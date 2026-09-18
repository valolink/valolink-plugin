<?php
/**
 * Plain-PHP test of the staging decision, no WordPress needed:
 *   php tests/staging-decision.php
 *
 * Exercises StagingDetector::decision() and the mu-loader's inline copy of the
 * same decision, and asserts they agree on every case.
 */
declare(strict_types=1);

// --- WordPress stand-ins -----------------------------------------------------
$GLOBALS['wp_options'] = [];
function get_option(string $key, $default = false) { return $GLOBALS['wp_options'][$key] ?? $default; }
$GLOBALS['captured_filter'] = null;
function add_filter(string $hook, callable $cb, int $prio = 10, int $args = 1): void { $GLOBALS['captured_filter'] = $cb; }
define('ABSPATH', '/nonexistent/');

require dirname(__DIR__) . '/src/Autoloader.php';
\Valolink\Plugin\Autoloader::register();
use Valolink\Plugin\Modules\Staging\StagingDetector;

require dirname(__DIR__) . '/src/Modules/Staging/mu-loader.php';
$loader = $GLOBALS['captured_filter'];
if (!is_callable($loader)) { fwrite(STDERR, "mu-loader did not register its filter\n"); exit(1); }

// --- helpers -----------------------------------------------------------------
$PLUGINS  = ['woocommerce/woocommerce.php', 'paytrail-for-woocommerce/plugin.php', 'valolink-plugin/valolink-plugin.php'];
$DISABLED = ['paytrail-for-woocommerce/plugin.php'];

function loader_disables(callable $loader, array $staging_settings, array $plugins, array $disabled): bool {
    $GLOBALS['wp_options']['valolink_settings'] = ['modules' => ['staging' => [
        'enabled'  => true,
        'settings' => $staging_settings + ['disable_plugins_enabled' => true, 'disabled_plugins' => $disabled],
    ]]];
    $result = $loader($plugins);
    return $result !== $plugins;
}

$declare = static fn (string $host): array => StagingDetector::declaration_for($host);

$cases = [
    // [home URL, settings, expected staging, expected reason, description]
    ['https://kuumalahde.fi',              [],                                                     false, 'undeclared', 'undeclared: never staging, never disables'],
    ['https://staging2.energiatuote.fi',   [],                                                     false, 'undeclared', 'undeclared clone: no safety (the notice is the safety)'],
    ['https://kuumalahde.fi',              $declare('kuumalahde.fi'),                              false, 'match',      'declared apex, home apex'],
    ['https://www.kuumalahde.fi',          $declare('kuumalahde.fi'),                              false, 'match',      'www on production is still production'],
    ['https://kuumalahde.fi',              $declare('https://www.kuumalahde.fi/'),                 false, 'match',      'declaration given as a URL with www'],
    ['https://KUUMALAHDE.FI:443',          $declare('kuumalahde.fi'),                              false, 'match',      'case and port ignored'],
    ['https://staging.kuumalahde.fi',      $declare('kuumalahde.fi'),                              true,  'mismatch',   'clone at staging.'],
    ['https://staging2.energiatuote.fi',   $declare('energiatuote.fi'),                            true,  'mismatch',   'the failing case from 2026-09-18'],
    ['https://kopio.example.fi',           $declare('example.fi'),                                 true,  'mismatch',   'any other host'],
    ['http://localhost:8888',              $declare('kuumalahde.fi'),                              true,  'mismatch',   'local mirror'],
    ['https://kuumalahde.fi',              $declare('kuumalahde.fi') + ['force_staging' => true],  true,  'forced',     'force wins on production'],
    ['https://kuumalahde.fi',              ['force_staging' => true],                              true,  'forced',     'force wins even undeclared'],
    ['https://kuumalahde.fi',              ['production_host_hash' => 'not-a-hash', 'production_host' => 'kuumalahde.fi'], false, 'undeclared', 'garbage hash counts as undeclared'],
    ['https://staging2.energiatuote.fi',   $declare('energiatuote.fi') + ['production_host' => 'staging2.energiatuote.fi'], true, 'mismatch', 'plaintext rewritten by search-replace; the hash still decides'],
    ['',                                   $declare('kuumalahde.fi'),                              true,  'mismatch',   'no home option at all: fail towards staging'],
];

