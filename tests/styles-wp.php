<?php
/**
 * Style proposals end to end against a real GenerateBlocks Pro 2.8+ install:
 *   wp --path=/path/to/wp eval-file tests/styles-wp.php
 *
 * Proposes a global style and two design tokens through ChangeService, approves
 * them as an administrator, and checks what GenerateBlocks then serves. All of
 * it inside a transaction that is rolled back; the stylesheet file is rebuilt
 * from the rolled-back state at the end. For a local mirror (claudewp): with a
 * persistent object cache the rolled-back values can linger there.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run with wp eval-file\n");
    exit(2);
}
if (!class_exists(\Valolink\Plugin\Modules\Accesslink\StyleReader::class)) {
    require dirname(__DIR__) . '/src/Autoloader.php';
    \Valolink\Plugin\Autoloader::register();
}
use Valolink\Plugin\Modules\Accesslink\AccesslinkModule;
use Valolink\Plugin\Modules\Accesslink\ChangeRepository;
use Valolink\Plugin\Modules\Accesslink\ChangeService;
use Valolink\Plugin\Modules\Accesslink\ChangeTable;
use Valolink\Plugin\Modules\Accesslink\GuideBuilder;
use Valolink\Plugin\Modules\Accesslink\PostApplier;
use Valolink\Plugin\Modules\Accesslink\StyleReader;
use Valolink\Plugin\Settings;

if (!StyleReader::tokens_available()) {
    fwrite(STDERR, "GenerateBlocks Pro 2.8+ is not active on that install\n");
    exit(2);
}

$GLOBALS['failures'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
    if (!$ok) {
        $GLOBALS['failures']++;
    }
}

global $wpdb;
$admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
if (!$admin) {
    fwrite(STDERR, "No administrator to approve as\n");
    exit(2);
}
$had_table = ChangeTable::exists();
ChangeTable::maybe_install();
$wpdb->query('START TRANSACTION');

try {
    $settings = new Settings();
    $service = new ChangeService($settings, new ChangeRepository(), new PostApplier());
    $reader = new StyleReader();

    $settings->set_module_setting(AccesslinkModule::MODULE_ID, 'allow_style_edits', false);
    $off = $service->propose(['action' => 'set_style', 'selector' => '.vl-test-card', 'styles' => ['color' => 'red']], 'test');
    check('refused while switched off', is_wp_error($off) && $off->get_error_code() === 'styles_disabled');

    $settings->set_module_setting(AccesslinkModule::MODULE_ID, 'allow_style_edits', true);
    $bad = $service->propose(['action' => 'set_style', 'selector' => '.vl-test-card', 'styles' => ['color' => 'red}body{x:y']], 'test');
    check('unsafe value refused at propose', is_wp_error($bad) && $bad->get_error_code() === 'invalid_styles');
    $root = $service->propose(['action' => 'set_style', 'selector' => ':root', 'styles' => ['color' => 'red']], 'test');
    check(':root refused as a class style', is_wp_error($root) && $root->get_error_code() === 'invalid_selector');

    // Tokens first, then a style that uses them.
    $t = $service->propose(['action' => 'set_tokens', 'tokens' => [
        ['name' => '--vl-test-space', 'value' => '24px', 'type' => 'unit', 'category' => 'Test', 'scope' => ['paddingTop', 'paddingBottom']],
        ['name' => '--vl-test-ink', 'value' => '#1a1c1d', 'type' => 'color', 'category' => 'Test', 'scope' => ['color']],
    ]], 'test');
    check('tokens proposed', !is_wp_error($t) && ($t['status'] ?? '') === 'pending', is_wp_error($t) ? $t->get_error_message() : '');

    $styles = ['paddingTop' => 'var(--vl-test-space)', 'color' => 'var(--vl-test-ink)', '&:hover' => ['color' => 'red']];
    $s = $service->propose(['action' => 'set_style', 'selector' => '.vl-test-card', 'category' => 'Test', 'styles' => $styles], 'test');
    check('style proposed', !is_wp_error($s) && ($s['status'] ?? '') === 'pending', is_wp_error($s) ? $s->get_error_message() : '');
    check('nothing written before approval', $reader->find('.vl-test-card') === null);

    wp_set_current_user($admin->ID);
    $ta = $service->approve((int) $t['id']);
    check('tokens applied', !is_wp_error($ta) && $ta['status'] === 'applied', is_wp_error($ta) ? $ta->get_error_message() : wp_json_encode($ta['error'] ?? ''));
    $names = array_column($reader->tokens(), 'value', 'name');
    check('token values readable', ($names['--vl-test-space'] ?? '') === '24px' && ($names['--vl-test-ink'] ?? '') === '#1a1c1d');
    check('root CSS carries the tokens', str_contains(\GenerateBlocks_Pro_Styles_Root::get_css(), '--vl-test-space:24px'));

    $sa = $service->approve((int) $s['id']);
    check('style applied', !is_wp_error($sa) && $sa['status'] === 'applied', is_wp_error($sa) ? $sa->get_error_message() : wp_json_encode($sa['error'] ?? ''));
    $found = $reader->find('.vl-test-card');
    check('style stored as GenerateBlocks stores it', $found !== null && $found['styles'] === $styles && $found['category'] === 'Test');
    check('compiled CSS', ($found['css'] ?? '') === '.vl-test-card{color:var(--vl-test-ink);padding-top:var(--vl-test-space)}.vl-test-card:hover{color:red}', (string) ($found['css'] ?? ''));
    check('row points at the style', (int) $sa['target_id'] === (int) ($found['id'] ?? -1));
    check('GenerateBlocks serves it', str_contains((string) get_option('generateblocks_style_css'), '.vl-test-card{'));

    // A change in between parks the second proposal as stale.
    $s2 = $service->propose(['action' => 'set_style', 'selector' => '.vl-test-card', 'styles' => ['color' => 'blue']], 'test');
    update_post_meta((int) $found['id'], 'gb_style_css', '.vl-test-card{color:green}');
    $s2a = $service->approve((int) $s2['id']);
    check('stale when the style changed meanwhile', !is_wp_error($s2a) && $s2a['status'] === 'stale');

    $guide = (new GuideBuilder($settings, $service, new \Valolink\Plugin\Modules\Accesslink\AgentNotes($settings), new \Valolink\Plugin\Modules\Accesslink\AccesslinkAuth($settings)))->section('styles') ?? '';
    check('guide lists the style and tokens', str_contains($guide, '`.vl-test-card`') && str_contains($guide, '`--vl-test-space` 24px'), mb_substr($guide, 0, 300));
    check('guide explains proposing when enabled', str_contains($guide, '"action": "set_style"'));
} finally {
    $wpdb->query('ROLLBACK');
    if (!$had_table) {
        ChangeTable::drop();
    }
    // This process still caches what the rollback undid. Runtime only: a full
    // flush on a box whose sites share a Redis database empties theirs too.
    wp_cache_flush_runtime();
    \Valolink\Plugin\Modules\Accesslink\StyleApplier::rebuild();
}

check('rolled back', (new StyleReader())->find('.vl-test-card') === null && !str_contains((string) get_option('generateblocks_style_css'), '.vl-test-card'));

exit($GLOBALS['failures'] === 0 ? 0 : 1);
