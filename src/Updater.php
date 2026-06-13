<?php

declare(strict_types=1);

namespace Valolink\Plugin;

/**
 * Self-hosted update checker. Hooks into WP's transient-based update mechanism
 * and points at the EngineLink instance as the manifest/download server.
 */
final class Updater
{
    private const TRANSIENT_KEY = 'valolink_update_manifest';
    private const CACHE_TTL     = 6 * HOUR_IN_SECONDS;

    public function __construct(private readonly Settings $settings) {}

    public function register(): void
    {
        $url = $this->manifest_base_url();
        if (!$url) {
            return; // No EngineLink URL configured — skip.
        }

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);
    }

    /** Injects our plugin into the WP update transient if a newer version is available. */
    public function inject_update(object $transient): object
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $manifest = $this->fetch_manifest();
        if (!$manifest || empty($manifest['version'])) {
            return $transient;
        }

        if (version_compare($manifest['version'], VALOLINK_PLUGIN_VERSION, '>')) {
            $update                 = new \stdClass();
            $update->slug           = 'valolink-plugin';
            $update->plugin         = VALOLINK_PLUGIN_BASENAME;
            $update->new_version    = $manifest['version'];
            $update->url            = $manifest['homepage'] ?? '';
            $update->package        = $manifest['download_url'] ?? '';
            $update->requires       = $manifest['requires'] ?? '6.5';
            $update->requires_php   = $manifest['requires_php'] ?? '8.2';
            $update->tested         = $manifest['tested'] ?? '';

            $transient->response[VALOLINK_PLUGIN_BASENAME] = $update;
        } else {
            // Mark as "no update available" to prevent stale notices.
            $no_update                 = new \stdClass();
            $no_update->slug           = 'valolink-plugin';
            $no_update->plugin         = VALOLINK_PLUGIN_BASENAME;
            $no_update->new_version    = VALOLINK_PLUGIN_VERSION;
            $no_update->url            = '';
            $no_update->package        = '';

            $transient->no_update[VALOLINK_PLUGIN_BASENAME] = $no_update;
        }

        return $transient;
    }

    /** Provides plugin info for the "View Details" modal. */
    public function plugin_info(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!isset($args->slug) || $args->slug !== 'valolink-plugin') {
            return $result;
        }

        $manifest = $this->fetch_manifest();
        if (!$manifest) {
            return $result;
        }

        $info                = new \stdClass();
        $info->name          = 'Valolink Plugin';
        $info->slug          = 'valolink-plugin';
        $info->version       = $manifest['version'] ?? VALOLINK_PLUGIN_VERSION;
        $info->author        = '<a href="https://valolink.fi">Valolink</a>';
        $info->requires      = $manifest['requires'] ?? '6.5';
        $info->tested        = $manifest['tested'] ?? '';
        $info->requires_php  = $manifest['requires_php'] ?? '8.2';
        $info->download_link = $manifest['download_url'] ?? '';
        $info->last_updated  = $manifest['last_updated'] ?? '';
        $info->sections      = [
            'description' => 'Internal Valolink agency plugin.',
            'changelog'   => $manifest['changelog'] ?? '',
        ];

        return $info;
    }

    public function clear_cache(object $upgrader, array $options): void
    {
        if (
            $options['action'] === 'update' &&
            $options['type'] === 'plugin' &&
            isset($options['plugins']) &&
            in_array(VALOLINK_PLUGIN_BASENAME, (array) $options['plugins'], true)
        ) {
            delete_transient(self::TRANSIENT_KEY);
        }
    }

    private function fetch_manifest(): ?array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $url = trailingslashit($this->manifest_base_url()) . 'valolink-plugin.json';

        $response = wp_remote_get($url, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; Valolink-Updater',
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['version'])) {
            return null;
        }

        set_transient(self::TRANSIENT_KEY, $body, self::CACHE_TTL);
        return $body;
    }

    private function manifest_base_url(): string
    {
        return rtrim($this->settings->get('enginelink_url', ''), '/');
    }
}
