<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Umami;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Modules\Staging\StagingDetector;
use Valolink\Plugin\Settings;

/**
 * Umami analytics: our Umami tracker on the front end for the website ID set
 * under Valolink → Umami, plus events (contact clicks, downloads, outbound
 * links, form successes, 404s, searches, CSS-selector rules) and, on shops,
 * WooCommerce events with server-side purchases (WooPurchases).
 *
 * Who is counted: wp-admin and users who can edit content (our own and the
 * customer's editors) are left out; logged-in shop customers are visitors like
 * any other. With "test mode" on, editors get the tracker and the events, but
 * every send is cancelled and written to the browser console instead.
 *
 * The script comes from cdn.valolink.fi, an alias of umami.valolink.fi whose
 * name ad blockers do not list. A staging clone keeps the production website
 * ID, so nothing is printed while the staging decision says staging — the
 * clone would otherwise report its visits as production's. data-domains
 * covers what that check cannot see: a clone serving production's cached HTML,
 * tracker and all, is not counted because its hostname is not on the list.
 *
 * The event code started as a port of WebDig.DEV Analytics' tracker.js
 * (João Costa, GPL-2.0-or-later, wordpress.org/plugins/webdig-dev-analytics).
 * Its `data-auto-pageview="false"` is not supported by the tracker our server
 * sends, so here the tracker counts its own pageviews.
 */
final class UmamiModule implements Module
{
    public const MODULE_ID    = 'umami';
    public const SUBPAGE_SLUG = 'valolink-umami';
    public const NONCE_ACTION = 'valolink_save_umami';
    public const SAVE_ACTION  = 'valolink_save_umami';

    public const SCRIPT_URL = 'https://cdn.valolink.fi/script.js';
    /** Server-side sends (purchases) need no ad-blocker-proof name. */
    public const API_URL = 'https://umami.valolink.fi/api/send';

    public const SCRIPT_HANDLE = 'valolink-umami';
    public const WOO_HANDLE    = 'valolink-umami-woo';
    /** Window function the tracker calls before every send (consent gate, test mode). */
    public const BEFORE_SEND = 'valolinkUmamiBeforeSend';
    /** First-party cookie mirroring statistics consent, read by server-side purchases. */
    public const CONSENT_COOKIE = 'valolink_umami_consent';

    public const CONSENT_NONE = 'none';
    public const CONSENT_AUTO = 'auto';

    /**
     * Regexes, as WP Rocket's delay exclusions are matched against each whole
     * script tag: the tracker, our event scripts, and the inline config (which
     * the event script needs at once).
     */
    private const ROCKET_EXCLUSIONS = ['cdn\\.valolink\\.fi/script\\.js', '/assets/umami/', 'valolinkUmamiConfig'];

