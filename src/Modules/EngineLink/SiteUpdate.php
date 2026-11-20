<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\EngineLink;

/**
 * POST /enginelink/v1/update: EngineLink's Update buttons on sites it has no
 * other way onto (other hosts: no SSH, no streamer). One installed plugin,
 * one installed theme or WordPress itself, updated with WordPress's own
 * upgraders to the package WordPress's own update check offers — what the
 * Update link in wp-admin would install. The request names what to update,
 * never where from: no URL, no zip, nothing installed, activated, deactivated
 * or deleted, so a leaked EngineLink key can at most trigger official updates.
 *
 * One item per request (slow hosts, PHP's time limit) and one request at a
 * time (a lock). Core takes the newest release of its own branch unless
 * `major` is set. Plugins and themes go through bulk_upgrade() like wp-admin's
 * own AJAX updates: maintenance mode for the seconds it takes, and an active
 * plugin stays active.
 */
final class SiteUpdate
{
    private const LOCK = 'valolink_site_update';

    /**
     * @return array{type: string, slug: string, updated: bool, from: string, to: string, reason?: string}|\WP_Error
     */
    public static function run(string $type, string $slug, bool $major = false): array|\WP_Error
    {
        if (!in_array($type, ['plugin', 'theme', 'core'], true)) {
            return new \WP_Error('valolink_update_type', 'type must be plugin, theme or core.', ['status' => 400]);
        }
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            return new \WP_Error('valolink_file_mods_disallowed', 'File modifications are disabled here (DISALLOW_FILE_MODS) — a staging copy, or locked down on purpose.', ['status' => 409]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Unattended: the filesystem must be writable without credentials.
        if (get_filesystem_method() !== 'direct') {
            return new \WP_Error('valolink_filesystem', 'WordPress cannot write its files directly here (it would ask for FTP credentials); update in wp-admin.', ['status' => 409]);
        }

        // This plugin has its own path: it also refreshes the staging mu-loader.
        $basename = $type === 'plugin' ? self::plugin_basename_for($slug) : null;
        if ($basename !== null && $basename === plugin_basename(VALOLINK_PLUGIN_FILE)) {
            $result = SelfUpdate::run();
            return is_wp_error($result) ? $result : ['type' => 'plugin', 'slug' => $basename] + $result;
        }

        if (!\WP_Upgrader::create_lock(self::LOCK, 15 * MINUTE_IN_SECONDS)) {
            return new \WP_Error('valolink_update_running', 'Another update started from EngineLink is still running on this site.', ['status' => 409]);
        }
        // A dropped connection must not stop PHP halfway through unpacking.
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            return match ($type) {
                'plugin' => self::plugin($slug, $basename),
                'theme'  => self::theme($slug),
                'core'   => self::core($major),
            };
        } finally {
            \WP_Upgrader::release_lock(self::LOCK);
        }
    }

    /**
     * The newest core release on offer: of the running branch (7.1.x), or of
     * any branch with $major. Read from the update_core transient as it is;
     * the caller refreshes it when it wants a fresh answer.
     */
    public static function core_offer(bool $major): ?object
    {
        $current = self::wp_version();
        $branch  = self::branch($current);
        $data    = get_site_transient('update_core');
        $best    = null;
        foreach (is_object($data) && is_array($data->updates ?? null) ? $data->updates : [] as $offer) {
            $version = (string) ($offer->version ?? $offer->current ?? '');
            if (!in_array($offer->response ?? '', ['upgrade', 'autoupdate'], true) || $version === '') {
                continue;
            }
            if (!version_compare($version, $current, '>') || (!$major && self::branch($version) !== $branch)) {
                continue;
            }
            $bestVersion = $best ? (string) ($best->version ?? $best->current) : '';
            $newer       = $best === null || version_compare($version, $bestVersion, '>');
            // The same release is offered per language; take the site's own.
            $sameButOurs = $best !== null && $version === $bestVersion && ($offer->locale ?? '') === get_locale();
            if ($newer || $sameButOurs) {
                $best = $offer;
            }
        }
        return $best;
    }

    /** The version from version.php, not one a plugin has masked. */
    public static function wp_version(): string
    {
        require ABSPATH . WPINC . '/version.php';
        /** @var string $wp_version */
        return (string) $wp_version;
    }

    /** @return array<string, mixed>|\WP_Error */
    private static function plugin(string $slug, ?string $basename): array|\WP_Error
    {
        if ($basename === null) {
            return new \WP_Error('valolink_not_installed', "No plugin \"$slug\" is installed here.", ['status' => 404]);
        }
        $file = WP_PLUGIN_DIR . '/' . $basename;
        $from = (string) get_plugin_data($file, false, false)['Version'];

        // A fresh check, so the offer is today's.
        wp_clean_plugins_cache(true);
        wp_update_plugins();
        $offer = get_site_transient('update_plugins')->response[$basename] ?? null;
        if (!is_object($offer) || empty($offer->new_version)) {
            return ['type' => 'plugin', 'slug' => $basename, 'updated' => false, 'from' => $from, 'to' => $from, 'reason' => 'up_to_date'];
        }
        if (empty($offer->package)) {
            return new \WP_Error('valolink_no_package', "WordPress knows of {$offer->new_version} but has no package for it — a paid plugin whose licence is not active on this site?", ['status' => 409]);
        }

        $network = is_multisite() && is_plugin_active_for_network($basename);
        $active  = $network || is_plugin_active($basename);

        $skin   = new \WP_Ajax_Upgrader_Skin();
        $result = (new \Plugin_Upgrader($skin))->bulk_upgrade([$basename]);
        $error  = self::failure($skin, $result, $basename);

        if ($active && is_file($file)) {
            SelfUpdate::reactivate($basename, $network);
        }
        // The upgrader empties the update list; the next /status would report
        // no updates at all until WordPress checked again on its own.
        wp_update_plugins();

        $to = is_file($file) ? (string) get_plugin_data($file, false, false)['Version'] : $from;
        if ($error !== null) {
            return new \WP_Error('valolink_update_failed', $error, ['status' => 500, 'from' => $from, 'to' => $to]);
        }
        return ['type' => 'plugin', 'slug' => $basename, 'updated' => $to !== $from, 'from' => $from, 'to' => $to];
    }

