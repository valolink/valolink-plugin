<?php
/**
 * EngineLink's update route against a real site that has updates waiting:
 *   wp --path=/path/to/wp eval-file tests/site-update-wp.php [plugin-slug] [theme-slug] [core]
 *
 * WRITES: it updates what it is given (a scratch site only — localwp).
 * Without arguments it only checks the refusals and the offers, and writes
 * nothing. Each update is checked for the version moving, the active state
 * kept, the update list still filled afterwards and no .maintenance left.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run with wp eval-file\n");
    exit(2);
}
if (!class_exists(\Valolink\Plugin\Modules\EngineLink\SiteUpdate::class)) {
    require dirname(__DIR__) . '/src/Autoloader.php';
    \Valolink\Plugin\Autoloader::register();
}
if (!defined('VALOLINK_PLUGIN_FILE')) {
    define('VALOLINK_PLUGIN_FILE', dirname(__DIR__) . '/valolink-plugin.php');
}
use Valolink\Plugin\Modules\EngineLink\SiteUpdate;

$GLOBALS['failures'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
    if (!$ok) {
        $GLOBALS['failures']++;
    }
}
function shown(mixed $r): string
{
    return is_wp_error($r) ? $r->get_error_code() . ': ' . $r->get_error_message() : (string) json_encode($r);
}

[$plugin, $theme, $core] = [$args[0] ?? '', $args[1] ?? '', ($args[2] ?? '') === 'core'];

$r = SiteUpdate::run('database', 'x');
check('an unknown type is a 400', is_wp_error($r) && $r->get_error_data()['status'] === 400, shown($r));
$r = SiteUpdate::run('plugin', 'no-such-plugin-here');
check('a plugin that is not installed is a 404', is_wp_error($r) && $r->get_error_data()['status'] === 404, shown($r));
$r = SiteUpdate::run('theme', 'no-such-theme-here');
check('a theme that is not installed is a 404', is_wp_error($r) && $r->get_error_data()['status'] === 404, shown($r));
$r = SiteUpdate::run('plugin', 'https://example.com/evil.zip');
check('a URL is not a plugin', is_wp_error($r) && $r->get_error_data()['status'] === 404, shown($r));

\WP_Upgrader::create_lock('valolink_site_update', 60);
$r = SiteUpdate::run('theme', get_stylesheet());
check('a second update while one runs is a 409', is_wp_error($r) && $r->get_error_code() === 'valolink_update_running', shown($r));
\WP_Upgrader::release_lock('valolink_site_update');

if ($plugin !== '') {
    $r = SiteUpdate::run('plugin', $plugin);
    check("plugin $plugin updated", is_array($r) && $r['updated'] && version_compare($r['to'], $r['from'], '>'), shown($r));
    if (is_array($r)) {
        $again = SiteUpdate::run('plugin', $r['slug']);
        check('and is up to date when asked again', is_array($again) && !$again['updated'] && $again['reason'] === 'up_to_date', shown($again));
        check('the update list is filled again', is_object(get_site_transient('update_plugins')) && isset(get_site_transient('update_plugins')->last_checked));
    }
}
if ($theme !== '') {
    $r = SiteUpdate::run('theme', $theme);
    check("theme $theme updated", is_array($r) && $r['updated'] && version_compare($r['to'], $r['from'], '>'), shown($r));
}
if ($core) {
    $before = SiteUpdate::wp_version();
    $r      = SiteUpdate::run('core', '');
    check('core updated within its branch', is_array($r) && $r['updated'] && $r['from'] === $before
        && implode('.', array_slice(explode('.', $r['to']), 0, 2)) === implode('.', array_slice(explode('.', $before), 0, 2)), shown($r));
    check('version.php says the same', is_array($r) && SiteUpdate::wp_version() === $r['to'], SiteUpdate::wp_version());
}
check('no .maintenance left behind', !file_exists(ABSPATH . '.maintenance'));

echo $GLOBALS['failures'] === 0 ? "\nall ok\n" : "\n{$GLOBALS['failures']} failed\n";
exit($GLOBALS['failures'] === 0 ? 0 : 1);
