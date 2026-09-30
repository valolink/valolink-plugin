<?php
/**
 * FusionReader and FusionValidator against a real WordPress. Needs Fusion
 * Builder active, since registration decides what insert_block accepts:
 *   WP_LOAD=/path/to/wp-load.php php tests/fusion-reader-wp.php
 * or, where a bare wp-load does not bootstrap the site:
 *   wp --path=/path/to/wp eval-file tests/fusion-reader-wp.php
 *
 * Nothing is written: every case is a string in, a string or error out.
 */

if (!defined('ABSPATH')) {
    $load = getenv('WP_LOAD');
    if (!$load || !is_file($load)) {
        fwrite(STDERR, "Set WP_LOAD to a WordPress wp-load.php\n");
        exit(2);
    }
    $_SERVER['HTTP_HOST'] ??= 'localhost';
    require $load;
}
if (!class_exists(\Valolink\Plugin\Modules\Accesslink\FusionReader::class)) {
    require dirname(__DIR__) . '/src/Autoloader.php';
    \Valolink\Plugin\Autoloader::register();
}
use Valolink\Plugin\Modules\Accesslink\ContentSanitizer;
use Valolink\Plugin\Modules\Accesslink\Documents;
use Valolink\Plugin\Modules\Accesslink\FusionReader;
use Valolink\Plugin\Modules\Accesslink\FusionValidator;

if (!shortcode_exists('fusion_builder_container')) {
    fwrite(STDERR, "Fusion Builder is not active on that install\n");
    exit(2);
}

$GLOBALS['failures'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
    if (!$ok) {
        $failures++;
    }
}
function err(mixed $r): string
{
    return is_wp_error($r) ? $r->get_error_code() : '';
}

$col = static fn (string $inside, string $type = '1_2'): string => '[fusion_builder_column type="' . $type . '" layout="' . $type . '" first="true" last="false" background_color=""]' . $inside . '[/fusion_builder_column]';
$container = static fn (string ...$cols): string => '[fusion_builder_container type="flex" admin_label="Hero" hundred_percent="no"][fusion_builder_row]' . implode('', $cols) . '[/fusion_builder_row][/fusion_builder_container]';
$text = static fn (string $t): string => '[fusion_text rule_style="default" animation_speed="0.3"]' . $t . '[/fusion_text]';
$sep = '[fusion_separator style_type="default" top_margin="20px" /]';
$tabs = '[fusion_tabs layout="horizontal"][fusion_tab title="Kuvaus" icon=""]<p>Tab one</p>[/fusion_tab][fusion_tab title="Tekniset tiedot" icon=""]<p>Tab two</p>[/fusion_tab][/fusion_tabs]';
$image = '[fusion_imageframe image_id="12|full" alt="" lightbox="no"]https://example.fi/a.jpg[/fusion_imageframe]';

$page = $container(
    $col($text("\n<h3>Otsikko</h3>\nEnsimmäinen kappale.") . $sep . $text('<p>Toinen [contact-form-7 id="5"]</p>')),
    $col($tabs . $image, '1_2'),
) . $container($col($text('<p>Alaosa</p>'), '1_1'));

$r = new FusionReader();

// 1. Reading.
check('page is detected as fusion', Documents::format($page) === Documents::FORMAT_FUSION);
check('blocks are not fusion', Documents::format("<!-- wp:paragraph -->\n<p>[fusion_text]</p>\n<!-- /wp:paragraph -->") === Documents::FORMAT_BLOCKS);
$flat = $r->flatten($page);
$by = [];
foreach ($flat['blocks'] as $b) {
    $by[$b['path']] = $b;
}
check('paths follow the grid', ($by['0']['name'] ?? '') === 'fusion_builder_container' && ($by['0.0.0.0']['name'] ?? '') === 'fusion_text' && ($by['0.0.1.0']['name'] ?? '') === 'fusion_tabs');
check('container label is read', ($by['0']['label'] ?? '') === 'Hero');
check('column width is read', ($by['0.0.1']['width'] ?? '') === '1_2');
check('text leaf is editable rich text', $by['0.0.0.0']['editable'] && $by['0.0.0.0']['text_kind'] === 'rich');
check('layout is not editable', !$by['0']['editable'] && $by['0']['has_inner_blocks']);
check('tabs hold tab items', ($by['0.0.1.0.1']['name'] ?? '') === 'fusion_tab' && ($by['0.0.1.0.1']['texts']['title'] ?? '') === 'Tekniset tiedot');
check('image content is not text', !$by['0.0.1.1']['editable'] && array_key_exists('alt', $by['0.0.1.1']['texts'] ?? []));
check('listing carries no markup', !array_key_exists('html', $by['0']));
check('get_at carries the markup', str_starts_with((string) ($r->get_at($page, '0.0.0.2')['html'] ?? ''), '[fusion_text'));

