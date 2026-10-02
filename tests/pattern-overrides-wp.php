<?php
/**
 * Synced-pattern overrides, core blocks and GenerateBlocks Text, rendered:
 *   wp --path=/path/to/wp eval-file tests/pattern-overrides-wp.php
 *
 * A pattern compiled with slots, a page that fills them in, do_blocks() on the
 * page. Also renders without the Blocks module's filter, to notice the day
 * core (or GenerateBlocks) swaps GB Text values by itself and the shim can go.
 * Inside a transaction that is rolled back.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run with wp eval-file\n");
    exit(2);
}
if (!class_exists(\Valolink\Plugin\Modules\Blocks\BlocksModule::class)) {
    require dirname(__DIR__) . '/src/Autoloader.php';
    \Valolink\Plugin\Autoloader::register();
}
use Valolink\Plugin\Modules\Accesslink\BlockCompiler;
use Valolink\Plugin\Modules\Accesslink\StyleApplier;
use Valolink\Plugin\Modules\Accesslink\StyleReader;
use Valolink\Plugin\Modules\Blocks\BlocksModule;
use Valolink\Plugin\Settings;

if (!StyleReader::available() || !WP_Block_Type_Registry::get_instance()->is_registered('generateblocks/text')) {
    fwrite(STDERR, "GenerateBlocks (Pro) is not active on that install\n");
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
$wpdb->query('START TRANSACTION');
try {
    (new StyleApplier())->apply_style('.vlpo-band', ['paddingTop' => '1px'], 'Test');
    (new StyleApplier())->apply_style('.vlpo-eyebrow', ['color' => 'red'], 'Test');
    $compiled = (new BlockCompiler())->compile(['element' => 'section', 'class' => 'vlpo-band', 'children' => [
        ['text' => 'Oletusyläotsikko', 'class' => 'vlpo-eyebrow', 'slot' => 'Yläotsikko'],
        ['heading' => 'Oletusotsikko', 'slot' => 'Otsikko'],
        ['paragraph' => 'Kiinteä teksti.'],
        ['button' => 'Oletuspainike', 'href' => '/x/', 'slot' => 'Painike'],
    ]], 'po');
    check('pattern compiles with slots', !is_wp_error($compiled), is_wp_error($compiled) ? $compiled->get_error_message() : '');
    $markup = $compiled['markup'];
    check('slot metadata written', substr_count($markup, '"source":"core/pattern-overrides"') === 3);
    $dup = (new BlockCompiler())->compile([['heading' => 'a', 'slot' => 'X'], ['paragraph' => 'b', 'slot' => 'X']], 'dup');
    check('duplicate slot refused', is_wp_error($dup));
    $bad = (new BlockCompiler())->compile(['element' => 'div', 'slot' => 'X'], 'bad');
    check('element cannot be a slot', is_wp_error($bad));

    $pattern = wp_insert_post(['post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'vlpo', 'post_content' => $markup]);
    $page = (new BlockCompiler())->compile(['pattern' => $pattern, 'slots' => [
        'Yläotsikko' => 'Sivun yläotsikko',
        'Otsikko'    => 'Sivun otsikko',
        'Painike'    => 'Sivun painike',
    ]], 'page');
    check('page compiles', !is_wp_error($page), is_wp_error($page) ? $page->get_error_message() : '');

    $module = new BlocksModule(new Settings());
    $render = static fn (): string => do_blocks($page['markup']);

    $without = $render();
    check('core fills a core heading slot by itself', str_contains($without, 'Sivun otsikko'));
    $core_does_gb = str_contains($without, 'Sivun yläotsikko');
    echo $core_does_gb
        ? "note core now swaps GenerateBlocks Text values without the Blocks module — its shim can go\n"
        : "note core still leaves GenerateBlocks Text slots at the pattern's default (the shim is needed)\n";

    $module->register();
    $with = $render();
    remove_filter('render_block', [$module, 'gb_text_bound_content'], 10);
    check('GB Text slot shows the page value', str_contains($with, '>Sivun yläotsikko</p>'), mb_substr(wp_strip_all_tags($with), 0, 200));
    check('GB Text button keeps its link and class', (bool) preg_match('#<a class="gb-text"[^>]*href="/x/"[^>]*>Sivun painike</a>#', $with), $with);
    check('fixed text unchanged', str_contains($with, 'Kiinteä teksti.'));
    check('heading still the page value', str_contains($with, 'Sivun otsikko'));
    $defaults = do_blocks('<!-- wp:block {"ref":' . $pattern . '} /-->');
    check('a page without values gets the defaults', str_contains($defaults, 'Oletusyläotsikko') && str_contains($defaults, 'Oletusotsikko'));
} finally {
    $wpdb->query('ROLLBACK');
    wp_cache_flush_runtime();
    StyleApplier::rebuild();
}

exit($GLOBALS['failures'] === 0 ? 0 : 1);
