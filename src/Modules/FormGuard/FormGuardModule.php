<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\FormGuard;

use Valolink\Plugin\Admin\PluginDetector;
use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Modules\Logging\EventLogger;
use Valolink\Plugin\Modules\Logging\LoggingModule;
use Valolink\Plugin\Modules\Security\SecurityModule;
use Valolink\Plugin\Settings;

/**
 * Spam check for the site's public forms: a form must carry a signed,
 * time-limited, single-use token that JavaScript fetches from an uncached REST
 * endpoint, and leave an off-screen decoy field empty. A token in cached HTML
 * would be replayable, which is how bots walked past WP Armour's static
 * honeypot field.
 *
 * A port of the standalone valolink-cf7-guard plugin (0.2.0, Pirkkala and
 * Lempäälä hautauspalvelu): same field names, same token format and signing,
 * same Flamingo spam log for Contact Form 7, so a site can swap the plugin for
 * the module. Beyond CF7 it covers WooCommerce's sign-in, lost-password,
 * comment / product review and registration forms, each behind its own toggle.
 * wp-login.php's sign-in is left to Security → Login timing guard when that is
 * on (it renders its token into the uncached login page).
 *
 * Every rejection goes to the Event Log as `spam.blocked` (integration, reason,
 * form, page; never the message), so "my message never arrived" can be checked
 * on sites that keep no spam folder.
 */
final class FormGuardModule implements Module
{
    public const MODULE_ID    = 'form_guard';
    public const SUBPAGE_SLUG = 'valolink-form-guard';
    public const SAVE_ACTION  = 'valolink_save_form_guard';
    public const REST_NS      = 'valolink/v1';
    public const REST_ROUTE   = '/form-guard/token';

    public const FIELD = '_vlg_token';
    public const DECOY = '_vlg_note';

    private const MIN_AGE = 2;              // seconds between token issue and submit
    private const MAX_AGE = DAY_IN_SECONDS;
    private const SKEW    = 60;             // tolerated clock skew for future timestamps

    /** One spam.blocked row per IP and integration per window, except CF7 whose every rejection matters. */
    private const LOG_THROTTLE_SECONDS = 300;

    /** Integration toggles, in settings-page order. */
    private const INTEGRATIONS = ['cf7', 'login', 'lostpassword', 'comments', 'registration'];

    /** Form selectors the script fills, per integration. */
    private const SELECTORS = [
        'cf7'          => ['form.wpcf7-form'],
        'login'        => ['form.woocommerce-form-login'],
        'lostpassword' => ['#lostpasswordform', 'form.woocommerce-ResetPassword'],
        'comments'     => ['#commentform', 'form.comment-form'],
        'registration' => ['#registerform', 'form.woocommerce-form-register'],
    ];

    private const CF7_STANDALONE_CLASS = 'Valolink_CF7_Guard';

    /** Reason of the CF7 rejection in this request, for the message shown. */
    private ?string $cf7_rejected = null;