$fail = 0;
foreach ($cases as [$home, $settings, $expected, $reason, $why]) {
    $GLOBALS['wp_options']['home'] = $home;
    $d = StagingDetector::decision($settings);
    $ok = $d['staging'] === $expected && $d['reason'] === $reason;
    $loader_says = loader_disables($loader, $settings, $PLUGINS, $DISABLED);
    $agree = $loader_says === $expected;
    printf("%s %s  %-34s %-9s %-8s loader=%s  %s\n",
        $ok ? 'ok  ' : 'FAIL', $agree ? 'agree   ' : 'DISAGREE',
        $home === '' ? '(no home)' : $home, var_export($d['staging'], true), $d['reason'], var_export($loader_says, true), $why);
    if (!$ok || !$agree) { $fail++; }
}

// The 1.0 loader (no try/catch, calls StagingDetector::is_staging()) is still
// installed on sites the plugin updates over. It must keep working.
define('WP_PLUGIN_DIR', dirname(dirname(__DIR__)));           // <this>/valolink-plugin/src/Autoloader.php
if (!is_file(WP_PLUGIN_DIR . '/valolink-plugin/src/Autoloader.php')) { fwrite(STDERR, "fixture needs the repo directory to be named valolink-plugin\n"); exit(1); }
$GLOBALS['captured_filter'] = null;
require __DIR__ . '/fixtures/mu-loader-1.0.php';
$old_loader = $GLOBALS['captured_filter'];
foreach ($cases as [$home, $settings, $expected, $reason, $why]) {
    $GLOBALS['wp_options']['home'] = $home;
    $GLOBALS['wp_options']['valolink_settings'] = ['modules' => ['staging' => ['enabled' => true, 'settings' => $settings + ['disable_plugins_enabled' => true, 'disabled_plugins' => $DISABLED]]]];
    $says = $old_loader($PLUGINS) !== $PLUGINS;
    printf("%s old 1.0 loader agrees  %-34s %s\n", $says === $expected ? 'ok  ' : 'FAIL', $home === '' ? '(no home)' : $home, $why);
    if ($says !== $expected) { $fail++; }
}

// The loader must fail open on garbage.
$GLOBALS['wp_options']['valolink_settings'] = 'corrupt';
$r = $loader($PLUGINS); $fail += ($r === $PLUGINS) ? 0 : 1; printf("%s loader fails open on a corrupt option\n", $r === $PLUGINS ? 'ok  ' : 'FAIL');
$GLOBALS['wp_options']['valolink_settings'] = ['modules' => ['staging' => ['enabled' => true, 'settings' => ['force_staging' => true, 'disable_plugins_enabled' => true, 'disabled_plugins' => ['valolink-plugin/valolink-plugin.php', 'paytrail-for-woocommerce/plugin.php']]]]];
$r = $loader($PLUGINS); $keeps_self = in_array('valolink-plugin/valolink-plugin.php', $r, true) && !in_array('paytrail-for-woocommerce/plugin.php', $r, true);
$fail += $keeps_self ? 0 : 1; printf("%s loader never disables valolink-plugin itself\n", $keeps_self ? 'ok  ' : 'FAIL');

// normalise_host edge cases
foreach ([['WWW.Example.FI.', 'example.fi'], ['https://www.example.fi:8443/path', 'example.fi'], ['shop.example.fi', 'shop.example.fi'], ['  ', ''], ['http://', '']] as [$in, $want]) {
    $got = StagingDetector::normalise_host($in); $fail += $got === $want ? 0 : 1;
    printf("%s normalise_host(%s) = %s\n", $got === $want ? 'ok  ' : 'FAIL', var_export($in, true), var_export($got, true));
}

printf("\n%d failures\n", $fail);
exit($fail ? 1 : 0);
