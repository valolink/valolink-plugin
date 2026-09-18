<?php
/**
 * Plugin Name: Valolink Staging Loader
 * Description: Auto-installed by valolink-plugin. Filters active_plugins so configured plugins do not load when staging is active. Safe if the main plugin is missing.
 * Version:     1.1
 *
 * Do not edit by hand — overwritten on the next valolink-plugin activation and
 * refreshed from wp-admin whenever the plugin's copy differs.
 */

if (!defined('ABSPATH')) {
    return;
}

add_filter('option_active_plugins', static function ($plugins) {
    // This closure runs on every request during the earliest bootstrap phase
    // (before regular plugins load). It MUST NOT be able to fatal the site:
    // any failure fails open, returning the plugin list untouched. The worst
    // case is a staging site briefly loading a plugin it should have hidden —
    // never a broken production site.
    try {
    if (!is_array($plugins) || empty($plugins)) {
        return $plugins;
    }

    $settings = get_option('valolink_settings');
    if (!is_array($settings)) {
        return $plugins;
    }

    $staging = $settings['modules']['staging'] ?? [];
    if (empty($staging['enabled'])) {
        return $plugins;
    }

    $config = $staging['settings'] ?? [];
    if (empty($config['disable_plugins_enabled'])) {
        return $plugins;
    }

    $disabled = (array) ($config['disabled_plugins'] ?? []);
    if (empty($disabled)) {
        return $plugins;
    }

    // The force flag needs no classes; otherwise load the detector lazily and
    // ask it the same question StagingModule asks (StagingDetector::is_staging_with),
    // so plugin disabling and every other staging feature agree on every host.
    if (empty($config['force_staging'])) {
        $autoloader = WP_PLUGIN_DIR . '/valolink-plugin/src/Autoloader.php';
        if (!file_exists($autoloader)) {
            return $plugins;
        }
        require_once $autoloader;
        if (!class_exists('Valolink\\Plugin\\Autoloader')) {
            return $plugins;
        }
        \Valolink\Plugin\Autoloader::register();
        if (!class_exists('Valolink\\Plugin\\Modules\\Staging\\StagingDetector')
            || !method_exists('Valolink\\Plugin\\Modules\\Staging\\StagingDetector', 'is_staging_with')) {
            return $plugins;
        }
        if (!\Valolink\Plugin\Modules\Staging\StagingDetector::is_staging_with(is_array($config) ? $config : [])) {
            return $plugins;
        }
    }

    return array_values(array_filter($plugins, static function ($p) use ($disabled) {
        // Never disable valolink-plugin itself — that'd take the settings page with it.
        if (is_string($p) && str_starts_with($p, 'valolink-plugin/')) {
            return true;
        }
        return !in_array($p, $disabled, true);
    }));
    } catch (\Throwable $e) {
        error_log('[valolink-plugin] staging mu-loader failed, leaving active_plugins untouched: ' . $e->getMessage());
        return $plugins;
    }
}, 10, 1);
