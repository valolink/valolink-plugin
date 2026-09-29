<?php
/**
 * BlockValidator against a real WordPress (it needs the block registry and
 * parser), core blocks only so any install will do:
 *   WP_LOAD=/path/to/wp-load.php php tests/block-validator-wp.php
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
use Valolink\Plugin\Modules\Accesslink\BlockValidator;

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}
function issues(string $content): array
{
    return (new BlockValidator())->check($content);
}
function mentions(array $issues, string $needle): bool
{
    return array_filter($issues, static fn (string $i): bool => str_contains($i, $needle)) !== [];
}

$p = static fn (string $text): string => "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
$group = static fn (string $inside): string => "<!-- wp:group -->\n<div class=\"wp-block-group\">{$inside}</div>\n<!-- /wp:group -->";

// 1. Stray comments: the renea.demolink.fi case, and the top-level variant.
$in_group = issues($group("\n<!-- BOX 1: Energiajohtaminen -->\n" . $p('a') . "\n"));
check('comment between a container\'s children is refused', mentions($in_group, 'BOX 1: Energiajohtaminen') && mentions($in_group, 'inside core/group'));
check('comment between top-level blocks is refused', mentions(issues($p('a') . "\n\n<!-- section two -->\n\n" . $p('b')), 'between top-level blocks'));
check('comment inside a leaf is refused', mentions(issues($p('a<!-- todo -->b')), 'inside core/paragraph'));

// 2. Where comments belong.
check('comment inside a Custom HTML block is fine', issues("<!-- wp:html -->\n<!-- widget --><div>x</div>\n<!-- /wp:html -->") === []);
check('core/more keeps its <!--more-->', issues("<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->") === []);
check('a clean container passes', issues($group("\n" . $p('a') . "\n\n" . $p('b') . "\n")) === []);

// 3. Only what a change introduces counts.
$broken = $group("\n<!-- old -->\n" . $p('a') . "\n");
check('a pre-existing comment does not block an edit', (new BlockValidator())->check_diff($broken, str_replace('>a<', '>b<', $broken)) === []);
check('removing it passes', (new BlockValidator())->check_diff($broken, $group("\n" . $p('a') . "\n")) === []);

// 4. Rich text is read inside its element only.
$button = "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"/x\">Varaa</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";
check('a button\'s own <div> wrapper is not rich text', issues($button) === []);
check('an <svg> in a paragraph is still refused', mentions(issues($p('a <svg viewBox="0 0 1 1"></svg>')), '<svg> inside core/paragraph'));
check('a <div> inside a button link is still refused', mentions(issues(str_replace('>Varaa<', '><div>Varaa</div><', $button)), '<div> inside core/button'));

echo $failures === 0 ? "\nall passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
