<?php
/**
 * BlockCompiler against a real GenerateBlocks Pro install:
 *   OUT=/tmp/compiled.json wp --path=/path/to/wp eval-file tests/block-compiler-wp.php
 *
 * Compiles one fixture per node kind with a fixed seed, checks the markup, and
 * (with OUT) writes {fixture: markup} for tests/editor-validate.cjs, which loads
 * each into the block editor — the editor's own validation is the only real
 * test of block markup. Global styles and a pattern for the fixtures are
 * created inside a transaction that is rolled back.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run with wp eval-file\n");
    exit(2);
}
if (!class_exists(\Valolink\Plugin\Modules\Accesslink\BlockCompiler::class)) {
    require dirname(__DIR__) . '/src/Autoloader.php';
    \Valolink\Plugin\Autoloader::register();
}
use Valolink\Plugin\Modules\Accesslink\BlockCompiler;
use Valolink\Plugin\Modules\Accesslink\StyleApplier;
use Valolink\Plugin\Modules\Accesslink\StyleReader;

if (!StyleReader::available()) {
    fwrite(STDERR, "GenerateBlocks Pro is not active on that install\n");
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
$out = [];
try {
    $applier = new StyleApplier();
    foreach (['vlc-section', 'vlc-section--alt', 'vlc-grid', 'vlc-card', 'vlc-eyebrow', 'vlc-button', 'vlc-lead', 'vlc-list', 'vlc-media'] as $class) {
        $r = $applier->apply_style('.' . $class, ['marginTop' => '0'], 'Compiler test');
        if (is_wp_error($r)) {
            throw new RuntimeException($r->get_error_message());
        }
    }
    $pattern_id = wp_insert_post([
        'post_type'    => 'wp_block',
        'post_status'  => 'publish',
        'post_title'   => 'vlc pattern',
        'post_content' => "<!-- wp:heading {\"metadata\":{\"name\":\"Otsikko\",\"bindings\":{\"__default\":{\"source\":\"core/pattern-overrides\"}}}} -->\n<h2 class=\"wp-block-heading\">Oletus</h2>\n<!-- /wp:heading -->\n\n<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button {\"metadata\":{\"name\":\"Painike\",\"bindings\":{\"__default\":{\"source\":\"core/pattern-overrides\"}}}} -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\">Painike</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->",
    ]);
    $image_id = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%' ORDER BY ID DESC LIMIT 1");

    $fixtures = [
        'section-grid-cards' => ['element' => 'section', 'class' => 'vlc-section vlc-section--alt', 'children' => [
            ['heading' => 'Ulkoporealtaat', 'level' => 2],
            ['paragraph' => 'Johdanto <strong>vahvalla</strong> ja <a href="/x/">linkillä</a>.', 'class' => 'vlc-lead'],
            ['element' => 'div', 'class' => 'vlc-grid', 'children' => [
                ['element' => 'article', 'class' => 'vlc-card', 'children' => [
                    ['text' => 'Uutuus', 'class' => 'vlc-eyebrow'],
                    ['heading' => 'HotSpring Highlife', 'level' => 3],
                    ['paragraph' => 'Tilava allas.'],
                    ['button' => 'Tutustu', 'href' => '/highlife/', 'class' => 'vlc-button'],
                ]],
                ['element' => 'article', 'class' => 'vlc-card', 'children' => [
                    ['heading' => 'Huolto', 'level' => 3],
                    ['button' => 'Varaa', 'href' => 'https://example.fi/varaa/', 'class' => 'vlc-button', 'new_tab' => true],
                ]],
            ]],
        ]],
        'plain-heading-paragraph' => [['heading' => 'Pelkkä otsikko'], ['paragraph' => 'Ja kappale.']],
        'heading-with-class-level-4' => ['heading' => 'Pieni', 'level' => 4, 'class' => 'vlc-lead'],
        'text-span-div' => ['element' => 'div', 'children' => [['text' => 'Span', 'tag' => 'span'], ['text' => 'Div', 'tag' => 'div', 'class' => 'vlc-eyebrow']]],
        'list-unordered' => ['list' => ['Yksi', 'Kaksi <em>korostettu</em>'], 'class' => 'vlc-list'],
        'list-ordered' => ['list' => ['Eka', 'Toka'], 'ordered' => true],
        'pattern-with-slots' => ['pattern' => $pattern_id, 'slots' => ['Otsikko' => 'Ohitettu', 'Painike' => ['text' => 'Klikkaa', 'url' => '/x/']]],
        'mixed-escapes' => ['element' => 'section', 'class' => 'vlc-section--alt', 'children' => [['paragraph' => 'Lainaus "tuplat" & \'yksittäiset\' <b>b</b> -- viivat']]],
    ];
    if ($image_id > 0) {
        $fixtures['image'] = ['element' => 'figure', 'class' => 'vlc-media', 'children' => [['image' => $image_id, 'alt' => 'Kuva', 'size' => 'medium']]];
    }

    $compiler = new BlockCompiler();
    foreach ($fixtures as $name => $tree) {
        $r = $compiler->compile($tree, 'golden-' . $name);
        if (is_wp_error($r)) {
            check($name . ' compiles', false, $r->get_error_message());
            continue;
        }
        $markup = $r['markup'];
        $out[$name] = $markup;
        $parsed = parse_blocks($markup);
        check($name . ' round-trips', serialize_blocks($parsed) === $markup);
        check($name . ' has no "--" inside a block comment', !preg_match('/<!-- wp:[^>]*--[^>]*-->/', preg_replace('/ \/?-->/', '', $markup) ?? ''));
    }

    $sg = $out['section-grid-cards'] ?? '';
    check('element: tagName and classes', str_contains($sg, '"tagName":"section","globalClasses":["vlc-section","vlc-section' . str_repeat('\u002d', 2) . 'alt"]') && str_contains($sg, '<section class="vlc-section vlc-section--alt">'), mb_substr($sg, 0, 200));
    check('text: gb-text then the class', str_contains($sg, '<p class="gb-text vlc-eyebrow">Uutuus</p>'));
    check('button: href in htmlAttributes and markup', str_contains($sg, '"htmlAttributes":{"href":"/highlife/"}') && str_contains($sg, '<a class="gb-text vlc-button" href="/highlife/">Tutustu</a>'));
    check('button new tab', str_contains($sg, 'target="_blank" rel="noopener"'));
    check('paragraph class', str_contains($sg, '<p class="vlc-lead">Johdanto <strong>vahvalla</strong> ja <a href="/x/">linkillä</a>.</p>'));
    check('heading level 3', str_contains($sg, '<!-- wp:heading {"level":3} -->'));
    check('pattern slots', str_contains($out['pattern-with-slots'] ?? '', '"content":{"Otsikko":{"content":"Ohitettu"},"Painike":{"text":"Klikkaa","url":"/x/"}}'), $out['pattern-with-slots'] ?? '');
    check('stable ids for a seed', ($compiler->compile($fixtures['text-span-div'], 'golden-text-span-div')['markup'] ?? '') === ($out['text-span-div'] ?? 'x'));

    $bad = [
        'unknown class' => ['element' => 'div', 'class' => 'not-a-style'],
        'bad tag' => ['element' => 'script'],
        'unknown node' => ['iframe' => 'x'],
        'button without href' => ['button' => 'x'],
        'javascript href' => ['button' => 'x', 'href' => 'javascript:alert(1)'],
        'missing pattern' => ['pattern' => 999999999],
        'unknown slot' => ['pattern' => $pattern_id, 'slots' => ['Nope' => 'x']],
    ];
    foreach ($bad as $name => $tree) {
        $r = $compiler->compile($tree, 'bad');
        check('refused: ' . $name, is_wp_error($r), is_wp_error($r) ? '' : 'compiled');
    }
    $r = $compiler->compile(['paragraph' => 'x<script>alert(1)</script><img src=x onerror=alert(1)>y'], 'strip');
    check('non-inline markup stripped', !is_wp_error($r) && !str_contains($r['markup'], '<script') && !str_contains($r['markup'], 'onerror'), is_wp_error($r) ? $r->get_error_message() : $r['markup']);
} finally {
    $wpdb->query('ROLLBACK');
    wp_cache_flush_runtime();
    StyleApplier::rebuild();
}

if (getenv('OUT')) {
    file_put_contents((string) getenv('OUT'), wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo 'wrote ' . count($out) . ' fixtures to ' . getenv('OUT') . "\n";
}

exit($GLOBALS['failures'] === 0 ? 0 : 1);
