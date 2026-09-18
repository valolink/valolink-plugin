<?php
/**
 * Plugin Name: Valolink Staging Loader
 * Description: Auto-installed by valolink-plugin. Filters active_plugins so configured plugins do not load on a clone of the declared production site. Safe if the main plugin is missing.
 * Version:     2.0
 *
 * Do not edit by hand — overwritten on the next valolink-plugin activation and
 * refreshed from wp-admin whenever the plugin's copy differs.
 */

if (!defined('ABSPATH')) {
    return;
}

add_filter('option_active_plugins', static function ($plugins) {
    // Runs on every request during the earliest bootstrap phase, before any
    // plugin loads. It MUST NOT be able to fatal the site: every failure fails
    // open and returns the plugin list untouched. The worst case is a staging
    // copy briefly loading a plugin it should have hidden — never a broken
    // production site.
    try {
        if (!is_array($plugins) || empty($plugins)) {
            return $plugins;
        }

        $settings = get_option('valolink_settings');
        if (!is_array($settings)) {
            return $plugins;
        }

        $staging = $settings['modules']['staging'] ?? [];
        if (!is_array($staging) || empty($staging['enabled'])) {
            return $plugins;
        }

        $config = $staging['settings'] ?? [];
        if (!is_array($config) || empty($config['disable_plugins_enabled'])) {
            return $plugins;
        }

        $disabled = (array) ($config['disabled_plugins'] ?? []);
        if (empty($disabled)) {
            return $plugins;
        }

        // The decision. Mirrors StagingDetector::decision() — force flag, then
        // "does the home host still hash to the declared production host?" —
        // kept inline so this file needs no classes. tests/staging-decision.php
        // holds the two to the same answers.
        if (empty($config['force_staging'])) {
            $declared = $config['production_host_hash'] ?? '';
            if (!is_string($declared) || !preg_match('/^[0-9a-f]{64}$/', $declared)) {
                return $plugins; // Undeclared: nothing is ever disabled.
            }

            $home = strtolower(trim((string) get_option('home', '')));
            if (str_contains($home, '://')) {
                $home = (string) (parse_url($home, PHP_URL_HOST) ?? '');
            } else {
                $home = explode('/', $home)[0];
            }
            $home = preg_replace('/:\d+$/', '', $home) ?? $home;
            $home = rtrim($home, '.');
            if (str_starts_with($home, 'www.')) {
                $home = substr($home, 4);
            }
            // An empty home host cannot be the declared production site, so it
            // falls through to staging — the same answer StagingDetector gives.
            if ($home !== '' && hash_equals($declared, hash('sha256', $home))) {
                return $plugins; // This is the declared production site.
            }
        }

        return array_values(array_filter($plugins, static function ($p) use ($disabled) {
            // Never disable valolink-plugin itself — that would take the settings page with it.
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
