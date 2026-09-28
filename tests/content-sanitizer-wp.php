<?php
/**
 * ContentSanitizer against a real WordPress, because the bug this guards
 * against lives in core's kses hooks and no stand-in reproduces it:
 *   WP_LOAD=/path/to/wp-load.php php tests/content-sanitizer-wp.php
 *
 * Any throwaway install works — ~/valolink/localwp (SQLite) runs without a
 * server. 0.2.7 filtered each block delimiter with wp_kses() on its own, and
 * core's pre_kses hook re-serialised every lone opener as a void block, so
 * every create and insert_block emptied its containers.
 */
declare(strict_types=1);

$load = getenv('WP_LOAD');
if (!$load || !is_file($load)) {
    fwrite(STDERR, "Set WP_LOAD to a WordPress wp-load.php\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] ??= 'localhost';
require $load;
require dirname(__DIR__) . '/src/Autoloader.php';
\Valolink\Plugin\Autoloader::register();
use Valolink\Plugin\Modules\Accesslink\ContentSanitizer;

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}
function names(string $content): array
{
    $out = [];
    $walk = static function (array $blocks) use (&$walk, &$out): void {
        foreach ($blocks as $b) {
            if ($b['blockName'] !== null) {
                $out[] = $b['blockName'];
            }
            $walk($b['innerBlocks']);
        }
    };
    $walk(parse_blocks($content));

    return $out;
}

$page = <<<'HTML'
<!-- wp:generateblocks/element {"uniqueId":"76b6d800","tagName":"div","globalClasses":["outer-container"]} -->
<div class="outer-container"><!-- wp:generateblocks/element {"uniqueId":"bd210250","tagName":"div","styles":{"gridColumn":"2"},"css":".gb-element-bd210250{grid-column:2}"} -->
<div class="gb-element-bd210250"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Otsikko</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><strong>Aukioloajat</strong><br>ke–to klo 11.00–18.00</p>
<!-- /wp:paragraph --></div>
<!-- /wp:generateblocks/element --></div>
<!-- /wp:generateblocks/element -->

<!-- wp:spacer {"height":"20px"} /-->
HTML;

// 1. A new document keeps its tree: no opener turns void, nothing is lost.
$out = ContentSanitizer::filter($page);
check('no opener comes back self-closing', substr_count($out, ' /-->') === 1);
check('block tree unchanged', names($out) === names($page));
check('clean markup passes byte-identical', $out === $page);

// 2. Insert-block markup is one block with its closer — the same path.
$block = "<!-- wp:paragraph -->\n<p>Uusi</p>\n<!-- /wp:paragraph -->";
check('single block markup unchanged', ContentSanitizer::filter($block) === $block);

// 3. Attribute values are still filtered, as core does in a whole document.
$evil = '<!-- wp:generateblocks/text {"uniqueId":"aa","htmlAttributes":{"title":"<script>x</script>"}} --><p>x</p><!-- /wp:generateblocks/text -->';
$filtered = ContentSanitizer::filter($evil);
check('markup inside an attribute value is stripped', !str_contains($filtered, '<script>'));
check('a filtered delimiter still opens its block', names($filtered) === ['generateblocks/text']);

// 4. An embed survives in a Custom HTML block and nowhere else.
$map = '<iframe src="https://www.google.com/maps/embed?pb=x" width="100%" height="322" loading="lazy"></iframe>';
$html = "<!-- wp:html -->\n{$map}\n<!-- /wp:html -->";
check('iframe kept inside a Custom HTML block', ContentSanitizer::filter($html) === $html);
check('iframe stripped outside one', !str_contains(ContentSanitizer::filter("<!-- wp:paragraph -->\n<p>{$map}</p>\n<!-- /wp:paragraph -->"), '<iframe'));
check('has_code flags an iframe for the reviewer', ContentSanitizer::has_code($html));

echo $failures === 0 ? "\nall passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
