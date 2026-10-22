<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\EngineLink;

/**
 * POST /enginelink/v1/self-update: EngineLink's "Update plugin" button.
 * Updates this plugin, and nothing else, to its latest GitHub release —
 * the same package the Updater offers in wp-admin. EngineLink has no other
 * way onto sites on other hosts (no SSH, no streamer), so without this they
 * waited for someone to log into wp-admin. A leaked EngineLink key can at
 * most trigger an update to an official release.
 *
 * WordPress's Plugin_Upgrader deactivates an active plugin outside cron and
 * leaves reactivating it to the browser's next page; a REST call has none,
 * so the plugin goes back into the active list here, without being loaded a
 * second time in this request (the new files load on the next one).
 */
final class SelfUpdate
{
    /**
     * @return array{updated: bool, from: string, to: string, reason?: string}|\WP_Error
     */
    public static function run(): array|\WP_Error
    {
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            return new \WP_Error('valolink_file_mods_disallowed', 'File modifications are disabled here (DISALLOW_FILE_MODS) — a staging copy, or locked down on purpose.', ['status' => 409]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Unattended: the filesystem must be writable without credentials.
        if (get_filesystem_method() !== 'direct') {
            return new \WP_Error('valolink_filesystem', 'WordPress cannot write its files directly here (it would ask for FTP credentials); update in wp-admin.', ['status' => 409]);
        }

        $basename = plugin_basename(VALOLINK_PLUGIN_FILE);
        $from     = VALOLINK_PLUGIN_VERSION;

        // A fresh check: the Updater's GitHub cache and WordPress's update
        // list both go, so the latest release is the one offered.
        delete_transient('valolink_plugin_updater_' . md5(VALOLINK_PLUGIN_GITHUB_REPO));
        delete_site_transient('update_plugins');
        wp_update_plugins();
        $offer = get_site_transient('update_plugins')->response[$basename] ?? null;
        if (!is_object($offer) || empty($offer->new_version)) {
            return ['updated' => false, 'from' => $from, 'to' => $from, 'reason' => 'up_to_date'];
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $network = is_multisite() && is_plugin_active_for_network($basename);
        $active  = $network || is_plugin_active($basename);

        $skin   = new \WP_Ajax_Upgrader_Skin();
        $result = (new \Plugin_Upgrader($skin))->upgrade($basename);

        if ($active) {
            self::reactivate($basename, $network);
        }
        if ($result !== true) {
            $errors = $skin->get_error_messages();
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            }
            $message = $errors !== [] ? implode(' ', array_unique($errors)) : 'The upgrade did not complete.';
            return new \WP_Error('valolink_update_failed', $message, ['status' => 500, 'from' => $from, 'to' => (string) $offer->new_version]);
        }

        // The staging mu-loader follows the plugin's copy; otherwise it only
        // does on the next wp-admin visit, which sites elsewhere rarely get.
        try {
            \Valolink\Plugin\Modules\Staging\MuPluginInstaller::install();
        } catch (\Throwable) {
            // admin_init installs it later.
        }

        return ['updated' => true, 'from' => $from, 'to' => (string) $offer->new_version];
    }

    /** Back into the active list, as activate_plugin() would, without including the plugin again. */
    private static function reactivate(string $basename, bool $network): void
    {
        if ($network) {
            $sitewide = (array) get_site_option('active_sitewide_plugins', []);
            $sitewide[$basename] = time();
            update_site_option('active_sitewide_plugins', $sitewide);
            return;
        }
        $active = (array) get_option('active_plugins', []);
        if (!in_array($basename, $active, true)) {
            $active[] = $basename;
            sort($active);
            update_option('active_plugins', $active);
        }
    }
}
