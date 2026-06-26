<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\EngineLink;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

final class EngineLinkModule implements Module
{
    public const MODULE_ID        = 'enginelink';
    public const SUBPAGE_SLUG     = 'valolink-enginelink';
    public const REGEN_KEY_ACTION = 'valolink_regen_enginelink_key';
    public const REGEN_KEY_NONCE  = 'valolink_regen_enginelink_key';
    public const REST_NAMESPACE   = 'enginelink/v1';

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        return $context->is_admin || $context->is_rest;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::REGEN_KEY_ACTION, [$this, 'handle_regen_key']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function uninstall(): void
    {
        $this->settings->forget_module(self::MODULE_ID);
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function add_settings_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('EngineLink', 'valolink-plugin'),
            __('EngineLink', 'valolink-plugin'),
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

        $api_key = $this->ensure_api_key();
        $updated = isset($_GET['updated']) && $_GET['updated'] === '1';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('EngineLink', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e('API key regenerated.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <p><?php esc_html_e('EngineLink pulls site status data from this WordPress install on demand. Copy the API key below and paste it into the EngineLink site settings.', 'valolink-plugin'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Base URL', 'valolink-plugin'); ?></th>
                    <td><code><?php echo esc_html(rest_url(self::REST_NAMESPACE)); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('API Key', 'valolink-plugin'); ?></th>
                    <td>
                        <div style="display:flex;gap:8px;align-items:center;max-width:520px;">
                            <input
                                id="valolink-enginelink-key"
                                type="text"
                                class="regular-text code"
                                value="<?php echo esc_attr($api_key); ?>"
                                readonly
                                style="flex:1;"
                            >
                            <button
                                type="button"
                                class="button"
                                onclick="(function(btn){
                                    navigator.clipboard.writeText(document.getElementById('valolink-enginelink-key').value).then(function(){
                                        var orig = btn.textContent;
                                        btn.textContent = '<?php echo esc_js(__('Copied!', 'valolink-plugin')); ?>';
                                        setTimeout(function(){ btn.textContent = orig; }, 2000);
                                    });
                                })(this)"
                            ><?php esc_html_e('Copy', 'valolink-plugin'); ?></button>
                        </div>
                    </td>
                </tr>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::REGEN_KEY_ACTION); ?>">
                <?php wp_nonce_field(self::REGEN_KEY_NONCE); ?>
                <?php submit_button(__('Regenerate API key', 'valolink-plugin'), 'secondary', 'submit', false); ?>
                <p class="description" style="margin-top:8px;"><?php esc_html_e('Regenerating invalidates the current key immediately. Update EngineLink before regenerating.', 'valolink-plugin'); ?></p>
            </form>
        </div>
        <?php
    }

    public function handle_regen_key(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }

        check_admin_referer(self::REGEN_KEY_NONCE);

        $this->settings->set_module_settings(self::MODULE_ID, ['api_key' => bin2hex(random_bytes(24))]);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // REST API
    // -------------------------------------------------------------------------

    public function register_routes(): void
    {
        $auth = new EnginelinkAuth($this->settings);

        register_rest_route(self::REST_NAMESPACE, '/ping', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_ping'],
            'permission_callback' => [$auth, 'check'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_status'],
            'permission_callback' => [$auth, 'check'],
        ]);
    }

    public function handle_ping(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'ok'             => true,
            'plugin_version' => VALOLINK_PLUGIN_VERSION,
        ]);
    }

    public function handle_status(): \WP_REST_Response
    {
        return new \WP_REST_Response((new StatusCollector())->collect());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function ensure_api_key(): string
    {
        $key = $this->setting('api_key', '');
        if (is_string($key) && $key !== '') {
            return $key;
        }

        $key = bin2hex(random_bytes(24));
        $this->settings->set_module_settings(self::MODULE_ID, ['api_key' => $key]);

        return $key;
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->get_module_setting(self::MODULE_ID, $key, $default);
    }
}
