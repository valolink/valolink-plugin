<?php
/**
 * Plain-PHP test of ContentSanitizer's code-keeping rule, no WordPress needed:
 *   php tests/content-sanitizer.php
 *
 * <script> and <style> survive only inside a Custom HTML block (core/html),
 * whether the block arrives as markup with its delimiters or as the block's
 * own content addressed by path; everywhere else they are stripped.
 */
declare(strict_types=1);

// --- WordPress stand-ins -----------------------------------------------------
// wp_kses keeps HTML comments (block delimiters depend on it) and drops tags
// outside the allowlist; this stand-in does the same, nothing more.
function wp_kses_allowed_html(string $context): array
{
    return ['p' => ['class' => true], 'div' => ['class' => true], 'strong' => [], 'a' => ['href' => true]];
}
// The colour rule is a filter on core's CSS check; the stand-in wp_kses never
// runs that check, so these only have to exist. allow_colour_functions() is
// exercised directly below.
function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return true;
}
function remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    return true;
}
function wp_kses(string $content, array $allowed): string
{
    return (string) preg_replace_callback(
        '/<\/?([a-zA-Z][\w:-]*)\b[^>]*>/',
        static fn (array $m): string => isset($allowed[strtolower($m[1])]) ? $m[0] : '',
        $content,
    );
}

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

$code = '<style>.x{color:red}</style><div class="x">Hei</div><script>if (a < b) { go(); }</script>';

// 1. Outside any HTML block the code is stripped, tags and all.
$plain = ContentSanitizer::filter('<p>Hei</p>' . $code);
check('script and style stripped outside an html block', !str_contains($plain, '<script') && !str_contains($plain, '<style'));
check('the rest is kept', str_contains($plain, '<p>Hei</p>') && str_contains($plain, '<div class="x">Hei</div>'));

// 2. As the content of a core/html block (a block edit by path) it stays verbatim.
$kept = ContentSanitizer::filter($code, true);
check('code kept verbatim in an html block edit', $kept === $code);

// 3. In markup, the delimiters decide: inside <!-- wp:html --> kept, outside stripped.
$markup = "<!-- wp:paragraph --><p>Hei</p><script>bad()</script><!-- /wp:paragraph -->\n"
    . "<!-- wp:html -->\n{$code}\n<!-- /wp:html -->\n"
    . '<!-- wp:paragraph --><p>Moi</p><style>.y{}</style><!-- /wp:paragraph -->';
$filtered = ContentSanitizer::filter($markup);
check('code kept inside the html block delimiters', str_contains($filtered, $code));
// kses drops a disallowed tag and leaves its text, as WordPress does; the tags
// around bad() and .y{} must be gone, the ones inside the html block present.
check('code tags stripped in the paragraphs around it', substr_count($filtered, '<script') === 1 && substr_count($filtered, '<style') === 1);
check('delimiters and paragraphs intact', str_contains($filtered, '<!-- wp:html -->') && str_contains($filtered, '<!-- /wp:html -->') && str_contains($filtered, '<p>Moi</p>'));

// 4. Two html blocks in one document, each kept, order preserved.
$two = "<!-- wp:html --><script>one()</script><!-- /wp:html --><p>x</p><!-- wp:html --><style>.two{}</style><!-- /wp:html -->";
$out = ContentSanitizer::filter($two);
check('several html blocks each keep their code', str_contains($out, 'one()') && str_contains($out, '.two{}') && strpos($out, 'one()') < strpos($out, '.two{}'));

// 5. The reviewer's flag.
check('has_code sees script or style', ContentSanitizer::has_code($code) && !ContentSanitizer::has_code('<p>Hei</p>'));

// 6. A whole-body update: what the current post already has passes through,
// only the agent's new or changed pieces are filtered. <mark> is outside the
// stand-in allowlist, standing in for markup kses would damage.
$site = '<!-- wp:paragraph --><p><mark class="m">Vanha</mark></p><!-- /wp:paragraph -->';
$current = "{$site}\n\n<!-- wp:html --><script>widget()</script><!-- /wp:html -->";
$added = '<!-- wp:paragraph --><p><mark class="m">Uusi</mark><script>bad()</script></p><!-- /wp:paragraph -->';
$update = ContentSanitizer::filter("{$current}\n\n{$added}", false, $current);
check('unchanged site markup passes through', str_contains($update, $site));
check('unchanged html block passes through', str_contains($update, '<script>widget()</script>'));
check('the agent\'s new markup is still filtered', str_contains($update, '<p>Uusi') && substr_count($update, '<mark') === 1 && substr_count($update, '<script') === 1);
check('without the current document everything is filtered', !str_contains(ContentSanitizer::filter("{$current}\n\n{$added}"), '<mark'));

// 7. Markup from inside an HTML block does not pass through raw elsewhere:
// code is allowed there and nowhere else.
$moved = '<!-- wp:paragraph --><p>x</p><script>widget()</script><!-- /wp:paragraph -->';
check('html-block content moved out of its block is filtered', !str_contains(ContentSanitizer::filter($moved, false, $current), '<script'));

// 8. A changed piece is filtered whole, even where most of it matches.
$edited = str_replace('Vanha', 'Muokattu', $site);
check('an edited piece is filtered', !str_contains(ContentSanitizer::filter($edited, false, $current), '<mark'));

// 9. Colour functions pass core's CSS check; nothing else is let through.
// Arguments are core's test string, which already has var(), calc() etc. removed.
check('rgba() allowed', ContentSanitizer::allow_colour_functions(false, 'background-color:rgba(0, 0, 0, 0)'));
check('rgb() / hsl() / hsla() allowed', ContentSanitizer::allow_colour_functions(false, 'color:rgb(10 20 30 / 50%)')
    && ContentSanitizer::allow_colour_functions(false, 'color:hsl(120deg, 50%, 40%)')
    && ContentSanitizer::allow_colour_functions(false, 'color:hsla(120, 50%, 40%, .5)'));
check('core\'s own yes is kept', ContentSanitizer::allow_colour_functions(true, 'color:red'));
check('other functions still refused', !ContentSanitizer::allow_colour_functions(false, 'background:image(x)')
    && !ContentSanitizer::allow_colour_functions(false, 'width:expression(alert(1))'));
check('nothing smuggled inside or beside a colour', !ContentSanitizer::allow_colour_functions(false, 'color:rgb(url(x))')
    && !ContentSanitizer::allow_colour_functions(false, 'color:rgb(0,0,0)\\')
    && !ContentSanitizer::allow_colour_functions(false, 'color:rgb(0,0,0);x:a(')
    && !ContentSanitizer::allow_colour_functions(false, 'color:rgb("0")'));

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}
echo "all good\n";
