<?php
/**
 * Plugin Name:       Valolink Plugin
 * Plugin URI:        https://valolink.fi
 * Description:       Modular utility, security, and management toolkit for Valolink-managed WordPress sites.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Valolink
 * Author URI:        https://valolink.fi
 * License:           GPL-2.0-or-later
 * Text Domain:       valolink-plugin
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('VALOLINK_PLUGIN_VERSION', '0.2.0');
define('VALOLINK_PLUGIN_FILE', __FILE__);
define('VALOLINK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VALOLINK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VALOLINK_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('VALOLINK_MIN_PHP', '8.2');
define('VALOLINK_MIN_WP', '6.5');

// GitHub repo for wp-admin updates, as 'owner/repo'. Set to 'OWNER/REPO' to disable the updater.
define('VALOLINK_PLUGIN_GITHUB_REPO', 'valolink/valolink-plugin');

require_once VALOLINK_PLUGIN_DIR . 'src/Autoloader.php';
\Valolink\Plugin\Autoloader::register();

register_activation_hook(__FILE__, [\Valolink\Plugin\Plugin::class, 'on_activate']);
register_deactivation_hook(__FILE__, [\Valolink\Plugin\Plugin::class, 'on_deactivate']);

if (!\Valolink\Plugin\EnvCheck::passes()) {
    add_action('admin_notices', [\Valolink\Plugin\EnvCheck::class, 'render_notice']);
    return;
}

add_action('plugins_loaded', [\Valolink\Plugin\Plugin::class, 'boot'], 5);