    private bool $error_logged = false;

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        // Forms post through REST (CF7), wp-login.php, wp-comments-post.php and
        // front-end requests (WooCommerce), and the token comes over REST.
        return true;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);

        if (!$this->any_integration()) {
            return;
        }

        add_action('rest_api_init', [$this, 'register_route']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('login_enqueue_scripts', [$this, 'enqueue_login']);

        if ($this->cf7_active()) {
            add_filter('wpcf7_spam', [$this, 'check_cf7'], 9, 2);
            add_action('wpcf7_mail_sent', [$this, 'consume_posted']);
            add_filter('wpcf7_display_message', [$this, 'cf7_message'], 10, 2);
        }
        if ($this->is_on('login')) {
            add_filter('woocommerce_process_login_errors', [$this, 'check_woo_login'], 10, 1);
            if ($this->wp_login_covered_here()) {
                // Priority 30: after core's own credential check at 20, which
                // would otherwise replace an earlier WP_Error with its result.
                add_filter('authenticate', [$this, 'check_wp_login'], 30, 1);
            }
        }
        if ($this->is_on('lostpassword')) {
            add_action('lostpassword_post', [$this, 'check_lostpassword'], 10, 1);
        }
        if ($this->is_on('comments')) {
            add_filter('preprocess_comment', [$this, 'check_comment'], 1);
        }
        if ($this->is_on('registration')) {
            add_filter('registration_errors', [$this, 'check_wp_registration'], 10, 1);
            add_filter('woocommerce_process_registration_errors', [$this, 'check_woo_registration'], 10, 1);
        }
    }

    public function uninstall(): void
    {
        $this->settings->forget_module(self::MODULE_ID);
    }

    // -------------------------------------------------------------------------
    // Token
    // -------------------------------------------------------------------------

    public function register_route(): void
    {
        register_rest_route(self::REST_NS, self::REST_ROUTE, [
            'methods'             => 'GET',
            'callback'            => [$this, 'issue'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function issue(): \WP_REST_Response
    {
        $ts    = time();
        $nonce = bin2hex(random_bytes(8));
        $res   = new \WP_REST_Response([
            'token' => $ts . '.' . $nonce . '.' . self::sign($ts, $nonce),
        ]);
        $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $res->header('X-Robots-Tag', 'noindex');
        return $res;
    }

    /** Same signing as valolink-cf7-guard, so tokens are interchangeable during a swap. */
    private static function sign(int $ts, string $nonce): string
    {
        $data = get_current_blog_id() . '|' . $ts . '|' . $nonce;
        return substr(hash_hmac('sha256', $data, wp_salt('nonce')), 0, 32);
    }

    /** Null when the posted form passes, otherwise a short reason code. */
    private function posted_reason(): ?string
    {
        $decoy = isset($_POST[self::DECOY]) ? trim((string) wp_unslash($_POST[self::DECOY])) : '';
        if ($decoy !== '') {
            return 'decoy-filled';
        }
        $token = self::posted_token();
        if ($token === '') {
            return 'missing';
        }
        if (!preg_match('/^(\d{9,11})\.([0-9a-f]{16})\.([0-9a-f]{32})$/', $token, $m)) {
            return 'malformed';
        }
        [, $ts, $nonce, $sig] = $m;
        $ts = (int) $ts;
        if (!hash_equals(self::sign($ts, $nonce), $sig)) {
            return 'bad-signature';
        }
        $age = time() - $ts;
        if ($age < -self::SKEW) {
            return 'future';
        }
        if ($age < self::MIN_AGE) {
            return 'too-fast';
        }
        if ($age > self::MAX_AGE) {
            return 'expired';
        }
        if (get_transient(self::used_key($nonce))) {
            return 'reused';
        }
        return null;
    }

    private static function posted_token(): string
    {
        return isset($_POST[self::FIELD]) ? trim((string) wp_unslash($_POST[self::FIELD])) : '';
    }

    private static function used_key(string $nonce): string
    {
        return 'vlg_used_' . $nonce;
    }

    /**
     * Marks the posted token as used. CF7 calls this only after its mail went
     * out, so a submit that failed validation can be retried with the same
     * token; the other forms spend it as soon as it passes.
     */
    public function consume_posted(mixed $unused = null): void
    {
        if (preg_match('/^\d+\.([0-9a-f]{16})\./', self::posted_token(), $m)) {
            set_transient(self::used_key($m[1]), 1, self::MAX_AGE + HOUR_IN_SECONDS);
        }
    }

    /** Runs the check for a non-CF7 form; returns the reason it failed, or null. */
    private function check(string $integration, string $form): ?string
    {
        try {
            $reason = $this->posted_reason();
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] form guard: check failed (open): ' . $e->getMessage());
            return null;
        }
        if ($reason === null) {
            $this->consume_posted();
            return null;
        }
        $this->record($integration, $reason, $form);
        return $reason;
    }

    // -------------------------------------------------------------------------
    // Integrations
    // -------------------------------------------------------------------------

    public function check_cf7(mixed $spam, mixed $submission = null): mixed
    {
        if ($spam) {
            return $spam;
        }
        try {
            $reason = $this->posted_reason();
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] form guard: CF7 check failed (open): ' . $e->getMessage());
            return $spam;
        }
        if ($reason === null) {
            return false;
        }
        $this->cf7_rejected = $reason;
        if (is_object($submission) && method_exists($submission, 'add_spam_log')) {
            // Flamingo shows this as the spam reason where it is installed.
            $submission->add_spam_log([
                'agent'  => 'valolink-cf7-guard',
                'reason' => sprintf('Guard check failed: %s', $reason),
            ]);
        }
        $form_id = is_object($submission) && method_exists($submission, 'get_contact_form')
            ? (string) $submission->get_contact_form()->id()
            : '';
        $this->record('cf7', $reason, $form_id !== '' ? 'cf7:' . $form_id : 'cf7');
        return true;
    }

    /** Tell a rejected sender how to get through instead of CF7's generic error. */
    public function cf7_message(mixed $message, mixed $status): mixed
    {
        if ($status !== 'spam' || $this->cf7_rejected === null) {
            return $message;
        }
        $text = 'Viestin lähetys ei onnistunut. Päivitä sivu ja yritä uudelleen. Jos ongelma toistuu, ota meihin yhteyttä puhelimitse tai sähköpostitse.';
        return apply_filters('valolink_cf7_guard_message', $text, $this->cf7_rejected);
    }

    /** WooCommerce's sign-in form (My Account, checkout); runs before wp_signon(), so no password is checked. */
    public function check_woo_login(mixed $errors): mixed
    {
        if ($errors instanceof \WP_Error && $this->check('login', 'woocommerce-login') !== null) {
            $errors->add('valolink_form_guard', $this->message());
        }
        return $errors;
    }

    /** wp-login.php's sign-in, only when Security's login timing guard is off. */
    public function check_wp_login(mixed $user): mixed
    {
        if (!$this->is_interactive_post() || ($GLOBALS['pagenow'] ?? '') !== 'wp-login.php'
            || !isset($_POST['log'], $_POST['pwd'])) {
            return $user;
        }
        if ($this->check('login', 'wp-login') !== null) {
            return new \WP_Error('valolink_form_guard', $this->message());
        }
        return $user;
    }

    /** Both wp-login.php's and WooCommerce's lost-password forms end in core's retrieve_password(). */
    public function check_lostpassword(mixed $errors): void
    {
        if (!$errors instanceof \WP_Error || !$this->is_interactive_post() || !isset($_POST['user_login'])) {
            return;
        }
        $woo = isset($_POST['wc_reset_password']);
        if (!$woo && ($GLOBALS['pagenow'] ?? '') !== 'wp-login.php') {
            return; // an admin sending a reset link, or another plugin calling retrieve_password()
        }
        if ($this->check('lostpassword', $woo ? 'woocommerce-lostpassword' : 'wp-lostpassword') !== null) {
            $errors->add('valolink_form_guard', $this->message());
        }
    }

    /** Comments and WooCommerce product reviews posted through wp-comments-post.php. */
    public function check_comment(mixed $commentdata): mixed
    {
        if (!$this->is_interactive_post() || ($GLOBALS['pagenow'] ?? '') !== 'wp-comments-post.php') {
            return $commentdata; // admin replies (AJAX), REST, imports
        }
        $post_id = is_array($commentdata) ? (int) ($commentdata['comment_post_ID'] ?? 0) : 0;
        if ($this->check('comments', 'comment:' . $post_id) !== null) {
            wp_die(
                esc_html($this->message()),
                esc_html__('Comment not sent', 'valolink-plugin'),
                ['response' => 403, 'back_link' => true],
            );
        }
        return $commentdata;
    }

    public function check_wp_registration(mixed $errors): mixed
    {
        if ($errors instanceof \WP_Error && $this->is_interactive_post() && ($GLOBALS['pagenow'] ?? '') === 'wp-login.php'
            && $this->check('registration', 'wp-register') !== null) {
            $errors->add('valolink_form_guard', $this->message());
        }
        return $errors;
    }

    /**
     * WooCommerce's My Account registration form only: this filter runs in its
     * form handler, not in wc_create_new_customer(), so an account created at
     * checkout is never checked (the checkout form carries no token).
     */
    public function check_woo_registration(mixed $errors): mixed
    {
        if ($errors instanceof \WP_Error && $this->check('registration', 'woocommerce-register') !== null) {
            $errors->add('valolink_form_guard', $this->message());
        }
        return $errors;
    }

    private function is_interactive_post(): bool
    {
        if ((defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI) || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }
        return isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST';
    }

    private function message(): string
    {
        $text = 'Lomakkeen lähetys ei onnistunut, koska sivun turvatarkistus ei mennyt läpi. Päivitä sivu ja yritä uudelleen.';
        return (string) apply_filters('valolink_form_guard_message', $text);
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function record(string $integration, string $reason, string $form): void
    {
        try {
            $ip = EventLogger::client_ip();
            if ($integration !== 'cf7' && $ip !== null) {
                $key = 'vl_fg_' . md5($integration . '|' . $ip);
                if (get_transient($key)) {
                    return;
                }
                set_transient($key, 1, self::LOG_THROTTLE_SECONDS);
            }

            $referer = wp_get_raw_referer();
            $page    = $referer ? (string) wp_parse_url($referer, PHP_URL_PATH) : '';

            if ($this->settings->is_module_enabled(LoggingModule::MODULE_ID)) {
                EventLogger::log('spam.blocked', [
                    'integration' => $integration,
                    'reason'      => $reason,
                    'form'        => $form,
                    'page'        => $page,
                    '__message'   => sprintf('Spam blocked: %s (%s)', $integration, $reason),
                ]);
            } elseif (!$this->error_logged) {
                $this->error_logged = true;
                error_log(sprintf('[valolink-plugin] form guard blocked %s (%s) on %s from %s', $integration, $reason, $form, $ip ?? '?'));
            }
        } catch (\Throwable $e) {
            // Logging must never break the form response.
        }
    }

    // -------------------------------------------------------------------------
    // Script
    // -------------------------------------------------------------------------

    public function enqueue(): void
    {
        $this->enqueue_for(['cf7', 'login', 'lostpassword', 'comments', 'registration']);
    }

    public function enqueue_login(): void
    {
        $this->enqueue_for(['login', 'lostpassword', 'registration'], true);
    }

    /** @param list<string> $integrations */
    private function enqueue_for(array $integrations, bool $login_page = false): void
    {
        $selectors = [];
        foreach ($integrations as $id) {
            if ($id === 'cf7' ? $this->cf7_active() : $this->is_on($id)) {
                $selectors = [...$selectors, ...self::SELECTORS[$id]];
            }
        }
        if ($login_page && $this->is_on('login') && $this->wp_login_covered_here()) {
            $selectors[] = '#loginform';
        }
        if (!$selectors) {
            return;
        }

        $base = defined('VALOLINK_PLUGIN_URL') ? VALOLINK_PLUGIN_URL : plugin_dir_url(dirname(__DIR__, 3) . '/valolink-plugin.php');
        $file = dirname(__DIR__, 3) . '/assets/form-guard/form-guard.js';
        wp_enqueue_script(
            'valolink-form-guard',
            $base . 'assets/form-guard/form-guard.js',
            [],
            is_file($file) ? (string) filemtime($file) : null,
            ['in_footer' => true],
        );
        wp_add_inline_script('valolink-form-guard', 'var vlgConfig = ' . wp_json_encode([
            'url'          => rest_url(self::REST_NS . self::REST_ROUTE),
            'field'        => self::FIELD,
            'decoy'        => self::DECOY,
            'forms'        => implode(',', $selectors),
            'minAge'       => self::MIN_AGE,
            'refreshAfter' => (int) (self::MAX_AGE / 2),
        ]) . ';', 'before');
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    private function is_on(string $integration): bool
    {
        return (bool) $this->settings->get_module_setting(self::MODULE_ID, $integration, false);
    }

    private function any_integration(): bool
    {
        foreach (self::INTEGRATIONS as $id) {
            if ($this->is_on($id)) {
                return true;
            }
        }
        return false;
    }

    /** The CF7 check runs here unless the standalone valolink-cf7-guard plugin is still active. */
    private function cf7_active(): bool
    {
        return $this->is_on('cf7') && !class_exists(self::CF7_STANDALONE_CLASS);
    }

    /** wp-login.php's sign-in is Security's when its login timing guard is on. */
    private function wp_login_covered_here(): bool
    {
        return !($this->settings->is_module_enabled(SecurityModule::MODULE_ID)
            && $this->settings->get_module_setting(SecurityModule::MODULE_ID, 'login_timing_guard', false));
    }

    public function add_settings_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Form guard', 'valolink-plugin'),
            __('Form guard', 'valolink-plugin'),
            'manage_options',
            self::SUBPAGE_SLUG,
            [$this, 'render_settings_page'],
        );
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $rows = [
            'cf7'          => [__('Contact Form 7', 'valolink-plugin'), __('Every Contact Form 7 form. A rejected sender is told to reload the page; Flamingo, where installed, shows the reason in its spam folder.', 'valolink-plugin')],
            'login'        => [__('Sign-in', 'valolink-plugin'), __('WooCommerce\'s sign-in form (My Account, checkout). wp-login.php too, unless Security → Login timing guard is on, which then covers it.', 'valolink-plugin')],
            'lostpassword' => [__('Lost password', 'valolink-plugin'), __('The lost-password forms of wp-login.php and WooCommerce\'s My Account.', 'valolink-plugin')],
            'comments'     => [__('Comments and reviews', 'valolink-plugin'), __('Comments and WooCommerce product reviews sent from the page. Replies from wp-admin are not checked.', 'valolink-plugin')],
            'registration' => [__('Registration', 'valolink-plugin'), __('wp-login.php\'s registration and WooCommerce\'s My Account registration. An account created at checkout is not checked.', 'valolink-plugin')],
        ];
        $updated = isset($_GET['updated']) && $_GET['updated'] === '1';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Form guard', 'valolink-plugin'); ?></h1>
            <p><?php esc_html_e('Each checked form must carry a signed, single-use token that the page fetches with JavaScript from an uncached address, at least two seconds before sending, and leave an invisible decoy field empty. Rejections are written to the Event Log as spam.blocked with the reason. Visitors without JavaScript cannot send these forms.', 'valolink-plugin'); ?></p>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Form guard settings saved.', 'valolink-plugin'); ?></p></div>
            <?php endif; ?>

            <?php
            if ($this->is_on('cf7') && class_exists(self::CF7_STANDALONE_CLASS)) {
                echo '<div class="notice notice-warning"><p>' . esc_html__('The Valolink CF7 Guard plugin is still active, so it checks Contact Form 7 and this module leaves CF7 alone. Deactivate the plugin to hand CF7 over.', 'valolink-plugin') . '</p></div>';
            }
            PluginDetector::render_conflict_notice(
                PluginDetector::find_active_in(['honeypot/wp-armour.php']),
                __('Another form spam plugin is active:', 'valolink-plugin'),
            );
            if (!$this->settings->is_module_enabled(LoggingModule::MODULE_ID)) {
                echo '<div class="notice notice-info"><p>' . esc_html__('The Event Log module is off, so rejections only reach the PHP error log.', 'valolink-plugin') . '</p></div>';
            }
            ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::SAVE_ACTION); ?>
                <table class="form-table" role="presentation"><tbody>
                    <?php foreach ($rows as $key => [$label, $description]) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($label); ?></th>
                            <td><label>
                                <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked($this->is_on($key)); ?>>
                                <?php echo esc_html($description); ?>
                            </label></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <?php submit_button(__('Save Form Guard Settings', 'valolink-plugin')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::SAVE_ACTION);

        $new = [];
        foreach (self::INTEGRATIONS as $key) {
            $new[$key] = !empty($_POST[$key]);
        }
        $this->settings->set_module_settings(self::MODULE_ID, $new);

        wp_safe_redirect(add_query_arg(['page' => self::SUBPAGE_SLUG, 'updated' => '1'], admin_url('admin.php')));
        exit;
    }
}
