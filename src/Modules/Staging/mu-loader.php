<?php
/**
 * Plugin Name: Valolink Staging Loader
 * Description: Auto-installed by valolink-plugin. Filters active_plugins so configured plugins do not load when staging is active. Safe if the main plugin is missing.
 * Version:     1.0
 *
 * Do not edit by hand — overwritten on the next valolink-plugin activation.
 */

if (!defined('ABSPATH')) {
    return;
}

add_filter('option_active_plugins', static function ($plugins) {
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

    // Force flag short-circuits detector; otherwise load the detector class lazily.
    $force = !empty($config['force_staging']);
    if (!$force) {
        $autoloader = WP_PLUGIN_DIR . '/valolink-plugin/src/Autoloader.php';
        if (!file_exists($autoloader)) {
            return $plugins;
        }
        require_once $autoloader;
        if (!class_exists('Valolink\\Plugin\\Autoloader')) {
            return $plugins;
        }
        \Valolink\Plugin\Autoloader::register();
        if (!class_exists('Valolink\\Plugin\\Modules\\Staging\\StagingDetector')) {
            return $plugins;
        }
        if (!\Valolink\Plugin\Modules\Staging\StagingDetector::is_staging()) {
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
}, 10, 1);
