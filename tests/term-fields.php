<?php
/**
 * Plain-PHP test of the taxonomy fields an agent may set, no WordPress needed:
 *   php tests/term-fields.php
 *
 * A site's own taxonomies become fields under their own names; WordPress's
 * and WooCommerce's plumbing taxonomies never do; a taxonomy whose name
 * collides with an existing field is skipped.
 */
declare(strict_types=1);

// --- WordPress stand-ins -----------------------------------------------------
$GLOBALS['taxonomies'] = [];
function get_taxonomies(array $args = [], string $output = 'names'): array
{
    $out = [];
    foreach ($GLOBALS['taxonomies'] as $name => $t) {
        if (($args['public'] ?? null) !== null && $t->public !== $args['public']) {
            continue;
        }
        if (($args['show_ui'] ?? null) !== null && $t->show_ui !== $args['show_ui']) {
            continue;
        }
        $out[$name] = $output === 'objects' ? $t : $name;
    }
    return $out;
}
function post_type_exists(string $type): bool { return false; }
function tax(string $name, bool $public = true, bool $show_ui = true): object
{
    return (object) ['name' => $name, 'public' => $public, 'show_ui' => $show_ui];
}

require dirname(__DIR__) . '/src/Autoloader.php';
\Valolink\Plugin\Autoloader::register();
use Valolink\Plugin\Modules\Accesslink\PostApplier;

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

$GLOBALS['taxonomies'] = [
    'category'       => tax('category'),
    'post_tag'       => tax('post_tag'),
    'post_format'    => tax('post_format', true, false),
    'nav_menu'       => tax('nav_menu', false, false),
    'kohteen_tyyppi' => tax('kohteen_tyyppi'),
    'renean_rooli'   => tax('renean_rooli'),
    'hidden_tax'     => tax('hidden_tax', false, true),
    'no_ui_tax'      => tax('no_ui_tax', true, false),
    'tags'           => tax('tags'),
    'product_cat'    => tax('product_cat'),
    'product_brand'  => tax('product_brand'),
    'language'       => tax('language'),
    'gblocks_pattern_collections' => tax('gblocks_pattern_collections'),
];

$custom = PostApplier::custom_term_fields();
check('the site\'s own public taxonomies become fields under their own names', $custom === ['kohteen_tyyppi' => 'kohteen_tyyppi', 'renean_rooli' => 'renean_rooli']);
check('categories and tags keep their aliases', PostApplier::term_fields()['categories'] === 'category' && PostApplier::term_fields()['tags'] === 'post_tag');
check('a taxonomy named like an existing field is skipped, not shadowing it', !isset($custom['tags']));
check('plumbing taxonomies are never fields', !isset($custom['post_format'], $custom['nav_menu'], $custom['product_cat'], $custom['language'], $custom['product_brand'], $custom['gblocks_pattern_collections']));
check('non-public or UI-less taxonomies are never fields', !isset($custom['hidden_tax'], $custom['no_ui_tax']));

$GLOBALS['taxonomies'] = ['category' => tax('category'), 'post_tag' => tax('post_tag')];
check('a plain blog has no custom fields', PostApplier::custom_term_fields() === []);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}
echo "all good\n";