// 2. Words.
$out = $r->replace_text_at($page, '0.0.0.0', '<p>Uusi <strong>teksti</strong></p>');
check('update_text replaces only the content', is_string($out) && str_contains($out, '[fusion_text rule_style="default" animation_speed="0.3"]<p>Uusi <strong>teksti</strong></p>[/fusion_text]') && strlen($out) - strlen($page) === strlen('<p>Uusi <strong>teksti</strong></p>') - strlen("\n<h3>Otsikko</h3>\nEnsimmäinen kappale."));
check('a closer in the text is refused', err($r->replace_text_at($page, '0.0.0.0', 'a[/fusion_text]b')) === 'shortcode_in_text');
check('a new shortcode in the text is refused', err($r->replace_text_at($page, '0.0.0.0', 'a [fusion_code]PHNjcmlwdD4=[/fusion_code]')) === 'shortcode_in_text');
check('a shortcode already there may stay', is_string($r->replace_text_at($page, '0.0.0.2', '<p>Kolmas [contact-form-7 id="5"]</p>')));
check('a div is refused in rich text', err($r->replace_text_at($page, '0.0.0.0', '<div>x</div>')) === 'disallowed_inline_tag');
check('a heading is refused in a title', err($r->replace_text_at('[fusion_title size="2"]Otsikko[/fusion_title]', '0', '<h2>x</h2>')) === 'disallowed_inline_tag');
check('the image URL is not text', err($r->replace_text_at($page, '0.0.1.1', 'hello')) === 'not_text');
check('layout text edit is refused', err($r->replace_text_at($page, '0.0.0', 'x')) === 'block_has_children');

// 3. Attributes.
$out = $r->replace_attr_at($page, '0.0.1.0.1', 'title', 'Technical data');
check('tab title is replaced in place', is_string($out) && str_contains($out, '[fusion_tab title="Technical data" icon=""]'));
$out = $r->replace_attr_at('[fusion_imageframe lightbox="no"]https://example.fi/a.jpg[/fusion_imageframe]', '0', 'alt', 'Poreallas terassilla');
check('missing alt text is added', $out === '[fusion_imageframe lightbox="no" alt="Poreallas terassilla"]https://example.fi/a.jpg[/fusion_imageframe]');
check('a quote in an attribute is refused', err($r->replace_attr_at($page, '0.0.1.0.1', 'title', 'Say "hi"')) === 'bad_attr_text');
check('a styling attribute is not text', err($r->replace_attr_at($page, '0.0.1.0.1', 'icon', 'fa-x')) === 'not_text_attr');
check('tags are stripped from attribute text', str_contains((string) $r->replace_attr_at($page, '0.0.1.0.1', 'title', '<b>Bold</b> tab'), 'title="Bold tab"'));

// 4. Whole element.
$button = '[fusion_button link="/a" color="default"]Lue lisää[/fusion_button]';
$out = $r->replace_at($button, '0', '[fusion_button link="/b" color="default"]Lue lisää[/fusion_button]');
check('update_block may change attributes', $out === '[fusion_button link="/b" color="default"]Lue lisää[/fusion_button]');
check('update_block keeps the element type', err($r->replace_at($button, '0', $text('x'))) === 'block_type_changed');
check('update_block wants one element', err($r->replace_at($button, '0', $button . $button)) === 'block_type_changed');

// 5. Composition.
$out = $r->insert_block($page, '0.0.0.1', 'after', $text('<p>Uusi kappale</p>'));
check('insert an element after the separator', is_string($out) && ($r->get_at($out, '0.0.0.2')['text'] ?? '') === 'Uusi kappale');
$out = $r->insert_block($page, '', 'end', $container($col($text('<p>CTA</p>'), '1_1')));
check('append a container at the end', is_string($out) && ($r->get_at($out, '2')['name'] ?? '') === 'fusion_builder_container');
check('an element cannot sit beside a container', err($r->insert_block($page, '0', 'after', $text('x'))) === 'wrong_level');
check('a container cannot go inside a column', err($r->insert_block($page, '0.0.0.0', 'after', $container($col($text('x'))))) === 'wrong_level');
check('columns are locked', err($r->insert_block($page, '0.0.0', 'after', $col($text('x')))) === 'layout_locked');
check('a column cannot be deleted', err($r->delete_block($page, '0.0.1')) === 'layout_locked');
check('only tabs go among tabs', err($r->insert_block($page, '0.0.1.0.0', 'after', $text('x'))) === 'wrong_level');
check('another tab goes among tabs', is_string($r->insert_block($page, '0.0.1.0.1', 'after', '[fusion_tab title="Uusi"]<p>x</p>[/fusion_tab]')));
check('markup with loose text is refused', err($r->insert_block($page, '0.0.0.1', 'after', '[fusion_text]x')) === 'not_one_block');
check('a tag that would pair with its neighbour\'s closer is refused', err($r->insert_block($page, '0.0.0.1', 'after', '[fusion_text]')) === 'block_roundtrip_failed');
check('a tab cannot be inserted into a column', err($r->insert_block($page, '0.0.0.0', 'after', '[fusion_tab title="x"]y[/fusion_tab]')) === 'wrong_level');
check('two elements at once are refused', err($r->insert_block($page, '0.0.0.1', 'after', $text('a') . $text('b'))) === 'not_one_block');
check('a code element cannot be inserted', err($r->insert_block($page, '0.0.0.1', 'after', '[fusion_code]PHNjcmlwdD4=[/fusion_code]')) === 'code_element');
check('an unregistered element is refused', err($r->insert_block($page, '0.0.0.1', 'after', '[fusion_nonexistent]x[/fusion_nonexistent]')) === 'block_not_available');
check('a reference to a missing Library element is refused', err($r->insert_block($page, '0.0.0.1', 'after', '[fusion_global id="99999999"]')) === 'bad_global');

