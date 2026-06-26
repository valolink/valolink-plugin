<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\EngineLink;

final class StatusCollector
{
    public function collect(): array
    {
        return [
            'plugin_version' => VALOLINK_PLUGIN_VERSION,
            'collected_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            'wordpress'      => $this->wordpress(),
            'php'            => $this->php(),
            'themes'         => $this->themes(),
            'plugins'        => $this->plugins(),
            'updates'        => $this->updates(),
            'users'          => $this->users(),
            'database'       => $this->database(),
            'health'         => $this->health(),
        ];
    }

    private function wordpress(): array
    {
        $tz = (string) get_option('timezone_string', '');
        if ($tz === '') {
            $offset = (float) get_option('gmt_offset', 0);
            $tz = 'UTC' . ($offset >= 0 ? '+' : '') . $offset;
        }

        return [
            'version'    => (string) get_bloginfo('version'),
            'language'   => (string) get_locale(),
            'timezone'   => $tz,
            'multisite'  => is_multisite(),
            'debug_mode' => defined('WP_DEBUG') && WP_DEBUG === true,
        ];
    }

    private function php(): array
    {
        $limit = ini_get('memory_limit');

        return [
            'version'            => PHP_VERSION,
            'memory_limit'       => $limit !== false ? $limit : null,
            'max_execution_time' => (int) ini_get('max_execution_time'),
        ];
    }

    private function themes(): array
    {
        $theme = wp_get_theme();

        return [
            'active' => [
                'name'    => $theme->get('Name'),
                'slug'    => $theme->get_stylesheet(),
                'version' => $theme->get('Version'),
                'author'  => wp_strip_all_tags((string) $theme->get('Author')),
            ],
        ];
    }

    private function plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all          = get_plugins();
        $active       = (array) get_option('active_plugins', []);
        $update_data  = get_site_transient('update_plugins');
        $with_updates = is_object($update_data) && isset($update_data->response)
            ? (array) $update_data->response
            : [];

        $result = [];
        foreach ($all as $file => $data) {
            $update = $with_updates[$file] ?? null;

            $result[] = [
                'name'             => $data['Name'],
                'slug'             => $file,
                'version'          => $data['Version'],
                'active'           => in_array($file, $active, true),
                'update_available' => $update !== null,
                'new_version'      => $update !== null ? ($update->new_version ?? null) : null,
                'author'           => wp_strip_all_tags($data['Author']),
            ];
        }

        usort($result, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $result;
    }

    private function updates(): array
    {
        $core_data    = get_site_transient('update_core');
        $wp_update    = false;
        $wp_new       = null;

        if (is_object($core_data) && !empty($core_data->updates)) {
            foreach ($core_data->updates as $update) {
                if (isset($update->response) && $update->response === 'upgrade') {
                    $wp_update = true;
                    $wp_new    = $update->version ?? null;
                    break;
                }
            }
        }

        $plugin_data  = get_site_transient('update_plugins');
        $plugin_count = is_object($plugin_data) && isset($plugin_data->response)
            ? count((array) $plugin_data->response)
            : 0;

        $theme_data  = get_site_transient('update_themes');
        $theme_count = is_object($theme_data) && isset($theme_data->response)
            ? count((array) $theme_data->response)
            : 0;

        return [
            'wordpress_update_available' => $wp_update,
            'new_wordpress_version'      => $wp_new,
            'plugin_updates_count'       => $plugin_count,
            'theme_updates_count'        => $theme_count,
        ];
    }

    private function users(): array
    {
        $counts = count_users();

        return [
            'admin_count' => $counts['avail_roles']['administrator'] ?? 0,
            'total_count' => $counts['total_users'],
        ];
    }

    private function database(): array
    {
        global $wpdb;

        $size = $wpdb->get_var(
            'SELECT ROUND(SUM(data_length + index_length) / 1048576, 2) FROM information_schema.TABLES WHERE table_schema = DATABASE()',
        );

        return [
            'size_mb' => $size !== null ? (float) $size : null,
        ];
    }

    private function health(): array
    {
        // Check for cron events that have been overdue for more than 2 hours.
        $cron    = _get_cron_array() ?: [];
        $now     = time();
        $cron_ok = true;
        foreach ($cron as $timestamp => $hooks) {
            if (is_int($timestamp) && $timestamp < ($now - 2 * HOUR_IN_SECONDS)) {
                $cron_ok = false;
                break;
            }
        }

        return [
            'loopback_ok'         => null, // requires an outbound HTTP request; deferred to EngineLink
            'scheduled_events_ok' => $cron_ok,
            'https_ok'            => str_starts_with((string) get_option('home', ''), 'https://'),
        ];
    }
}
