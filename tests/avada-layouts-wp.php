<?php
/**
 * AvadaLayouts against a real Avada install, read-only:
 *   wp --path=/path/to/wp eval-file tests/avada-layouts-wp.php
 *
 * The round trip is the check that matters: every Layout on the site, read
 * into Accesslink's shape and written back into Avada's, must come out as
 * Avada stored it — otherwise approving an unrelated change to a Layout would
 * quietly rewrite its other conditions.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run with wp eval-file\n");
    exit(2);
}
if (!class_exists(\Valolink\Plugin\Modules\Accesslink\AvadaLayouts::class)) {
    require dirname(__DIR__) . '/src/Autoloader.php';
    \Valolink\Plugin\Autoloader::register();
}
use Valolink\Plugin\Modules\Accesslink\AvadaLayouts;

if (!AvadaLayouts::available()) {
    fwrite(STDERR, "Avada's Layout Builder is not active on that install\n");
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

$l = new AvadaLayouts();
$private = static fn (string $m) => (new ReflectionMethod($l, $m));

// 1. Grammar.
$page = get_posts(['post_type' => 'page', 'numberposts' => 1, 'post_status' => 'publish'])[0] ?? null;
$ok = $l->normalise_conditions([['rule' => 'front_page'], ['rule' => 'singular_page', 'mode' => 'exclude']] + ($page ? [2 => ['rule' => 'specific_page', 'object' => $page->ID]] : []));
check('valid rules normalise', is_array($ok) && $ok[0] === ['rule' => 'front_page', 'mode' => 'include'] && $ok[1]['mode'] === 'exclude', is_wp_error($ok) ? $ok->get_error_message() : '');
check('duplicates collapse', count((array) $l->normalise_conditions([['rule' => 'front_page'], ['rule' => 'front_page']])) === 1);
check('include and exclude of one thing is refused', is_wp_error($l->normalise_conditions([['rule' => 'front_page'], ['rule' => 'front_page', 'mode' => 'exclude']])));
check('specific_ needs an object', is_wp_error($l->normalise_conditions([['rule' => 'specific_page']])));
check('an object of the wrong type is refused', !$page || is_wp_error($l->normalise_conditions([['rule' => 'specific_post', 'object' => $page->ID]])));
check('a fixed rule takes no object', is_wp_error($l->normalise_conditions([['rule' => 'front_page', 'object' => 5]])));
check('an unknown post type is refused', is_wp_error($l->normalise_conditions([['rule' => 'singular_nosuchtype']])));
check('an unknown rule is refused', is_wp_error($l->normalise_conditions([['rule' => 'everything']])));
check('a bad mode is refused', is_wp_error($l->normalise_conditions([['rule' => 'front_page', 'mode' => 'maybe']])));
check('JSON text is accepted', is_array($l->normalise_conditions('[{"rule":"search_results"}]')));
check('not a list is refused', is_wp_error($l->normalise_conditions('front_page')));

// 2. Every real Layout survives the round trip.
$layouts = get_posts(['post_type' => AvadaLayouts::LAYOUT_TYPE, 'post_status' => 'any', 'numberposts' => -1]);
$decode = $private('decode');
$out = $private('conditions_out');
$store = $private('to_storage');
foreach ($layouts as $layout) {
    $stored = $decode->invoke(null, (string) $layout->post_content)['conditions'];
    $canonical = array_map(
        static fn (array $c): array => array_diff_key($c, ['label' => true]),
        $out->invoke($l, $stored),
    );
    $again = $store->invoke($l, $canonical);
    // Labels are Avada's display text and are rebuilt from the object's
    // current name, so a renamed page may differ there and nowhere else.
    $strip = static fn (array $conds): array => array_map(static fn (array $c): array => array_diff_key($c, ['label' => true]), $conds);
    check(
        sprintf('layout %d "%s" round-trips (%d conditions)', $layout->ID, $layout->post_title, count($stored)),
        $strip($again) === $strip($stored),
        wp_json_encode(['stored' => $stored, 'again' => $again]),
    );
}

// 3. Resolution returns a shape an agent can rely on.
if ($page) {
    $r = $l->for_post($page);
    check('for_post answers', array_key_exists('layout', $r) && is_array($r['sections']) && is_bool($r['post_content_shown']));
}
$list = $l->list();
check('list has areas, layouts and sections', $list['areas'] !== [] && is_array($list['layouts']) && is_array($list['sections']));

$failures = $GLOBALS['failures'];
echo $failures === 0 ? "\nall passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
