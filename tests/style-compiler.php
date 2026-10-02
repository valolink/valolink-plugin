<?php
/**
 * StyleCompiler: GenerateBlocks style objects → CSS, and the safety rules.
 * Plain PHP: php tests/style-compiler.php
 */

require_once __DIR__ . '/../src/Modules/Accesslink/StyleCompiler.php';

use Valolink\Plugin\Modules\Accesslink\StyleCompiler;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
    $failures += $ok ? 0 : 1;
}

// GenerateBlocks' own example (styles-builder getCss): same declarations and order.
$css = StyleCompiler::compile('.btn', [
    'paddingTop'               => '1rem',
    'backgroundColor'          => 'var(--c)',
    '&:hover'                  => ['backgroundColor' => 'red'],
    '@media (max-width: 767px)' => ['display' => 'grid', 'gap' => '1rem'],
]);
check('basic rule, hover, media', $css === '.btn{background-color:var(--c);padding-top:1rem}.btn:hover{background-color:red}@media (max-width:767px){.btn{display:grid;gap:1rem}}', $css);

$css = StyleCompiler::compile('.a', ['--local' => '4px', ' > .b' => ['color' => 'var(--x)'], '.c' => ['marginTop' => '0']]);
check('custom property and descendants', $css === '.a{--local:4px}.a > .b{color:var(--x)}.a .c{margin-top:0}', $css);

$css = StyleCompiler::compile('.a', ['&:hover' => ['color' => 'red', '@media (max-width:767px)' => ['color' => 'blue']]]);
check('at-rule inside a nested selector is hoisted', $css === '.a:hover{color:red}@media (max-width:767px){.a:hover{color:blue}}', $css);

$css = StyleCompiler::compile('.a', ['WebkitLineClamp' => '3']);
check('vendor prefix', $css === '.a{-webkit-line-clamp:3}', $css);

$css = StyleCompiler::compile(':root', ['--space-md' => '16px', '--basalt' => '#1a1c1d']);
check('root tokens sorted', $css === ':root{--basalt:#1a1c1d;--space-md:16px}', $css);

check('valid object passes', StyleCompiler::validate(['paddingTop' => 'var(--space-lg)', '&:hover' => ['color' => '#fff']]) === []);
check('empty object refused', StyleCompiler::validate([]) !== []);

$bad = [
    'brace in value'    => ['color' => 'red}body{display:none'],
    'semicolon'         => ['color' => 'red;position:fixed'],
    'comment'           => ['color' => 'red/* x */'],
    'markup'            => ['content' => '"</style><script>"'],
    'import'            => ['--x' => '@import url(https://evil)'],
    'expression'        => ['width' => 'expression(alert(1))'],
    'javascript url'    => ['backgroundImage' => 'url(javascript:alert(1))'],
    'data url'          => ['backgroundImage' => "url('data:image/svg+xml;base64,AAAA')"],
    'unbalanced paren'  => ['width' => 'calc(100% - 4px'],
    'bad property'      => ['padding-top' => '1px'],
    'bad nested key'    => ['&{}' => ['color' => 'red']],
    'bad at-rule'       => ['@font-face' => ['fontFamily' => 'x']],
    'too deep'          => ['&:hover' => ['.a' => ['.b' => ['color' => 'red']]]],
    'array of numbers'  => ['color' => ['x']],
];
foreach ($bad as $name => $styles) {
    check('refused: ' . $name, StyleCompiler::validate($styles) !== [], implode(' | ', StyleCompiler::validate($styles)));
}
check('plain url allowed', StyleCompiler::validate(['backgroundImage' => "url('/wp-content/uploads/a.jpg')"]) === []);
check('https url allowed', StyleCompiler::validate(['backgroundImage' => 'url(https://example.fi/a.jpg)']) === []);

exit($failures === 0 ? 0 : 1);
