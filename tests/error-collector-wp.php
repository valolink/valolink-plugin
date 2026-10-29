<?php
/**
 * The after-update watch's two reads, against a real site (MySQL/MariaDB —
 * the collector's upsert is MySQL syntax):
 *   wp --path=/path/to/wp eval-file tests/error-collector-wp.php
 *
 * Writes a few rows into {prefix}valolink_php_errors under a test hour and
 * removes them again; reads order counts (counts only).
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run with wp eval-file\n");
    exit(2);
}
foreach (['ErrorCollector', 'OrderCounts'] as $cls) {
    if (!class_exists("Valolink\\Plugin\\Modules\\EngineLink\\$cls")) {
        require dirname(__DIR__) . "/src/Modules/EngineLink/$cls.php";
    }
}
use Valolink\Plugin\Modules\EngineLink\ErrorCollector;
use Valolink\Plugin\Modules\EngineLink\OrderCounts;

$GLOBALS['failures'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
    if (!$ok) {
        $GLOBALS['failures']++;
    }
}

// Scrubbing.
$s = ErrorCollector::scrub("Failed for jane.doe@example.com from 192.168.10.20 at https://shop.fi/x?key=abc&order=1234567 \n id 98765432");
check('scrub: email', !str_contains($s, 'jane.doe') && str_contains($s, '[email]'), $s);
check('scrub: ip', !str_contains($s, '192.168') && str_contains($s, '[ip]'), $s);
check('scrub: query string', !str_contains($s, 'key=abc'), $s);
check('scrub: long number', !str_contains($s, '98765432'), $s);
check('scrub: one line', !str_contains($s, "\n"), $s);
check('scrub: path relative', ErrorCollector::scrub('in ' . ABSPATH . 'wp-content/plugins/x/y.php') === 'in wp-content/plugins/x/y.php');

// Two "requests": warnings counted in memory, one flush each.
global $wpdb;
$table  = ErrorCollector::table_name();
$before = time();
$tag    = 'vltest-' . bin2hex(random_bytes(4));
for ($req = 0; $req < 2; $req++) {
    $c = new ErrorCollector();
    $prev = set_error_handler(null);
    set_error_handler($prev);
    // Drive the handler directly, as PHP would.
    $c->handle(E_WARNING, "Undefined array key \"$tag\" for bob@example.org", ABSPATH . 'wp-content/plugins/demo/demo.php', 10);
    $c->handle(E_WARNING, "Undefined array key \"$tag\" for bob@example.org", ABSPATH . 'wp-content/plugins/demo/demo.php', 10);
    $c->handle(E_USER_ERROR, "$tag fatal-ish", ABSPATH . 'wp-content/themes/t/functions.php', 5);
    $c->handle(E_DEPRECATED, "$tag deprecated is ignored", __FILE__, 1);
    $c->handle(E_NOTICE, "$tag notice is ignored", __FILE__, 1);
    $c->shutdown();
}
$rows = $wpdb->get_results($wpdb->prepare("SELECT level, file, message, count FROM $table WHERE message LIKE %s ORDER BY level", '%' . $wpdb->esc_like($tag) . '%'), ARRAY_A);
check('two groups stored (warning + fatal), notices and deprecations ignored', count($rows) === 2, json_encode($rows));
$warn = array_values(array_filter($rows, fn ($r) => $r['level'] === 'warning'))[0] ?? null;
check('warning counted 4 times over two requests in one row', $warn && (int) $warn['count'] === 4, json_encode($warn));
check('stored file is relative', $warn && $warn['file'] === 'wp-content/plugins/demo/demo.php', json_encode($warn));
check('stored message scrubbed', $warn && !str_contains($warn['message'], 'bob@'), json_encode($warn));

$report = ErrorCollector::report($before - 3600);
$mine   = array_values(array_filter($report['groups'], fn ($g) => str_contains($g['message'], $tag)));
check('report lists the groups, fatal first', count($mine) === 2 && $mine[0]['level'] === 'fatal', json_encode($mine));
check('report has first/last seen', isset($mine[0]['first_seen'], $mine[0]['last_seen']));
check('report has hourly totals', count($report['hours']) > 0);

$wpdb->query($wpdb->prepare("DELETE FROM $table WHERE message LIKE %s", '%' . $wpdb->esc_like($tag) . '%'));

// Orders: shape only.
$orders = OrderCounts::report(time() - 7 * DAY_IN_SECONDS);
check('order counts: woocommerce flag', is_bool($orders['woocommerce']));
if ($orders['woocommerce']) {
    $h = $orders['hours'][0] ?? null;
    check('order counts: hour rows carry counts only', $h === null || array_keys($h) === ['hour', 'created', 'paid', 'pending', 'failed'], json_encode($h));
    $sum = array_sum(array_map(fn ($r) => $r['created'], $orders['hours']));
    echo "     orders in 7 days: $sum over " . count($orders['hours']) . " hours\n";
}

echo $GLOBALS['failures'] ? "{$GLOBALS['failures']} failed\n" : "all ok\n";