    private const UUID_PATTERN  = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const EVENT_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,49}$/';

    private const DEFAULT_DOWNLOAD_EXTENSIONS = 'pdf, doc, docx, xls, xlsx, ppt, pptx, odt, ods, csv, rtf, txt, zip';

    /** Event toggles: setting key => on by default. */
    private const EVENT_TOGGLES = [
        'ev_contact'   => true,
        'ev_downloads' => true,
        'ev_outbound'  => true,
        'ev_forms'     => true,
        'ev_not_found' => true,
        'ev_search'    => false,
        'woo_browser'  => true,
        'woo_purchase' => true,
    ];

    /** Per-request memo of mode(). */
    private ?string $mode = null;

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        // Every context: WooCommerce orders are created over REST (block
        // checkout) and paid in payment-gateway callbacks, cron or wp-admin;
        // Gravity Forms may confirm over admin-ajax. register() only adds hooks.
        return true;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);

        add_action('wp_head', [$this, 'print_tracker'], 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_events']);
        // Delayed until the first click or scroll, a visitor who leaves without
        // touching the page would never be counted.
        add_filter('rocket_delay_js_exclusions', [$this, 'exclude_from_rocket_delay']);

        if ($this->event_enabled('ev_forms')) {
            add_filter('gform_confirmation', [$this, 'mark_gravity_confirmation'], 10, 2);
        }
        if ($this->event_enabled('woo_purchase') && $this->website_id() !== '') {
            (new WooPurchases($this))->register();
        }
    }

    public function uninstall(): void
    {
        $this->settings->forget_module(self::MODULE_ID);
        WooPurchases::delete_order_meta();
    }

    // -------------------------------------------------------------------------
    // Who gets what
    // -------------------------------------------------------------------------

    /**
     * 'track' for visitors, 'debug' for editors in test mode, 'off' otherwise.
     * Test mode works on staging too: nothing is sent from it.
     */
    public function mode(): string
    {
        if ($this->mode !== null) {
            return $this->mode;
        }
        if ($this->website_id() === '') {
            return $this->mode = 'off';
        }
        if (current_user_can('edit_posts')) {
            return $this->mode = $this->test_mode() ? 'debug' : 'off';
        }

        return $this->mode = StagingDetector::is_staging() ? 'off' : 'track';
    }

    /** Is the current request's visitor someone whose purchases count? */
    public function counts_visitor(): bool
    {
        return $this->website_id() !== '' && !current_user_can('edit_posts') && !StagingDetector::is_staging();
    }

    // -------------------------------------------------------------------------
    // Front end
    // -------------------------------------------------------------------------

    public function print_tracker(): void
    {
        try {
            // With consent required the event script loads the tracker once
            // statistics consent is given.
            if ($this->mode() === 'off' || $this->consent_mode() === self::CONSENT_AUTO) {
                return;
            }
            wp_print_script_tag(['defer' => true, 'src' => self::SCRIPT_URL] + $this->tracker_attributes());
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] umami: ' . $e->getMessage());
        }
    }

    /** @return array<string, string> data-* attributes of the tracker tag. */
    private function tracker_attributes(): array
    {
        $attributes = ['data-website-id' => $this->website_id()];
        // In test mode the page may be a staging host; everything is cancelled
        // in before-send anyway.
        if ($this->mode() === 'track') {
            // Umami compares the exact hostname; the normalised host has no www.
            $host = StagingDetector::home_hostname();
            if ($host !== '') {
                $attributes['data-domains'] = $host . ',www.' . $host;
            }
        }
        if ($this->exclude_search()) {
            $attributes['data-exclude-search'] = 'true';
        }
        if ($this->needs_events_script()) {
            $attributes['data-before-send'] = self::BEFORE_SEND;
        }

        return $attributes;
    }

    private function needs_events_script(): bool
    {
        if ($this->mode() === 'off') {
            return false;
        }
        if ($this->mode() === 'debug' || $this->consent_mode() === self::CONSENT_AUTO || $this->rules() !== []) {
            return true;
        }
        foreach (['ev_contact', 'ev_downloads', 'ev_outbound', 'ev_forms', 'ev_not_found', 'ev_search'] as $key) {
            if ($this->event_enabled($key)) {
                return true;
            }
        }

        return $this->woo_browser_events();
    }

    private function woo_browser_events(): bool
    {
        return $this->event_enabled('woo_browser') && function_exists('WC');
    }

    public function enqueue_events(): void
    {
        try {
            if (!$this->needs_events_script()) {
                return;
            }
            $version = defined('VALOLINK_PLUGIN_VERSION') ? VALOLINK_PLUGIN_VERSION : '0';
            $url     = defined('VALOLINK_PLUGIN_URL') ? VALOLINK_PLUGIN_URL : plugin_dir_url(dirname(__DIR__, 3) . '/valolink-plugin.php');
            $args    = ['strategy' => 'defer', 'in_footer' => false];

            wp_enqueue_script(self::SCRIPT_HANDLE, $url . 'assets/umami/events.js', [], $version, $args);
            wp_add_inline_script(self::SCRIPT_HANDLE, 'window.valolinkUmamiConfig = ' . wp_json_encode($this->script_config()) . ';', 'before');

            if ($this->woo_browser_events()) {
                wp_enqueue_script(self::WOO_HANDLE, $url . 'assets/umami/woo.js', [self::SCRIPT_HANDLE], $version, $args);
            }
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] umami: ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function script_config(): array
    {
        $page = [];
        if (is_404() && $this->event_enabled('ev_not_found')) {
            $page['not_found'] = true;
        }
        if (is_search() && $this->event_enabled('ev_search')) {
            global $wp_query;
            $page['search'] = [
                'term'    => mb_substr(get_search_query(false), 0, 200),
                'results' => $wp_query instanceof \WP_Query ? (int) $wp_query->found_posts : 0,
            ];
        }

        $config = [
            'debug'   => $this->mode() === 'debug',
            'consent' => $this->consent_mode(),
            'cookie'  => self::CONSENT_COOKIE,
            'events'  => [
                'contact'   => $this->event_enabled('ev_contact'),
                'downloads' => $this->event_enabled('ev_downloads') ? $this->download_extensions() : [],
                'outbound'  => $this->event_enabled('ev_outbound'),
                'forms'     => $this->event_enabled('ev_forms'),
            ],
            'rules'   => $this->rules(),
            'page'    => (object) $page,
        ];
        if ($this->consent_mode() === self::CONSENT_AUTO) {
            $config['tracker'] = ['src' => self::SCRIPT_URL, 'attributes' => $this->tracker_attributes()];
        }
        if ($this->woo_browser_events()) {
            $config['woo'] = (object) $this->woo_page_context();
        }

        return $config;
    }

    /** @return array<string, mixed> What the WooCommerce page itself says (view_item, view_cart, begin_checkout). */
    private function woo_page_context(): array
    {
        $context = [];
        if (function_exists('is_product') && is_product()) {
            $product = function_exists('wc_get_product') ? wc_get_product(get_queried_object_id()) : null;
            if ($product instanceof \WC_Product) {
                $context['product'] = ['product_id' => (string) $product->get_id(), 'name' => $product->get_name()];
            }
        }
        if (function_exists('is_cart') && is_cart()) {
            $context['cart'] = true;
        }
        if (function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('order-pay')) {
            $context['checkout'] = true;
        }

        return $context;
    }

    /**
     * Gravity Forms shows a text confirmation in place of the form, after a
     * reload or over AJAX; a hidden marker in it tells the event script which
     * form succeeded. Redirect confirmations are a pageview of their own.
     *
     * @param mixed $confirmation
     * @param mixed $form
     * @return mixed
     */
    public function mark_gravity_confirmation($confirmation, $form)
    {
        if (!is_string($confirmation) || !is_array($form) || $this->mode() === 'off') {
            return $confirmation;
        }

        return $confirmation . sprintf(
            '<span hidden data-valolink-umami-form="%s" data-valolink-umami-form-title="%s"></span>',
            esc_attr((string) ($form['id'] ?? '')),
            esc_attr((string) ($form['title'] ?? '')),
        );
    }

    /** @param mixed $exclusions */
    public function exclude_from_rocket_delay($exclusions): array
    {
        return array_merge(is_array($exclusions) ? $exclusions : [], self::ROCKET_EXCLUSIONS);
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function add_settings_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Umami', 'valolink-plugin'),
            __('Umami', 'valolink-plugin'),
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
        $updated = isset($_GET['updated']) && $_GET['updated'] === '1';
        $invalid = isset($_GET['invalid']) && $_GET['invalid'] === '1';
        $skipped = isset($_GET['skipped']) ? absint($_GET['skipped']) : 0;
        $toggle  = function (string $key, string $label, string $help = ''): void {
            ?>
            <label style="display:block;margin-bottom:.4em;">
                <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked($this->event_enabled($key)); ?>>
                <?php echo esc_html($label); ?>
            </label>
            <?php if ($help !== '') : ?>
                <p class="description" style="max-width:700px;margin:0 0 .8em 1.8em;"><?php echo esc_html($help); ?></p>
            <?php endif;
        };
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Umami', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e('Umami settings saved.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>
            <?php if ($skipped > 0) : ?>
                <div class="notice notice-warning is-dismissible"><p>
                    <?php
                    printf(
                        /* translators: %d: number of rule lines */
                        esc_html(_n('%d event rule line was skipped: write it as "selector | event_name", the name in lower case letters, digits, _ or -.', '%d event rule lines were skipped: write them as "selector | event_name", the name in lower case letters, digits, _ or -.', $skipped, 'valolink-plugin')),
                        $skipped,
                    );
                    ?>
                </p></div>
            <?php endif; ?>
            <?php if ($invalid) : ?>
                <div class="notice notice-error is-dismissible"><p>
                    <?php esc_html_e('That is not a website ID. Copy it from the website\'s settings in Umami: it looks like ab226a42-4040-4181-8b89-f256b4ea0d2a.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <p class="description" style="max-width:780px;">
                <?php
                printf(
                    /* translators: %s: tracker script URL */
                    esc_html__('Adds the Umami tracker (%s) to every front-end page except to users who can edit content; wp-admin is not tracked, logged-in shop customers are. Only this site\'s own hostname is counted, and nothing is added while the site counts as staging, so a clone does not report its visits as the production site\'s.', 'valolink-plugin'),
                    '<code>' . esc_html(self::SCRIPT_URL) . '</code>',
                );
                ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row">
                            <label for="valolink-umami-website-id"><?php esc_html_e('Website ID', 'valolink-plugin'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="valolink-umami-website-id" name="website_id" class="regular-text code"
                                   value="<?php echo esc_attr($this->website_id()); ?>"
                                   placeholder="ab226a42-4040-4181-8b89-f256b4ea0d2a">
                            <p class="description">
                                <?php esc_html_e('From the website\'s settings in Umami. Leave empty to add no tracker.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Leave out query strings', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="exclude_search" value="1"
                                       <?php checked($this->exclude_search()); ?>>
                                <?php esc_html_e('Record page addresses without the part after "?".', 'valolink-plugin'); ?>
                            </label>
                            <p class="description" style="max-width:700px;">
                                <?php esc_html_e('Keeps ad click IDs (fbclid, gclid) and things like order keys out of the page list, so one page shows as one row. Umami reads campaigns from the same query string, so with this on UTM campaigns and ad clicks are no longer reported. Off by default.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <h2><?php esc_html_e('Events', 'valolink-plugin'); ?></h2>
                <p class="description" style="max-width:780px;">
                    <?php esc_html_e('Event names are the same on every site, so goals and funnels can be copied between sites in Umami; the details are in the event\'s properties. Form field values are never sent.', 'valolink-plugin'); ?>
                </p>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Automatic events', 'valolink-plugin'); ?></th>
                        <td>
                            <?php
                            $toggle('ev_contact', __('Phone, email and WhatsApp links (phone_click, email_click, whatsapp_click)', 'valolink-plugin'));
                            $toggle('ev_downloads', __('File downloads (download)', 'valolink-plugin'));
                            ?>
                            <p style="margin:0 0 .8em 1.8em;">
                                <label for="valolink-umami-exts"><?php esc_html_e('File types:', 'valolink-plugin'); ?></label>
                                <input type="text" id="valolink-umami-exts" name="download_extensions" class="regular-text"
                                       value="<?php echo esc_attr(implode(', ', $this->download_extensions())); ?>">
                            </p>
                            <?php
                            $toggle('ev_outbound', __('Links to other sites (outbound_click)', 'valolink-plugin'));
                            $toggle('ev_forms', __('Form sent: Contact Form 7, Gravity Forms (form_success)', 'valolink-plugin'), __('Counts forms that were actually sent, not attempts that failed validation. A Gravity Forms confirmation that redirects to another page shows as that page\'s pageview instead.', 'valolink-plugin'));
                            $toggle('ev_not_found', __('Page not found (not_found)', 'valolink-plugin'), __('With the page that linked to it, so broken links can be found and fixed.', 'valolink-plugin'));
                            $toggle('ev_search', __('Site search (search)', 'valolink-plugin'), __('The search term and how many results it found; searches that found nothing are worth reading.', 'valolink-plugin'));
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="valolink-umami-rules"><?php esc_html_e('Click rules', 'valolink-plugin'); ?></label>
                        </th>
                        <td>
                            <textarea id="valolink-umami-rules" name="rules" rows="5" class="large-text code"
                                      placeholder=".cta-soita | cta_call"><?php echo esc_textarea($this->rules_text()); ?></textarea>
                            <p class="description" style="max-width:700px;">
                                <?php esc_html_e('One rule per line: a CSS selector, "|", and the event name. A click on a matching element (or anything inside it) sends that event with the element\'s text. Give the element a CSS class in the page editor and use it here, e.g. ".cta-soita | cta_call". Rules are checked before the automatic events.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <?php if (function_exists('WC')) : ?>
                    <h2><?php esc_html_e('WooCommerce', 'valolink-plugin'); ?></h2>
                    <table class="form-table" role="presentation"><tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Shop events', 'valolink-plugin'); ?></th>
                            <td>
                                <?php
                                $toggle('woo_browser', __('Product views, add to cart, cart and checkout (view_item, add_to_cart, view_cart, begin_checkout)', 'valolink-plugin'));
                                $toggle('woo_purchase', __('Purchases with revenue (purchase)', 'valolink-plugin'), __('Sent from the server once the order is paid, so orders paid in a bank\'s or Paytrail\'s window count even when the customer never returns to the shop. The order total includes VAT and shipping, in the order\'s currency. It lands in the customer\'s own visit, keeping its referrer and campaign. Refunds are not subtracted. Set the currency of the Revenue report in Umami to EUR.', 'valolink-plugin'));
                                ?>
                            </td>
                        </tr>
                    </tbody></table>
                <?php endif; ?>

                <h2><?php esc_html_e('Consent and testing', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Cookie consent', 'valolink-plugin'); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:.4em;">
                                <input type="radio" name="consent" value="<?php echo esc_attr(self::CONSENT_NONE); ?>" <?php checked($this->consent_mode(), self::CONSENT_NONE); ?>>
                                <?php esc_html_e('Track without asking (Umami uses no cookies)', 'valolink-plugin'); ?>
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="consent" value="<?php echo esc_attr(self::CONSENT_AUTO); ?>" <?php checked($this->consent_mode(), self::CONSENT_AUTO); ?>>
                                <?php esc_html_e('Wait for statistics consent from the cookie banner', 'valolink-plugin'); ?>
                            </label>
                            <p class="description" style="max-width:700px;">
                                <?php esc_html_e('Understands the WP Consent API, Complianz, CookieYes and Cookiebot. With no banner on the site nothing is ever tracked in this mode.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Test mode', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="test_mode" value="1" <?php checked($this->test_mode()); ?>>
                                <?php esc_html_e('Editors get the tracker and events too, written to the browser console instead of sent.', 'valolink-plugin'); ?>
                            </label>
                            <p class="description" style="max-width:700px;">
                                <?php esc_html_e('For checking the events while logged in: open the browser\'s developer tools, Console, and look for "[Umami]". Works on staging too. Purchases by editors are never sent.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <?php submit_button(__('Save Umami Settings', 'valolink-plugin')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION);

        $raw        = isset($_POST['website_id']) ? sanitize_text_field(wp_unslash((string) $_POST['website_id'])) : '';
        $website_id = strtolower(trim($raw));
        $args       = ['page' => self::SUBPAGE_SLUG];

        if ($website_id !== '' && preg_match(self::UUID_PATTERN, $website_id) !== 1) {
            wp_safe_redirect(add_query_arg($args + ['invalid' => '1'], admin_url('admin.php')));
            exit;
        }

        $settings = [
            'website_id'          => $website_id,
            'exclude_search'      => !empty($_POST['exclude_search']),
            'test_mode'           => !empty($_POST['test_mode']),
            'consent'             => (isset($_POST['consent']) && $_POST['consent'] === self::CONSENT_AUTO) ? self::CONSENT_AUTO : self::CONSENT_NONE,
            'download_extensions' => implode(', ', self::parse_extensions(isset($_POST['download_extensions']) ? sanitize_text_field(wp_unslash((string) $_POST['download_extensions'])) : '')),
        ];
        foreach (array_keys(self::EVENT_TOGGLES) as $key) {
            // The WooCommerce toggles are only on the page when WooCommerce is active.
            if (str_starts_with($key, 'woo_') && !function_exists('WC')) {
                continue;
            }
            $settings[$key] = !empty($_POST[$key]);
        }
        $parsed            = self::parse_rules(isset($_POST['rules']) ? sanitize_textarea_field(wp_unslash((string) $_POST['rules'])) : '');
        $settings['rules'] = $parsed['rules'];

        $this->settings->set_module_settings(self::MODULE_ID, $settings);

        $args['updated'] = '1';
        if ($parsed['skipped'] > 0) {
            $args['skipped'] = (string) $parsed['skipped'];
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * "selector | event_name" per line; split at the last "|" so a selector
     * may contain one.
     *
     * @return array{rules: list<array{selector: string, event: string}>, skipped: int}
     */
    public static function parse_rules(string $text): array
    {
        $rules   = [];
        $skipped = 0;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $at       = strrpos($line, '|');
            $selector = $at === false ? '' : trim(substr($line, 0, $at));
            $event    = $at === false ? '' : trim(substr($line, $at + 1));
            if ($selector === '' || strlen($selector) > 300 || preg_match(self::EVENT_PATTERN, $event) !== 1) {
                $skipped++;
                continue;
            }
            $rules[] = ['selector' => $selector, 'event' => $event];
        }

        return ['rules' => array_slice($rules, 0, 50), 'skipped' => $skipped];
    }

    /** @return list<string> */
    public static function parse_extensions(string $text): array
    {
        $extensions = [];
        foreach (preg_split('/[\s,;]+/', strtolower($text)) ?: [] as $extension) {
            $extension = ltrim($extension, '.');
            if (preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1) {
                $extensions[] = $extension;
            }
        }

        return array_values(array_unique($extensions));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function website_id(): string
    {
        $id = $this->settings->get_module_setting(self::MODULE_ID, 'website_id', '');
        return is_string($id) && preg_match(self::UUID_PATTERN, $id) === 1 ? $id : '';
    }

    public function consent_mode(): string
    {
        return $this->settings->get_module_setting(self::MODULE_ID, 'consent', self::CONSENT_NONE) === self::CONSENT_AUTO
            ? self::CONSENT_AUTO
            : self::CONSENT_NONE;
    }

    private function exclude_search(): bool
    {
        return (bool) $this->settings->get_module_setting(self::MODULE_ID, 'exclude_search', false);
    }

    private function test_mode(): bool
    {
        return (bool) $this->settings->get_module_setting(self::MODULE_ID, 'test_mode', false);
    }

    private function event_enabled(string $key): bool
    {
        return (bool) $this->settings->get_module_setting(self::MODULE_ID, $key, self::EVENT_TOGGLES[$key] ?? false);
    }

    /** @return list<string> */
    private function download_extensions(): array
    {
        $saved = $this->settings->get_module_setting(self::MODULE_ID, 'download_extensions', self::DEFAULT_DOWNLOAD_EXTENSIONS);
        return self::parse_extensions(is_string($saved) ? $saved : self::DEFAULT_DOWNLOAD_EXTENSIONS);
    }

    /** @return list<array{selector: string, event: string}> */
    private function rules(): array
    {
        $saved = $this->settings->get_module_setting(self::MODULE_ID, 'rules', []);
        if (!is_array($saved)) {
            return [];
        }
        $rules = [];
        foreach ($saved as $rule) {
            if (is_array($rule) && is_string($rule['selector'] ?? null) && is_string($rule['event'] ?? null)) {
                $rules[] = ['selector' => $rule['selector'], 'event' => $rule['event']];
            }
        }

        return $rules;
    }

    private function rules_text(): string
    {
        return implode("\n", array_map(static fn (array $rule): string => $rule['selector'] . ' | ' . $rule['event'], $this->rules()));
    }
}
