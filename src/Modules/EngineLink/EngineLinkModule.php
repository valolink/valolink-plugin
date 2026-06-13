<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\EngineLink;

use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

final class EngineLinkModule implements Module
{
    public const MODULE_ID   = 'enginelink';
    public const NAMESPACE   = 'enginelink/v1';
    public const OPTION_KEY  = 'valolink_enginelink_key';

    public function __construct(private readonly Settings $settings) {}

    public function id(): string
    {
        return self::MODULE_ID;
    }

    public function should_load(Context $ctx): bool
    {
        return $ctx->is_rest;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        $auth = new EnginelinkAuth($this->settings);

        register_rest_route(self::NAMESPACE, '/ping', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => fn() => new \WP_REST_Response(['ok' => true], 200),
            'permission_callback' => [$auth, 'check'],
        ]);

        register_rest_route(self::NAMESPACE, '/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'status'],
            'permission_callback' => [$auth, 'check'],
        ]);
    }

    public function status(): \WP_REST_Response
    {
        global $wpdb;

        $core_updates   = get_site_transient('update_core');
        $plugin_updates = get_site_transient('update_plugins');
        $theme_updates  = get_site_transient('update_themes');

        $wp_update_available  = false;
        $new_wp_version       = null;
        if (isset($core_updates->updates) && is_array($core_updates->updates)) {
            foreach ($core_updates->updates as $update) {
                if ($update->response === 'upgrade') {
                    $wp_update_available = true;
                    $new_wp_version      = $update->version;
                    break;
                }
            }
        }

        $plugin_updates_count = 0;
        if (isset($plugin_updates->response) && is_array($plugin_updates->response)) {
            $plugin_updates_count = count($plugin_updates->response);
        }

        $theme_updates_count = 0;
        if (isset($theme_updates->response) && is_array($theme_updates->response)) {
            $theme_updates_count = count($theme_updates->response);
        }

        // Plugins
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $plugin_data    = [];
        foreach ($all_plugins as $file => $info) {
            $slug          = explode('/', $file)[0];
            $update_info   = $plugin_updates->response[$file] ?? null;
            $plugin_data[] = [
                'name'             => $info['Name'],
                'slug'             => $file,
                'version'          => $info['Version'],
                'active'           => in_array($file, $active_plugins, true),
                'update_available' => $update_info !== null,
                'new_version'      => $update_info->new_version ?? null,
                'author'           => wp_strip_all_tags($info['Author']),
            ];
        }

        // Active theme
        $theme        = wp_get_theme();
        $active_theme = [
            'name'    => $theme->get('Name'),
            'slug'    => $theme->get_stylesheet(),
            'version' => $theme->get('Version'),
            'author'  => wp_strip_all_tags($theme->get('Author')),
        ];

        // Users
        $admin_count = count(get_users(['role' => 'administrator', 'fields' => 'ID']));
        $total_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");

        // Database size
        $db_size_mb = null;
        $db_name    = DB_NAME;
        $rows       = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT SUM(data_length + index_length) / 1024 / 1024 AS size_mb
                 FROM information_schema.tables
                 WHERE table_schema = %s",
                $db_name,
            ),
        );
        if (!empty($rows[0]->size_mb)) {
            $db_size_mb = round((float) $rows[0]->size_mb, 2);
        }

        // Health
        $health_https = is_ssl();
        $scheduled_ok = !wp_next_scheduled('wp_version_check') === false; // true if cron is running
        // Simpler check: verify wp-cron is not disabled
        $scheduled_ok = !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);

        return new \WP_REST_Response([
            'plugin_version' => VALOLINK_PLUGIN_VERSION,
            'collected_at'   => gmdate('c'),
            'wordpress'      => [
                'version'    => get_bloginfo('version'),
                'language'   => get_locale(),
                'timezone'   => wp_timezone_string(),
                'multisite'  => is_multisite(),
                'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
            ],
            'php'     => [
                'version'           => PHP_VERSION,
                'memory_limit'      => ini_get('memory_limit') ?: null,
                'max_execution_time' => (int) ini_get('max_execution_time'),
            ],
            'themes'  => [
                'active' => $active_theme,
            ],
            'plugins' => $plugin_data,
            'updates' => [
                'wordpress_update_available' => $wp_update_available,
                'new_wordpress_version'      => $new_wp_version,
                'plugin_updates_count'       => $plugin_updates_count,
                'theme_updates_count'        => $theme_updates_count,
            ],
            'users'    => [
                'admin_count' => $admin_count,
                'total_count' => $total_count,
            ],
            'database' => [
                'size_mb' => $db_size_mb,
            ],
            'health'   => [
                'https_ok'             => $health_https,
                'scheduled_events_ok'  => $scheduled_ok,
                'loopback_ok'          => null,
            ],
        ], 200);
    }

    public function uninstall(): void
    {
        $this->settings->forget_module(self::MODULE_ID);
    }
}