    /** @return array<string, mixed>|\WP_Error */
    private static function theme(string $slug): array|\WP_Error
    {
        $theme = wp_get_theme($slug);
        if ($slug === '' || !$theme->exists()) {
            return new \WP_Error('valolink_not_installed', "No theme \"$slug\" is installed here.", ['status' => 404]);
        }
        $from = (string) $theme->get('Version');

        wp_clean_themes_cache(true);
        wp_update_themes();
        $offer = get_site_transient('update_themes')->response[$slug] ?? null;
        if (!is_array($offer) || empty($offer['new_version'])) {
            return ['type' => 'theme', 'slug' => $slug, 'updated' => false, 'from' => $from, 'to' => $from, 'reason' => 'up_to_date'];
        }
        if (empty($offer['package'])) {
            return new \WP_Error('valolink_no_package', "WordPress knows of {$offer['new_version']} but has no package for it — a paid theme whose licence is not active on this site?", ['status' => 409]);
        }

        $skin   = new \WP_Ajax_Upgrader_Skin();
        $result = (new \Theme_Upgrader($skin))->bulk_upgrade([$slug]);
        $error  = self::failure($skin, $result, $slug);

        wp_clean_themes_cache(true);
        wp_update_themes();

        $after = wp_get_theme($slug);
        $to    = $after->exists() ? (string) $after->get('Version') : $from;
        if ($error !== null) {
            return new \WP_Error('valolink_update_failed', $error, ['status' => 500, 'from' => $from, 'to' => $to]);
        }
        return ['type' => 'theme', 'slug' => $slug, 'updated' => $to !== $from, 'from' => $from, 'to' => $to];
    }

    /** @return array<string, mixed>|\WP_Error */
    private static function core(bool $major): array|\WP_Error
    {
        $from = self::wp_version();

        wp_version_check([], true);
        $offer = self::core_offer($major);
        if ($offer === null) {
            // Say so when only a new branch is on offer, so nobody reads "up to date" into it.
            $beyond = $major ? null : self::core_offer(true);
            return ['type' => 'core', 'slug' => 'wordpress', 'updated' => false, 'from' => $from, 'to' => $from, 'reason' => $beyond ? 'major_only' : 'up_to_date']
                + ($beyond ? ['available' => (string) ($beyond->version ?? $beyond->current)] : []);
        }

        $skin   = new \WP_Ajax_Upgrader_Skin();
        $result = (new \Core_Upgrader($skin))->upgrade($offer, [
            // As wp-admin's own core update does.
            'allow_relaxed_file_ownership' => isset($offer->new_files) && !$offer->new_files,
        ]);
        wp_version_check([], true);

        if (is_wp_error($result) || !is_string($result) || $result === '') {
            $errors = $skin->get_errors()->get_error_messages();
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            }
            $message = $errors !== [] ? implode(' ', array_unique($errors)) : 'The upgrade did not complete.';
            return new \WP_Error('valolink_update_failed', $message, ['status' => 500, 'from' => $from, 'to' => self::wp_version()]);
        }
        return ['type' => 'core', 'slug' => 'wordpress', 'updated' => $result !== $from, 'from' => $from, 'to' => $result];
    }

    /**
     * "contact-form-7/wp-contact-form-7.php" as /status reports it, or the bare
     * "contact-form-7" (WP-CLI's name) → the installed plugin's basename.
     */
    private static function plugin_basename_for(string $slug): ?string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $installed = array_keys(get_plugins());
        if (in_array($slug, $installed, true)) {
            return $slug;
        }
        foreach ($installed as $basename) {
            $bare = str_contains($basename, '/') ? dirname($basename) : basename($basename, '.php');
            if ($bare === $slug) {
                return $basename;
            }
        }
        return null;
    }

    /** What went wrong in a bulk_upgrade() of one item, read as wp-admin's AJAX update reads it; null when it went through. */
    private static function failure(\WP_Ajax_Upgrader_Skin $skin, mixed $result, string $key): ?string
    {
        $errors = $skin->get_errors()->get_error_messages();
        if (is_wp_error($skin->result)) {
            $errors[] = $skin->result->get_error_message();
        }
        $own = is_array($result) ? ($result[$key] ?? null) : null;
        if (is_wp_error($own)) {
            $errors[] = $own->get_error_message();
        }
        if ($errors !== []) {
            return implode(' ', array_unique($errors));
        }
        // true: WordPress found nothing to do after all; the versions tell.
        return $own === true || is_array($own) ? null : 'The upgrade did not complete.';
    }

    private static function branch(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }
}