$out = $r->delete_block($page, '0.0.0.1');
check('delete an element', is_string($out) && !str_contains($out, 'fusion_separator') && strlen($out) === strlen($page) - strlen($sep));

$out = $r->move_block($page, '0.0.0.0', '0.0.0.2', 'after');
check('move within a column', is_string($out) && ($r->get_at($out, '0.0.0.2')['text'] ?? '') !== '' && str_contains((string) ($r->get_at($out, '0.0.0.2')['text'] ?? ''), 'Otsikko'));
$out = $r->move_block($page, '0.0.0.1', '0.0.1.1', 'before');
check('move across columns', is_string($out) && ($r->get_at($out, '0.0.1.1')['name'] ?? '') === 'fusion_separator');
$out = $r->move_block($page, '1', '0', 'before');
check('move a container', is_string($out) && ($r->get_at($out, '0.0.0.0')['text'] ?? '') === 'Alaosa');
check('a tab cannot move into a column', err($r->move_block($page, '0.0.1.0.0', '0.0.0.0', 'after')) === 'wrong_level');

// 6. Translation skeleton: words and text attributes may differ, nothing else.
$translated = $r->replace_attr_at($page, '0.0.1.0.1', 'title', 'Specifications');
$translated = $r->replace_text_at((string) $translated, '0.0.0.2', '<p>Second [contact-form-7 id="5"]</p>', false);
check('skeleton ignores words and text attributes', $r->skeleton($page) === $r->skeleton((string) $translated));
check('skeleton sees a styling attribute', $r->skeleton($page) !== $r->skeleton(str_replace('top_margin="20px"', 'top_margin="40px"', $page)));
check('skeleton sees a changed shortcode in text', $r->skeleton($page) !== $r->skeleton(str_replace('id="5"', 'id="6"', $page)));

// 7. Validator: only what a change introduces.
$v = new FusionValidator();
check('the test page is clean', $v->check($page) === [], implode(' | ', $v->check($page)));
check('an element directly in a row is reported', $v->check_diff($page, str_replace('[fusion_builder_row]', '[fusion_builder_row]' . $text('x'), $page)) !== []);
check('an unclosed container is reported', $v->check_diff($page, $page . '[fusion_builder_container type="flex"][fusion_builder_row]' . $col($text('x')) . '[/fusion_builder_row]') !== []);
check('a stray closer is reported', $v->check_diff($page, $page . '[/fusion_text]') !== []);
check('loose text in a column is reported', $v->check_diff($page, str_replace($sep, $sep . 'irrallinen teksti', $page)) !== []);
check('a new code element is reported', $v->check_diff($page, str_replace($sep, '[fusion_code]PHNjcmlwdD4=[/fusion_code]', $page)) !== []);
check('a script URL is reported', $v->check_diff($button, str_replace('/a', 'javascript:alert(1)', $button)) !== []);
$broken = str_replace($sep, $sep . 'vanha', $page);
check('a pre-existing problem does not block an edit', $v->check_diff($broken, (string) $r->replace_text_at($broken, '0.0.0.0', 'uusi')) === []);
check('a second copy of an old problem is reported', $v->check_diff($broken, str_replace($image, $image . 'toinen', $broken)) !== []);
check('a Library element may be a lone column', $v->check($col($text('x'))) === []);

// 8. A whole-body update filters only what the agent changed.
$widget = $container($col($text('<p>Kartta</p><iframe src="https://maps.example/embed"></iframe>')), $col($text('<p>Vanha teksti</p>')));
$edited = str_replace('Vanha teksti', 'Uusi teksti', $widget);
check('the page\'s own iframe survives an edit elsewhere', ContentSanitizer::filter($edited, false, $widget) === $edited);
$injected = str_replace('Vanha teksti', 'Uusi<iframe src="https://evil.example"></iframe>', $widget);
check('an iframe the agent adds is stripped', !str_contains(ContentSanitizer::filter($injected, false, $widget), 'evil.example'));
check('the untouched iframe stays when the agent adds one', str_contains(ContentSanitizer::filter($injected, false, $widget), 'maps.example'));

// 9. Review display.
$shown = $r->display_html($button);
check('display puts attributes on their own lines', substr_count($shown, "\n") >= 3 && str_contains($shown, "\n    link=\"/a\""));

$failures = $GLOBALS['failures'];
echo $failures === 0 ? "\nall passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
