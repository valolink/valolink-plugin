<?php
/**
 * Plain-PHP test of the ACF fields an agent may set, no WordPress and no ACF:
 *   php tests/acf-fields.php
 *
 * Fields come from the post type's groups under `acf:<name>`; scalar kinds
 * are cleaned to what update_field stores, the object kinds are refused on
 * write and named; values read back in the one string shape the diff and
 * the staleness hash compare.
 */
declare(strict_types=1);

// --- WordPress and ACF stand-ins ---------------------------------------------
$GLOBALS['groups'] = [
    'kohde' => [['key' => 'group_kohde']],
    'page'  => [],
];
$GLOBALS['fields'] = [
    'group_kohde' => [
        ['key' => 'field_1', 'name' => 'pinta_ala', 'label' => 'Pinta-ala', 'type' => 'number'],
        ['key' => 'field_2', 'name' => 'kuvaus', 'label' => 'Kuvaus', 'type' => 'wysiwyg'],
        ['key' => 'field_3', 'name' => 'tila', 'label' => 'Tila', 'type' => 'select', 'choices' => ['suunnittelu' => 'Suunnittelu', 'valmis' => 'Valmis']],
        ['key' => 'field_4', 'name' => 'palvelut', 'label' => 'Palvelut', 'type' => 'checkbox', 'choices' => ['valvonta' => 'Valvonta', 'kilpailutus' => 'Kilpailutus']],
        ['key' => 'field_5', 'name' => 'julkinen', 'label' => 'Julkinen', 'type' => 'true_false'],
        ['key' => 'field_6', 'name' => 'valmistui', 'label' => 'Valmistui', 'type' => 'date_picker'],
        ['key' => 'field_7', 'name' => 'kuvat', 'label' => 'Kuvat', 'type' => 'gallery'],
        ['key' => 'field_8', 'name' => 'vaiheet', 'label' => 'Vaiheet', 'type' => 'repeater'],
        ['key' => 'field_9', 'name' => 'valilehti', 'label' => 'Välilehti', 'type' => 'tab'],
    ],
];
$GLOBALS['meta'] = [1408 => ['field_1' => '2500', 'field_3' => 'suunnittelu', 'field_4' => ['valvonta'], 'field_5' => '1', 'field_7' => [11, 12]]];
$GLOBALS['written'] = [];
function acf_get_field_groups(array $args = []): array { return $GLOBALS['groups'][$args['post_type'] ?? ''] ?? []; }
function acf_get_fields(string $key): array { return $GLOBALS['fields'][$key] ?? []; }
function get_field(string $key, int $post_id, bool $format = true) { return $GLOBALS['meta'][$post_id][$key] ?? null; }
function update_field(string $key, $value, int $post_id): bool { $GLOBALS['written'][] = [$key, $value, $post_id]; return true; }
function get_post_type(int $post_id): string { return 'kohde'; }
function sanitize_text_field(string $s): string { return trim(strip_tags($s)); }
function sanitize_textarea_field(string $s): string { return trim(strip_tags($s)); }
function esc_url_raw(string $s): string { return trim($s); }
function wp_json_encode($v): string { return json_encode($v); }
function wp_kses_allowed_html(string $context): array { return ['p' => [], 'strong' => []]; }
function wp_kses(string $content, array $allowed): string
{
    return (string) preg_replace_callback('/<\/?([a-zA-Z][\w:-]*)\b[^>]*>/', static fn (array $m): string => isset($allowed[strtolower($m[1])]) ? $m[0] : '', $content);
}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public $data = null) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}

require dirname(__DIR__) . '/src/Autoloader.php';
\Valolink\Plugin\Autoloader::register();
use Valolink\Plugin\Modules\Accesslink\AcfFields;

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

check('available with the ACF functions present', AcfFields::available());
check('field names come from the post type\'s groups, layout kinds left out', AcfFields::field_names(['kohde', 'page']) === [
    'acf:pinta_ala', 'acf:kuvaus', 'acf:tila', 'acf:palvelut', 'acf:julkinen', 'acf:valmistui', 'acf:kuvat', 'acf:vaiheet',
]);
check('a page with no groups has none', AcfFields::field_names(['page']) === []);

$acf = new AcfFields();
$read = $acf->read(1408);
check('read lists every field with type, writability and the current value', $read['acf:pinta_ala']['value'] === '2500' && $read['acf:kuvat']['writable'] === false && $read['acf:kuvat']['value'] === '11, 12' && $read['acf:tila']['choices'] === ['suunnittelu', 'valmis']);
check('true_false reads as true/false, an unset field as empty', $read['acf:julkinen']['value'] === 'true' && $read['acf:valmistui']['value'] === '');
check('an array value reads in the shape the diff compares', $acf->read_field(1408, 'acf:palvelut') === 'valvonta');

$ok = $acf->normalise('kohde', [
    'acf:pinta_ala' => ' 3100 ',
    'acf:kuvaus'    => '<p>Hei <script>x()</script><strong>siellä</strong></p>',
    'acf:tila'      => 'valmis',
    'acf:palvelut'  => ['valvonta', 'kilpailutus'],
    'acf:julkinen'  => 'yes',
    'acf:valmistui' => '2026-09-22',
]);
check('scalar kinds are cleaned to what update_field stores', is_array($ok)
    && $ok['acf:pinta_ala'] === '3100'
    && $ok['acf:kuvaus'] === '<p>Hei x()<strong>siellä</strong></p>'
    && $ok['acf:tila'] === 'valmis'
    && $ok['acf:palvelut'] === ['valvonta', 'kilpailutus']
    && $ok['acf:julkinen'] === 'true'
    && $ok['acf:valmistui'] === '20260922');

$bad = static fn (array $f): string => ($r = $acf->normalise('kohde', $f)) instanceof WP_Error ? $r->get_error_code() : 'ok';
check('a choice the field does not offer is refused', $bad(['acf:tila' => 'purettu']) === 'acf_bad_choice');
check('a single-choice field refuses two values', $bad(['acf:tila' => ['valmis', 'suunnittelu']]) === 'acf_single_choice');
check('a non-number is refused', $bad(['acf:pinta_ala' => 'iso']) === 'acf_bad_number');
check('a bad date is refused', $bad(['acf:valmistui' => '2026-13-40']) === 'acf_bad_date');
check('an object kind is refused on write with its type named', $bad(['acf:kuvat' => [1]]) === 'acf_field_readonly' && str_contains($acf->normalise('kohde', ['acf:vaiheet' => []])->get_error_message(), 'repeater'));
check('an unknown field is refused', $bad(['acf:olematon' => 'x']) === 'acf_unknown_field');

$acf->write(1408, ['acf:pinta_ala' => '3100', 'acf:julkinen' => 'false', 'acf:palvelut' => ['valvonta']]);
check('writes go through update_field with the field key, true_false as 1/0', $GLOBALS['written'] === [['field_1', '3100', 1408], ['field_5', 0, 1408], ['field_4', ['valvonta'], 1408]]);

check('sanitize cleans a known writable field and passes the rest through', AcfFields::sanitize('acf:julkinen', 1, ['kohde']) === 'true' && AcfFields::sanitize('acf:kuvat', [1, 2], ['kohde']) === [1, 2] && AcfFields::sanitize('acf:nope', 'x', ['kohde']) === 'x');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}
echo "all good\n";
