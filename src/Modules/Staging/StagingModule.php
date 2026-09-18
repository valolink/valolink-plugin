<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Staging;

use Valolink\Plugin\Admin\Field\PluginMultiCheckbox;
use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

final class StagingModule implements Module
{
    public const MODULE_ID          = 'staging';
    public const SUBPAGE_SLUG       = 'valolink-staging';
    public const NONCE_ACTION       = 'valolink_save_staging';
    public const SAVE_ACTION        = 'valolink_save_staging';
    public const REGEN_TOKEN_ACTION = 'valolink_regen_staging_token';
    public const REGEN_TOKEN_NONCE  = 'valolink_regen_staging_token';
    public const DECLARE_ACTION     = 'valolink_staging_declare_current';
    public const DECLARE_NONCE      = 'valolink_staging_declare_current';
    public const BYPASS_COOKIE_NAME = 'valolink_staging_preview';
    public const BYPASS_PARAM       = 'valolink_preview';
    public const BYPASS_COOKIE_TTL  = 86400;

    /** WooCommerce gateway IDs that are safe to keep active on staging. */
    private const SAFE_GATEWAYS = ['bacs', 'cheque', 'cod'];

    /**
     * Plugins pre-checked the first time the "disable plugins" list is rendered.
     * Only those actually installed appear — the list is intersected at render time.
     */
    private const SUGGESTED_DISABLED_PLUGINS = [
        // Live payment gateways
        'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php',
        'woocommerce-paypal-payments/woocommerce-paypal-payments.php',
        'klarna-checkout-for-woocommerce/klarna-checkout-for-woocommerce.php',
        // Email / CRM marketing
        'klaviyo/klaviyo.php',
        'mailchimp-for-woocommerce/mailchimp-for-woocommerce.php',
        'hubspot/hubspot.php',
        // Live chat
        'tidio-live-chat/tidio-live-chat.php',
        'crisp/crisp.php',
        // Push notifications
        'onesignal-free-web-push-notifications/onesignal.php',
        // Social commerce / ad pixels
        'facebook-for-woocommerce/facebook-for-woocommerce.php',
        'google-listings-and-ads/google-listings-and-ads.php',
    ];

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        // Settings page must be reachable even when staging isn't active.
        return true;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);
        add_action('admin_post_' . self::REGEN_TOKEN_ACTION, [$this, 'handle_regen_token']);
        add_action('admin_post_' . self::DECLARE_ACTION, [$this, 'handle_declare_current']);

        // Module on, production host not declared: no safety can apply, so say so
        // on every admin screen. No fallback to heuristics — declare or switch off.
        if (!StagingDetector::is_declared($this->raw_settings()) && !(bool) $this->setting('force_staging')) {
            add_action('admin_notices', [$this, 'render_undeclared_notice']);
        }

        if (!$this->is_effectively_staging()) {
            return;
        }

        // Bypass param runs before redirects so visitors can set the cookie first.
        add_action('init', [$this, 'process_bypass_param'], 1);
        add_action('admin_notices', [$this, 'render_admin_notice']);

        if ($this->is_enabled('block_indexing')) {
            add_filter('wp_robots',    [$this, 'filter_robots'], 1);
            add_action('send_headers', [$this, 'add_robots_header'], 1);
        }
        if ($this->is_enabled('intercept_mail')) {
            add_filter('wp_mail', [$this, 'intercept_mail'], 1);
        }
        if ($this->is_enabled('disable_live_gateways') && class_exists('WooCommerce')) {
            add_filter('woocommerce_available_payment_gateways', [$this, 'disable_live_gateways']);
        }
        if ($this->is_enabled('require_login')) {
            add_action('template_redirect', [$this, 'enforce_require_login'], 1);
            add_filter('rest_authentication_errors', [$this, 'block_unauthenticated_rest'], 99);
        }
        if ($this->is_enabled('coming_soon_enabled')) {
            add_action('template_redirect', [$this, 'enforce_coming_soon'], 2);
        }
        if ($this->is_enabled('block_auto_updates')) {
            add_filter('auto_update_plugin',      '__return_false');
            add_filter('auto_update_theme',       '__return_false');
            add_filter('auto_update_core_minor',  '__return_false');
            add_filter('auto_update_core_major',  '__return_false');
            add_filter('auto_update_translation', '__return_false');
        }
    }

    public function uninstall(): void
    {
        MuPluginInstaller::remove();
        $this->settings->forget_module(self::MODULE_ID);
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function add_settings_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Staging', 'valolink-plugin'),
            __('Staging', 'valolink-plugin'),
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

        $decision   = StagingDetector::decision($this->raw_settings());
        $forced     = (bool) $this->setting('force_staging');
        $declared   = StagingDetector::is_declared($this->raw_settings());
        $active     = $decision['staging'];
        $updated    = isset($_GET['updated']) && $_GET['updated'] === '1';
        $blocked    = isset($_GET['declare_blocked']) && $_GET['declare_blocked'] === '1';
        $token      = $this->ensure_bypass_token();
        $bypass_url = add_query_arg(self::BYPASS_PARAM, $token, home_url('/'));
        $home_looks_like_clone = StagingDetector::looks_like_staging($decision['home_host'])
            || StagingDetector::has_non_www_subdomain($decision['home_host']);

        $saved_disabled = $this->setting('disabled_plugins', null);
        $checked_plugins = $saved_disabled !== null
            ? (array) $saved_disabled
            : $this->default_disabled_plugins();

        $pages = get_pages(['post_status' => 'publish', 'sort_column' => 'post_title']);
        $coming_soon_page_id = (int) $this->setting('coming_soon_page_id');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Staging', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e('Staging settings saved.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <?php if ($blocked) : ?>
                <div class="notice notice-error"><p>
                    <?php printf(
                        /* translators: %s: current home host */
                        esc_html__('Refused: %s looks like a staging copy. Declare the production host on the production site. If this really is production, tick the confirmation box and try again.', 'valolink-plugin'),
                        '<code>' . esc_html($decision['home_host']) . '</code>'
                    ); ?>
                </p></div>
            <?php endif; ?>

            <?php $this->render_misconfig_warnings($decision, $home_looks_like_clone); ?>

            <div class="card" style="padding:12px 18px;max-width:none;display:flex;gap:24px;flex-wrap:wrap;align-items:center;">
                <div>
                    <strong><?php esc_html_e('This site', 'valolink-plugin'); ?>:</strong>
                    <code><?php echo esc_html($decision['home_host'] !== '' ? $decision['home_host'] : '—'); ?></code>
                </div>
                <div>
                    <strong><?php esc_html_e('Declared production', 'valolink-plugin'); ?>:</strong>
                    <?php echo $declared
                        ? '<code>' . esc_html($decision['declared_host'] !== '' ? $decision['declared_host'] : '(hash only)') . '</code>'
                        : '<span style="color:#b32d2e;">' . esc_html__('not declared', 'valolink-plugin') . '</span>'; ?>
                </div>
                <div>
                    <strong><?php esc_html_e('Forced', 'valolink-plugin'); ?>:</strong>
                    <?php echo $forced
                        ? '<span style="color:#b32d2e;">' . esc_html__('yes', 'valolink-plugin') . '</span>'
                        : '<span style="color:#646970;">' . esc_html__('no', 'valolink-plugin') . '</span>'; ?>
                </div>
                <div>
                    <strong><?php esc_html_e('Mode', 'valolink-plugin'); ?>:</strong>
                    <?php if ($active) : ?>
                        <span style="color:#b32d2e;font-weight:600;"><?php esc_html_e('STAGING', 'valolink-plugin'); ?></span>
                        <em>(<?php echo esc_html($decision['reason'] === StagingDetector::REASON_FORCED ? __('forced', 'valolink-plugin') : __('home host differs from the declared production host', 'valolink-plugin')); ?>)</em>
                    <?php elseif (!$declared) : ?>
                        <span style="color:#b32d2e;font-weight:600;"><?php esc_html_e('UNDECLARED — no safeties active', 'valolink-plugin'); ?></span>
                    <?php else : ?>
                        <span style="color:#118a4c;font-weight:600;"><?php esc_html_e('PRODUCTION', 'valolink-plugin'); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <h2><?php esc_html_e('Production site', 'valolink-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('Declare the production host once, on the production site. Every database copy whose home URL no longer matches it is treated as staging. The declaration is stored as a hash, so a search-replace during cloning cannot rewrite it.', 'valolink-plugin'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0 16px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::DECLARE_ACTION); ?>">
                <?php wp_nonce_field(self::DECLARE_NONCE); ?>
                <?php submit_button(
                    sprintf(
                        /* translators: %s: current home host */
                        __('Use current site URL (%s)', 'valolink-plugin'),
                        $decision['home_host'] !== '' ? $decision['home_host'] : '—'
                    ),
                    $home_looks_like_clone ? 'secondary' : 'primary',
                    'submit',
                    false
                ); ?>
                <?php if ($home_looks_like_clone) : ?>
                    <label style="margin-left:12px;">
                        <input type="checkbox" name="confirm_clone_host" value="1">
                        <?php esc_html_e('This host looks like a clone, but it really is production.', 'valolink-plugin'); ?>
                    </label>
                <?php endif; ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <h2><?php esc_html_e('Mode', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Force staging mode', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="force_staging" value="1" <?php checked($forced); ?>>
                                <?php esc_html_e('Treat this site as staging even if it is the declared production host.', 'valolink-plugin'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Useful for hiding a live site temporarily.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <h2><?php esc_html_e('Visibility', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row('block_indexing',
                        __('Block search indexing', 'valolink-plugin'),
                        __('Adds noindex/nofollow robots meta and X-Robots-Tag header.', 'valolink-plugin')); ?>
                    <?php $this->checkbox_row('require_login',
                        __('Require login for frontend', 'valolink-plugin'),
                        __('Non-logged-in visitors are redirected to wp-login.php. Also blocks unauthenticated REST API calls. Guests with a bypass cookie can still preview.', 'valolink-plugin')); ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('"Coming soon" redirect', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="coming_soon_enabled" value="1"
                                       <?php checked($this->is_enabled('coming_soon_enabled')); ?>>
                                <?php esc_html_e('Redirect frontend visitors to the page below (admin and login bypassed; logged-in admins can preview).', 'valolink-plugin'); ?>
                            </label>
                            <br><br>
                            <label>
                                <?php esc_html_e('Page', 'valolink-plugin'); ?>:
                                <select name="coming_soon_page_id">
                                    <option value="0">— <?php esc_html_e('Select a page', 'valolink-plugin'); ?> —</option>
                                    <?php foreach ($pages as $page) : ?>
                                        <option value="<?php echo esc_attr((string) $page->ID); ?>"
                                                <?php selected($coming_soon_page_id, $page->ID); ?>>
                                            <?php echo esc_html($page->post_title ?: ('#' . $page->ID)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <p class="description">
                                <?php esc_html_e('If "Require login" is also on, login takes precedence.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <h2><?php esc_html_e('Mail & payments', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row('intercept_mail',
                        __('Intercept outgoing mail', 'valolink-plugin'),
                        __('All outgoing wp_mail() is redirected to the site admin with a [STAGING] prefix; CC/BCC headers stripped.', 'valolink-plugin')); ?>
                    <tr>
                        <th scope="row">
                            <label for="valolink-intercept-mail-extra"><?php esc_html_e('Additional intercept recipient', 'valolink-plugin'); ?></label>
                        </th>
                        <td>
                            <input
                                id="valolink-intercept-mail-extra"
                                type="email"
                                name="intercept_mail_extra"
                                class="regular-text"
                                value="<?php echo esc_attr((string) $this->setting('intercept_mail_extra', '')); ?>"
                                placeholder="<?php esc_attr_e('customer@example.com', 'valolink-plugin'); ?>"
                            >
                            <p class="description">
                                <?php esc_html_e('Optional. Intercepted mail also goes to this address — e.g. the customer, so they can test flows that send email. Leave empty to send to the site admin only.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                    <?php $this->checkbox_row('disable_live_gateways',
                        __('Disable live WooCommerce payment gateways', 'valolink-plugin'),
                        __('Only safe offline gateways (BACS, Cheque, COD) remain active when WooCommerce is installed.', 'valolink-plugin')); ?>
                </tbody></table>

                <h2><?php esc_html_e('Production host', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row">
                            <label for="valolink-production-host"><?php esc_html_e('Declared production host', 'valolink-plugin'); ?></label>
                        </th>
                        <td>
                            <input
                                id="valolink-production-host"
                                type="text"
                                name="production_host"
                                class="regular-text code"
                                value="<?php echo esc_attr($decision['declared_host']); ?>"
                                placeholder="example.fi"
                            >
                            <p class="description">
                                <?php esc_html_e('Host only; scheme, port and a leading "www." are ignored. Leave empty to clear the declaration — the module then does nothing except remind you. The button above fills this in from the current site.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <h2><?php esc_html_e('Updates', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row('block_auto_updates',
                        __('Block automatic updates', 'valolink-plugin'),
                        __('Prevents WordPress from auto-updating core, plugins, themes, and translations while staging is active.', 'valolink-plugin')); ?>
                </tbody></table>

                <h2><?php esc_html_e('Plugins to disable', 'valolink-plugin'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Selected plugins will not load while staging is active. Implemented via mu-plugin so plugin activation state is preserved.', 'valolink-plugin'); ?>
                    <?php if (MuPluginInstaller::is_installed()) : ?>
                        <span style="color:#118a4c;">✓ <?php esc_html_e('mu-loader installed.', 'valolink-plugin'); ?></span>
                    <?php else : ?>
                        <span style="color:#b32d2e;">⚠ <?php esc_html_e('mu-loader NOT installed. Plugin disabling will not take effect.', 'valolink-plugin'); ?></span>
                    <?php endif; ?>
                </p>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable plugin disabling', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="disable_plugins_enabled" value="1"
                                       <?php checked($this->is_enabled('disable_plugins_enabled')); ?>>
                                <?php esc_html_e('Disable the selected plugins when staging is active.', 'valolink-plugin'); ?>
                            </label>
                        </td>
                    </tr>
                </tbody></table>

                <?php
                PluginMultiCheckbox::render(
                    'disabled_plugins[]',
                    $checked_plugins,
                    __('Suggested defaults are pre-checked based on what is installed. Only active plugins appear.', 'valolink-plugin'),
                );
                ?>

                <?php submit_button(__('Save Staging Settings', 'valolink-plugin')); ?>
            </form>

            <h2><?php esc_html_e('Guest bypass link', 'valolink-plugin'); ?></h2>
            <p class="description"><?php esc_html_e('Share this URL to let guests browse the staging site without logging in. The link sets a 24-hour cookie on first visit. Regenerating creates a new token and invalidates the old one.', 'valolink-plugin'); ?></p>

            <div style="display:flex;gap:8px;align-items:center;margin-top:8px;max-width:640px;">
                <input
                    id="valolink-bypass-url"
                    type="text"
                    class="regular-text"
                    value="<?php echo esc_attr($bypass_url); ?>"
                    readonly
                    style="flex:1;"
                >
                <button
                    type="button"
                    class="button"
                    onclick="(function(btn){
                        var input = document.getElementById('valolink-bypass-url');
                        navigator.clipboard.writeText(input.value).then(function(){
                            var orig = btn.textContent;
                            btn.textContent = '<?php echo esc_js(__('Copied!', 'valolink-plugin')); ?>';
                            setTimeout(function(){ btn.textContent = orig; }, 2000);
                        });
                    })(this)"
                ><?php esc_html_e('Copy', 'valolink-plugin'); ?></button>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::REGEN_TOKEN_ACTION); ?>">
                <?php wp_nonce_field(self::REGEN_TOKEN_NONCE); ?>
                <?php submit_button(__('Regenerate bypass token', 'valolink-plugin'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private function checkbox_row(string $key, string $label, string $description): void
    {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1"
                           <?php checked($this->is_enabled($key)); ?>>
                    <?php echo esc_html($description); ?>
                </label>
            </td>
        </tr>
        <?php
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION);

        $bool = static fn(string $name): bool => !empty($_POST[$name]);

        $declaration = StagingDetector::declaration_for(
            sanitize_text_field((string) wp_unslash($_POST['production_host'] ?? ''))
        );

        $this->settings->set_module_settings(self::MODULE_ID, $declaration + [
            'force_staging'           => $bool('force_staging'),
            'block_indexing'          => $bool('block_indexing'),
            'intercept_mail'          => $bool('intercept_mail'),
            'intercept_mail_extra'    => sanitize_email((string) wp_unslash($_POST['intercept_mail_extra'] ?? '')),
            'disable_live_gateways'   => $bool('disable_live_gateways'),
            'require_login'           => $bool('require_login'),
            'coming_soon_enabled'     => $bool('coming_soon_enabled'),
            'coming_soon_page_id'     => max(0, (int) ($_POST['coming_soon_page_id'] ?? 0)),
            'disable_plugins_enabled' => $bool('disable_plugins_enabled'),
            'disabled_plugins'        => PluginMultiCheckbox::sanitize($_POST['disabled_plugins'] ?? null),
            'block_auto_updates'      => $bool('block_auto_updates'),
        ]);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    public function handle_regen_token(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::REGEN_TOKEN_NONCE);

        $this->settings->set_module_settings(self::MODULE_ID, ['bypass_token' => bin2hex(random_bytes(6))]);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    /**
     * "Use current site URL": declares the host of the `home` option as
     * production. Refused when that host looks like a clone unless the
     * confirmation box was ticked — declaring a staging copy as production
     * would switch every safety off on it.
     */
    public function handle_declare_current(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::DECLARE_NONCE);

        $home = StagingDetector::home_hostname();
        $looks_like_clone = StagingDetector::looks_like_staging($home) || StagingDetector::has_non_www_subdomain($home);
        if ($home === '' || ($looks_like_clone && empty($_POST['confirm_clone_host']))) {
            wp_safe_redirect(add_query_arg(
                ['page' => self::SUBPAGE_SLUG, 'declare_blocked' => '1'],
                admin_url('admin.php'),
            ));
            exit;
        }

        $this->settings->set_module_settings(self::MODULE_ID, StagingDetector::declaration_for($home));

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // Bypass cookie / param
    // -------------------------------------------------------------------------

    public function process_bypass_param(): void
    {
        if (!isset($_GET[self::BYPASS_PARAM])) {
            return;
        }

        $token = (string) $this->setting('bypass_token', '');
        if ($token === '') {
            return;
        }

        $provided = sanitize_text_field(wp_unslash($_GET[self::BYPASS_PARAM]));
        if (!hash_equals($token, $provided)) {
            return;
        }

        setcookie(
            self::BYPASS_COOKIE_NAME,
            $token,
            [
                'expires'  => time() + self::BYPASS_COOKIE_TTL,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        );

        wp_safe_redirect(remove_query_arg(self::BYPASS_PARAM));
        exit;
    }

    // -------------------------------------------------------------------------
    // Feature implementations
    // -------------------------------------------------------------------------

    /** @param array<string, bool|string> $robots */
    public function filter_robots(array $robots): array
    {
        $robots['noindex']  = true;
        $robots['nofollow'] = true;
        unset($robots['max-image-preview']);
        return $robots;
    }

    public function add_robots_header(): void
    {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow');
        }
    }

    /** @param array<string, mixed> $atts */
    public function intercept_mail(array $atts): array
    {
        $recipients = $this->intercept_recipients();
        if ($recipients === []) {
            return $atts;
        }

        $original_to = is_array($atts['to'] ?? null)
            ? implode(', ', $atts['to'])
            : (string) ($atts['to'] ?? '');

        $atts['to']      = $recipients;
        $atts['subject'] = '[STAGING] ' . ($atts['subject'] ?? '');

        $headers = $atts['headers'] ?? [];
        if (is_string($headers)) {
            $headers = array_filter(array_map('trim', explode("\n", $headers)));
        }
        $atts['headers'] = array_values(array_filter(
            (array) $headers,
            static fn(mixed $h): bool => is_string($h) && !preg_match('/^(CC|BCC)\s*:/i', $h),
        ));

        $atts['message'] = sprintf(
            "--- STAGING INTERCEPT ---\nOriginal recipient(s): %s\n\n",
            $original_to,
        ) . ($atts['message'] ?? '');

        return $atts;
    }

    /**
     * Site admin plus the optional extra recipient, deduplicated.
     *
     * @return list<string>
     */
    private function intercept_recipients(): array
    {
        $candidates = [
            (string) get_option('admin_email', ''),
            (string) $this->setting('intercept_mail_extra', ''),
        ];

        $valid = array_filter(array_map('trim', $candidates), 'is_email');

        return array_values(array_unique(array_map('strtolower', $valid)));
    }

    /**
     * @param  array<string, \WC_Payment_Gateway> $gateways
     * @return array<string, \WC_Payment_Gateway>
     */
    public function disable_live_gateways(array $gateways): array
    {
        return array_filter(
            $gateways,
            static fn(string $id): bool => in_array($id, self::SAFE_GATEWAYS, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function enforce_require_login(): void
    {
        if (is_user_logged_in() || self::is_bypassable_request() || $this->has_valid_bypass_cookie()) {
            return;
        }

        $current_url = home_url(add_query_arg(null, null));
        wp_safe_redirect(wp_login_url($current_url));
        exit;
    }

    public function block_unauthenticated_rest(mixed $errors): mixed
    {
        if (is_wp_error($errors) || $errors === true) {
            return $errors;
        }
        if (is_user_logged_in()) {
            return $errors;
        }
        return new \WP_Error(
            'valolink_staging_login_required',
            __('Staging mode: login required.', 'valolink-plugin'),
            ['status' => 401],
        );
    }

    public function enforce_coming_soon(): void
    {
        // Logged-in admins can preview the real site.
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return;
        }
        if (self::is_bypassable_request() || $this->has_valid_bypass_cookie()) {
            return;
        }

        $page_id = (int) $this->setting('coming_soon_page_id');
        if ($page_id <= 0 || is_page($page_id)) {
            return;
        }

        $page_url = get_permalink($page_id);
        if (!$page_url) {
            return;
        }

        wp_safe_redirect($page_url);
        exit;
    }

    public function render_admin_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $active = [];
        if ($this->is_enabled('block_indexing'))        $active[] = __('indexing blocked', 'valolink-plugin');
        if ($this->is_enabled('intercept_mail')) {
            $extra = (string) $this->setting('intercept_mail_extra', '');
            $active[] = is_email($extra)
                /* translators: %s: additional intercept recipient address. */
                ? sprintf(__('mail intercepted (also to %s)', 'valolink-plugin'), $extra)
                : __('mail intercepted', 'valolink-plugin');
        }
        if ($this->is_enabled('disable_live_gateways') && class_exists('WooCommerce'))
                                                        $active[] = __('Woo live gateways off', 'valolink-plugin');
        if ($this->is_enabled('require_login'))         $active[] = __('login required', 'valolink-plugin');
        if ($this->is_enabled('coming_soon_enabled'))   $active[] = __('coming-soon redirect', 'valolink-plugin');
        if ($this->is_enabled('block_auto_updates'))    $active[] = __('auto-updates blocked', 'valolink-plugin');
        if ($this->is_enabled('disable_plugins_enabled')) {
            $count = count((array) $this->setting('disabled_plugins', []));
            if ($count > 0) {
                $active[] = sprintf(_n('%d plugin disabled', '%d plugins disabled', $count, 'valolink-plugin'), $count);
            }
        }
        ?>
        $decision = StagingDetector::decision($this->raw_settings());
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Staging mode active', 'valolink-plugin'); ?></strong>
                <?php if ($decision['reason'] === StagingDetector::REASON_FORCED) : ?>
                    <em>(<?php esc_html_e('forced', 'valolink-plugin'); ?>)</em>
                <?php else : ?>
                    <em>(<?php printf(
                        /* translators: 1: this site's home host, 2: declared production host */
                        esc_html__('this site is %1$s, production is declared as %2$s', 'valolink-plugin'),
                        '<code>' . esc_html($decision['home_host']) . '</code>',
                        '<code>' . esc_html($decision['declared_host'] !== '' ? $decision['declared_host'] : '…') . '</code>'
                    ); ?>)</em>
                <?php endif; ?>
                <?php if ($active) : ?>
                    — <?php echo esc_html(implode(', ', $active)); ?>
                <?php endif; ?>
                · <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SUBPAGE_SLUG)); ?>"><?php esc_html_e('configure', 'valolink-plugin'); ?></a>
                <?php if ($decision['reason'] === StagingDetector::REASON_MISMATCH) : ?>
                    · <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SUBPAGE_SLUG)); ?>"><?php esc_html_e('this is production — re-declare', 'valolink-plugin'); ?></a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /** Module on, nothing declared: every admin screen says so until someone declares or switches the module off. */
    public function render_undeclared_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('Valolink Staging: production host not declared.', 'valolink-plugin'); ?></strong>
                <?php esc_html_e('The module is on, but until the production host is declared it cannot tell a clone from production, so none of its safeties are active here.', 'valolink-plugin'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SUBPAGE_SLUG)); ?>"><?php esc_html_e('Declare it', 'valolink-plugin'); ?></a>
            </p>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array{staging: bool, reason: string, home_host: string, declared_host: string} $decision */
    private function render_misconfig_warnings(array $decision, bool $home_looks_like_clone): void
    {
        $warnings = [];

        if ($decision['reason'] === StagingDetector::REASON_UNDECLARED) {
            $warnings[] = __('No production host is declared. The module does nothing until it is — declare it on the production site, then clone.', 'valolink-plugin');
        }
        if ($decision['reason'] === StagingDetector::REASON_MATCH && $home_looks_like_clone) {
            $warnings[] = __('This site is the declared production host, but its hostname looks like a staging copy. If a clone was declared as production by mistake, clear the declaration here and declare it on the real production site.', 'valolink-plugin');
        }
        if ($decision['reason'] === StagingDetector::REASON_MISMATCH && !$home_looks_like_clone) {
            $warnings[] = __('Staging mode is active because the home URL no longer matches the declared production host, yet this hostname does not look like a clone. If the production domain changed, re-declare it with the button above.', 'valolink-plugin');
        }

        if ($this->is_enabled('disable_live_gateways') && !class_exists('WooCommerce')) {
            $warnings[] = __('Disable live WooCommerce gateways is on, but WooCommerce is not active here. Nothing to filter.', 'valolink-plugin');
        }
        $extra_recipient = trim((string) $this->setting('intercept_mail_extra', ''));
        if ($extra_recipient !== '' && !is_email($extra_recipient)) {
            $warnings[] = __('The additional intercept recipient is not a valid email address — it will be ignored.', 'valolink-plugin');
        } elseif ($extra_recipient !== '' && !$this->is_enabled('intercept_mail')) {
            $warnings[] = __('An additional intercept recipient is set, but mail interception is off — nothing is redirected anywhere.', 'valolink-plugin');
        }
        if ($this->is_enabled('coming_soon_enabled') && (int) $this->setting('coming_soon_page_id') <= 0) {
            $warnings[] = __('Coming-soon redirect is on, but no destination page is selected — visitors will not be redirected anywhere.', 'valolink-plugin');
        }
        if ((bool) $this->setting('force_staging') && $decision['reason'] === StagingDetector::REASON_FORCED
            && StagingDetector::is_declared($this->raw_settings())
            && hash_equals(StagingDetector::host_hash($decision['home_host']), (string) ($this->raw_settings()[StagingDetector::KEY_HOST_HASH] ?? ''))) {
            $warnings[] = __('Staging is forced on the declared production site. Visitors get the staging behaviour until this is switched off.', 'valolink-plugin');
        }

        foreach ($warnings as $msg) {
            ?>
            <div class="notice notice-warning inline" style="margin:12px 0;">
                <p><?php echo esc_html($msg); ?></p>
            </div>
            <?php
        }
    }

    private function is_effectively_staging(): bool
    {
        static $result = null;
        if ($result !== null) {
            return $result;
        }

        // One decision, shared with the mu-loader: force flag, or the home host
        // no longer hashing to the declared production host. No heuristics.
        return $result = StagingDetector::is_staging_with($this->raw_settings());
    }

    /** @return array<string, mixed> The stored settings array, without defaults — what the loader reads too. */
    private function raw_settings(): array
    {
        $raw = $this->settings->all()['modules'][self::MODULE_ID]['settings'] ?? [];
        return is_array($raw) ? $raw : [];
    }

    /** Admin / cron / CLI / REST / login screen always bypass redirect-based features. */
    private static function is_bypassable_request(): bool
    {
        if (is_admin()) return true;
        if (defined('DOING_AJAX')   && DOING_AJAX)   return true;
        if (defined('DOING_CRON')   && DOING_CRON)   return true;
        if (defined('REST_REQUEST') && REST_REQUEST) return true;
        if (defined('WP_CLI')       && WP_CLI)       return true;

        global $pagenow;
        if (in_array($pagenow ?? '', ['wp-login.php', 'wp-register.php'], true)) return true;

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($uri !== '' && str_contains($uri, '/wp-cron.php')) return true;

        return false;
    }

    private function has_valid_bypass_cookie(): bool
    {
        $token = (string) $this->setting('bypass_token', '');
        if ($token === '') {
            return false;
        }
        $cookie = $_COOKIE[self::BYPASS_COOKIE_NAME] ?? '';
        return is_string($cookie) && hash_equals($token, $cookie);
    }

    private function ensure_bypass_token(): string
    {
        $token = (string) $this->setting('bypass_token', '');
        if ($token !== '') {
            return $token;
        }
        $token = bin2hex(random_bytes(6));
        $this->settings->set_module_settings(self::MODULE_ID, ['bypass_token' => $token]);
        return $token;
    }

    /** Intersect the curated default list with actually-installed plugins. */
    private function default_disabled_plugins(): array
    {
        $active = (array) get_option('active_plugins', []);
        return array_values(array_intersect($active, self::SUGGESTED_DISABLED_PLUGINS));
    }

    private function is_enabled(string $key): bool
    {
        return (bool) $this->setting($key);
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings->get_module_setting(self::MODULE_ID, $key, null);
        if ($value === null) {
            return self::defaults()[$key] ?? $default;
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'force_staging'           => false,
            'production_host'         => '',
            'production_host_hash'    => '',
            'block_indexing'          => true,
            'intercept_mail'          => true,
            'intercept_mail_extra'    => '',
            'disable_live_gateways'   => true,
            'require_login'           => false,
            'coming_soon_enabled'     => false,
            'coming_soon_page_id'     => 0,
            'disable_plugins_enabled' => false,
            'disabled_plugins'        => [],
            'block_auto_updates'      => true,
        ];
    }
}
