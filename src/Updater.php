<?php

declare(strict_types=1);

namespace Valolink\Plugin;

/**
 * Minimal self-contained GitHub release updater (no third-party library). Hooks WordPress's
 * own update flow so updates surface in wp-admin. Prefers a `.zip` release asset (built by
 * build.sh), falling back to GitHub's source zipball and renaming the unpacked folder to the slug.
 *
 * Inert until a real repo is passed (see VALOLINK_PLUGIN_GITHUB_REPO in the main plugin file).
 */
final class Updater
{
    public const CHECK_ACTION = 'valolink_plugin_check_updates';

    private string $basename;
    private string $slug;
    private string $cache_key;
    private int $cache_ttl;

    public function __construct(
        private readonly string $plugin_file,
        private readonly string $repo,
        private readonly string $version,
    ) {
        $this->basename  = plugin_basename($plugin_file);
        $this->slug      = dirname($this->basename);
        $this->cache_key = 'valolink_plugin_updater_' . md5($repo);
        $this->cache_ttl = 6 * HOUR_IN_SECONDS;
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clear_cache']);

        // "Check for updates" link in the Plugins list + its handler + a confirmation notice.
        add_filter('plugin_action_links_' . $this->basename, [$this, 'action_links']);
        add_action('admin_post_' . self::CHECK_ACTION, [$this, 'handle_check']);
        add_action('admin_notices', [$this, 'maybe_checked_notice']);
    }

    public function action_links($links)
    {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::CHECK_ACTION),
            self::CHECK_ACTION,
        );
        $links['valolink_plugin_check'] = '<a href="' . esc_url($url) . '">'
            . esc_html__('Check for updates', 'valolink-plugin') . '</a>';
        return $links;
    }

    public function handle_check(): void
    {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::CHECK_ACTION);

        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');
        wp_update_plugins();

        wp_safe_redirect(add_query_arg('valolink_plugin_checked', '1', self_admin_url('plugins.php')));
        exit;
    }

    public function maybe_checked_notice(): void
    {
        if (empty($_GET['valolink_plugin_checked'])) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'plugins') {
            return;
        }

        $update     = get_site_transient('update_plugins');
        $has_update = isset($update->response[$this->basename]);
        $message    = $has_update
            ? __('Valolink Plugin: an update is available below.', 'valolink-plugin')
            : __('Valolink Plugin is up to date.', 'valolink-plugin');

        echo '<div class="notice notice-' . ($has_update ? 'warning' : 'success') . ' is-dismissible"><p>'
            . esc_html($message) . '</p></div>';
    }

    public function check_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release     = $this->get_release();
        $new_version = $this->release_version($release);
        if ($new_version === '' || version_compare($new_version, $this->version, '<=')) {
            return $transient;
        }

        $package = $this->package_url($release);
        if ($package === '') {
            return $transient;
        }

        $transient->response[$this->basename] = (object) [
            'slug'        => $this->slug,
            'plugin'      => $this->basename,
            'new_version' => $new_version,
            'package'     => $package,
            'url'         => $release['html_url'] ?? '',
        ];
        return $transient;
    }

    public function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_release();
        if (empty($release)) {
            return $result;
        }

        return (object) [
            'name'          => 'Valolink Plugin',
            'slug'          => $this->slug,
            'version'       => $this->release_version($release),
            'homepage'      => $release['html_url'] ?? '',
            'download_link' => $this->package_url($release),
            'sections'      => [
                'changelog' => !empty($release['body']) ? wpautop(esc_html((string) $release['body'])) : '',
            ],
        ];
    }

    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = [])
    {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $source;
        }

        global $wp_filesystem;
        $desired = trailingslashit($remote_source) . $this->slug . '/';
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }
        if ($wp_filesystem && $wp_filesystem->move($source, $desired)) {
            return $desired;
        }
        return $source;
    }

    public function clear_cache(): void
    {
        delete_transient($this->cache_key);
    }

    private function get_release(): array
    {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return is_array($cached) ? $cached : [];
        }

        $response = wp_remote_get("https://api.github.com/repos/{$this->repo}/releases/latest", [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'valolink-plugin-updater',
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($this->cache_key, [], 15 * MINUTE_IN_SECONDS);
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : [];
        set_transient($this->cache_key, $data, $this->cache_ttl);
        return $data;
    }

    private function release_version(array $release): string
    {
        return isset($release['tag_name']) ? ltrim((string) $release['tag_name'], 'vV') : '';
    }

    private function package_url(array $release): string
    {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['browser_download_url']) && str_ends_with((string) $asset['name'], '.zip')) {
                    return (string) $asset['browser_download_url'];
                }
            }
        }
        return (string) ($release['zipball_url'] ?? '');
    }
}
