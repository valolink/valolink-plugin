<?php
/**
 * Plain-PHP test of PhpBuild (the php.build_date EngineLink reads), no WordPress needed:
 *   php tests/php-build.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoloader.php';
\Valolink\Plugin\Autoloader::register();
use Valolink\Plugin\Modules\EngineLink\PhpBuild;

$fail = 0;
function check(string $name, $got, $want): void {
    global $fail;
    if ($got === $want) { echo "ok   $name\n"; return; }
    $fail++;
    echo "FAIL $name: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
}

// phpinfo() under a web SAPI: an HTML table (CloudLinux alt-php 8.4.26 LiteSpeed, 2026-10-21).
check('html table', PhpBuild::parse('<tr><td class="e">System </td><td class="v">Linux wp04 </td></tr>' .
    '<tr><td class="e">Build Date </td><td class="v">Oct 21 2026 22:10:32 </td></tr>'), '2026-10-21T22:10:32Z');
// phpinfo() under the CLI: "key => value" text.
check('cli text', PhpBuild::parse("System => Linux hz 6.1.0\nBuild Date => Sep 25 2026 05:13:36\nBuild System => Linux\n"), '2026-09-25T05:13:36Z');
// __DATE__ pads a single-digit day with a space.
check('padded day', PhpBuild::parse('Build Date => Oct  1 2026 08:00:00'), '2026-10-01T08:00:00Z');
check('no build date', PhpBuild::parse('<h1>PHP Version 8.3.36</h1>'), null);
check('garbage month', PhpBuild::parse('Build Date => Xyz 21 2026 22:10:32'), null);

// collect() on this PHP: phpinfo() is available in the CLI, so the source is phpinfo.
$c = PhpBuild::collect();
check('collect source', $c['build_date_source'], 'phpinfo');
check('collect date shape', (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $c['build_date']), true);
check('collect sapi', $c['sapi'], PHP_SAPI);

exit($fail === 0 ? 0 : 1);
