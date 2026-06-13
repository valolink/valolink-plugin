<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Staging;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

final class StagingModule implements Module
{
    public const MODULE_ID    = 'staging';
    public const SUBPAGE_SLUG = 'valolink-staging';
    public const NONCE_ACTION = 'valolink_save_staging';
    public const SAVE_ACTION  = 'valolink_save_staging';

    /** WooCommerce gateway IDs that are safe to keep active on staging (no real money). */
    private const SAFE_GATEWAYS = ['bacs', 'cheque', 'cod'];

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        // Always load — the settings page must be reachable even when staging is off,
        // and per-feature toggles below check for active staging before doing anything.
        return true;
    }

    public function register(): void
    {
        // Settings page is always available.
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);

        if (!$this->is_staging_active()) {
            return;
        }

        // From here down: features only register when staging is effectively active.
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

        add_action('admin_notices', [$this, 'render_admin_notice']);
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

        $detected = StagingDetector::is_staging();
        $forced   = (bool) $this->setting('force_staging');
        $active   = $detected || $forced;
        $updated  = isset($_GET['updated']) && $_GET['updated'] === '1';

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins      = get_plugins();
        $disabled_plugins = (array) $this->setting('disabled_plugins');

        $pages = get_pages(['post_status' => 'publish', 'sort_column' => 'post_title']);
        $coming_soon_page_id = (int) $this->setting('coming_soon_page_id');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Staging', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html__('Staging settings saved.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <!-- Status panel -->
            <div class="card" style="padding: 12px 18px;max-width:none;display:flex;gap:24px;flex-wrap:wrap;align-items:center;">
                <div>
                    <strong><?php echo esc_html__('Detected', 'valolink-plugin'); ?>:</strong>
                    <?php echo $detected
                        ? '<span style="color:#118a4c;">' . esc_html__('yes', 'valolink-plugin') . '</span>'
                        : '<span style="color:#646970;">' . esc_html__('no', 'valolink-plugin') . '</span>'; ?>
                </div>
                <div>
                    <strong><?php echo esc_html__('Forced', 'valolink-plugin'); ?>:</strong>
                    <?php echo $forced
                        ? '<span style="color:#b32d2e;">' . esc_html__('yes', 'valolink-plugin') . '</span>'
                        : '<span style="color:#646970;">' . esc_html__('no', 'valolink-plugin') . '</span>'; ?>
                </div>
                <div>
                    <strong><?php echo esc_html__('Active', 'valolink-plugin'); ?>:</strong>
                    <?php echo $active
                        ? '<span style="color:#b32d2e;font-weight:600;">' . esc_html__('STAGING', 'valolink-plugin') . '</span>'
                        : '<span style="color:#118a4c;font-weight:600;">' . esc_html__('PRODUCTION', 'valolink-plugin') . '</span>'; ?>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <h2><?php echo esc_html__('Mode', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Force staging mode', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="force_staging" value="1" <?php checked($forced); ?>>
                                <?php echo esc_html__('Treat this site as staging even if auto-detection says otherwise.', 'valolink-plugin'); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('Useful for hiding a live site temporarily, or for sites we host on production domains.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <h2><?php echo esc_html__('Visibility', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row('block_indexing',
                        __('Block search indexing', 'valolink-plugin'),
                        __('Adds noindex/nofollow robots meta and X-Robots-Tag header.', 'valolink-plugin')); ?>
                    <?php $this->checkbox_row('require_login',
                        __('Require login for frontend', 'valolink-plugin'),
                        __('Non-logged-in visitors are redirected to wp-login.php. Also blocks unauthenticated REST API calls.', 'valolink-plugin')); ?>
                    <tr>
                        <th scope="row"><?php echo esc_html__('"Coming soon" redirect', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="coming_soon_enabled" value="1"
                                       <?php checked($this->setting('coming_soon_enabled')); ?>>
                                <?php echo esc_html__('Redirect frontend visitors to the page below (admin and login bypassed; logged-in admins can preview).', 'valolink-plugin'); ?>
                            </label>
                            <br><br>
                            <label>
                                <?php echo esc_html__('Page', 'valolink-plugin'); ?>:
                                <select name="coming_soon_page_id">
                                    <option value="0">— <?php echo esc_html__('Select a page', 'valolink-plugin'); ?> —</option>
                                    <?php foreach ($pages as $page) : ?>
                                        <option value="<?php echo esc_attr((string) $page->ID); ?>"
                                                <?php selected($coming_soon_page_id, $page->ID); ?>>
                                            <?php echo esc_html($page->post_title ?: ('#' . $page->ID)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('Build a normal WordPress page and select it here. If "Require login" is also on, login takes precedence.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <h2><?php echo esc_html__('Mail & payments', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row('intercept_mail',
                        __('Intercept outgoing mail', 'valolink-plugin'),
                        __('All outgoing wp_mail() is redirected to the site admin with a [STAGING] prefix.', 'valolink-plugin')); ?>
                    <?php $this->checkbox_row('disable_live_gateways',
                        __('Disable live WooCommerce payment gateways', 'valolink-plugin'),
                        __('Only safe offline gateways (BACS, Cheque, COD) remain active when WooCommerce is installed.', 'valolink-plugin')); ?>
                </tbody></table>

                <h2><?php echo esc_html__('Updates', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row('block_auto_updates',
                        __('Block automatic updates', 'valolink-plugin'),
                        __('Prevents WordPress from auto-updating core, plugins, themes, and translations while staging is active.', 'valolink-plugin')); ?>
                </tbody></table>

                <h2><?php echo esc_html__('Plugins to disable', 'valolink-plugin'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Selected plugins will not load while staging is active. Requires the mu-plugin loader (auto-installed).', 'valolink-plugin'); ?>
                    <?php if (MuPluginInstaller::is_installed()) : ?>
                        <span style="color:#118a4c;">✓ <?php echo esc_html__('mu-loader installed.', 'valolink-plugin'); ?></span>
                    <?php else : ?>
                        <span style="color:#b32d2e;">⚠ <?php echo esc_html__('mu-loader NOT installed. Plugin disabling will not take effect.', 'valolink-plugin'); ?></span>
                    <?php endif; ?>
                </p>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable plugin disabling', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="disable_plugins_enabled" value="1"
                                       <?php checked($this->setting('disable_plugins_enabled')); ?>>
                                <?php echo esc_html__('Disable the selected plugins when staging is active.', 'valolink-plugin'); ?>
                            </label>
                        </td>
                    </tr>
                </tbody></table>

                <?php if (!empty($all_plugins)) : ?>
                    <table class="widefat striped" style="max-width: 900px;">
                        <thead>
                            <tr>
                                <th style="width:80px;"><?php echo esc_html__('Disable', 'valolink-plugin'); ?></th>
                                <th><?php echo esc_html__('Plugin', 'valolink-plugin'); ?></th>
                                <th style="width:120px;"><?php echo esc_html__('Version', 'valolink-plugin'); ?></th>
                                <th><?php echo esc_html__('Path', 'valolink-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_plugins as $plugin_file => $plugin_data) :
                                if (str_starts_with($plugin_file, 'valolink-plugin/')) continue; // never disable self
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox"
                                               name="disabled_plugins[]"
                                               value="<?php echo esc_attr($plugin_file); ?>"
                                               <?php checked(in_array($plugin_file, $disabled_plugins, true)); ?>>
                                    </td>
                                    <td><strong><?php echo esc_html($plugin_data['Name']); ?></strong></td>
                                    <td><?php echo esc_html($plugin_data['Version'] ?? ''); ?></td>
                                    <td><code><?php echo esc_html($plugin_file); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php submit_button(__('Save Staging Settings', 'valolink-plugin')); ?>
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
                           <?php checked($this->setting($key)); ?>>
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

        $disabled = isset($_POST['disabled_plugins']) && is_array($_POST['disabled_plugins'])
            ? array_values(array_filter(
                array_map(static fn($v) => sanitize_text_field((string) $v), wp_unslash($_POST['disabled_plugins'])),
                static fn(string $v) => $v !== '' && !str_starts_with($v, 'valolink-plugin/'),
            ))
            : [];

        $this->settings->set_module_settings(self::MODULE_ID, [
            'force_staging'           => $bool('force_staging'),
            'block_indexing'          => $bool('block_indexing'),
            'intercept_mail'          => $bool('intercept_mail'),
            'disable_live_gateways'   => $bool('disable_live_gateways'),
            'require_login'           => $bool('require_login'),
            'coming_soon_enabled'     => $bool('coming_soon_enabled'),
            'coming_soon_page_id'     => max(0, (int) ($_POST['coming_soon_page_id'] ?? 0)),
            'disable_plugins_enabled' => $bool('disable_plugins_enabled'),
            'disabled_plugins'        => $disabled,
            'block_auto_updates'      => $bool('block_auto_updates'),
        ]);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
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
        $admin_email = (string) get_option('admin_email', '');
        if ($admin_email === '') {
            return $atts;
        }

        $original_to = is_array($atts['to'] ?? null)
            ? implode(', ', $atts['to'])
            : (string) ($atts['to'] ?? '');

        $atts['to']      = $admin_email;
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
        if (is_user_logged_in() || self::is_bypassable_request()) {
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
        // Admins can preview the real site.
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return;
        }
        if (self::is_bypassable_request()) {
            return;
        }

        $page_id = (int) $this->setting('coming_soon_page_id');
        if ($page_id <= 0) {
            return;
        }
        if (is_page($page_id)) {
            return; // already on the coming-soon page
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
        if ($this->is_enabled('intercept_mail'))        $active[] = __('mail intercepted', 'valolink-plugin');
        if ($this->is_enabled('disable_live_gateways') && class_exists('WooCommerce'))
                                                        $active[] = __('Woo live gateways off', 'valolink-plugin');
        if ($this->is_enabled('require_login'))         $active[] = __('login required', 'valolink-plugin');
        if ($this->is_enabled('coming_soon_enabled'))   $active[] = __('coming-soon redirect', 'valolink-plugin');
        if ($this->is_enabled('block_auto_updates'))    $active[] = __('auto-updates blocked', 'valolink-plugin');
        if ($this->is_enabled('disable_plugins_enabled')) {
            $count = count((array) $this->setting('disabled_plugins'));
            if ($count > 0) {
                $active[] = sprintf(_n('%d plugin disabled', '%d plugins disabled', $count, 'valolink-plugin'), $count);
            }
        }
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Staging mode active', 'valolink-plugin'); ?></strong>
                <?php if ((bool) $this->setting('force_staging')) : ?>
                    <em>(<?php esc_html_e('forced', 'valolink-plugin'); ?>)</em>
                <?php endif; ?>
                <?php if ($active) : ?>
                    — <?php echo esc_html(implode(', ', $active)); ?>
                <?php endif; ?>
                · <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SUBPAGE_SLUG)); ?>"><?php esc_html_e('configure', 'valolink-plugin'); ?></a>
            </p>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Used by both require_login and coming_soon redirects. */
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

    private function is_staging_active(): bool
    {
        if ((bool) $this->setting('force_staging')) {
            return true;
        }
        return StagingDetector::is_staging();
    }

    private function is_enabled(string $key): bool
    {
        return (bool) $this->setting($key);
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings->get_module_setting(self::MODULE_ID, $key, null);
        if ($value === null) {
            $defaults = self::defaults();
            return $defaults[$key] ?? $default;
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'force_staging'           => false,
            'block_indexing'          => true,
            'intercept_mail'          => true,
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
