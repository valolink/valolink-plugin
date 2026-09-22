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

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}
echo "all good\n";
