<?php
/**
 * Accesslink's abilities through WordPress' Abilities API (6.9+):
 *   wp --path=/path/to/wp eval-file tests/abilities-wp.php
 * Needs the Accesslink module enabled when WordPress loads (the abilities are
 * registered by it). Runs the read abilities, a compile, and a proposal inside
 * a rolled-back transaction; checks who may run them.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run with wp eval-file\n");
    exit(2);
}
if (!function_exists('wp_get_ability')) {
    fwrite(STDERR, "No Abilities API on this WordPress\n");
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

$names = ['valolink/accesslink-guide', 'valolink/design-system', 'valolink/read-content', 'valolink/read-structure', 'valolink/compile-section', 'valolink/propose-change', 'valolink/change-status'];
if (\Valolink\Plugin\Modules\Accesslink\AvadaLayouts::available()) {
    $names[] = 'valolink/avada-layouts';
}
foreach ($names as $name) {
    check('registered: ' . $name, wp_get_ability($name) !== null);
}
if (wp_get_ability('valolink/accesslink-guide') === null) {
    fwrite(STDERR, "Accesslink abilities are not registered — is the Accesslink module enabled?\n");
    exit(1);
}

global $wpdb;
$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
$subscriber = wp_insert_user(['user_login' => 'vl-abilities-test-' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);

$wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int) $subscriber);
    $denied = wp_get_ability('valolink/design-system')->execute([]);
    check('a subscriber is refused', is_wp_error($denied), is_wp_error($denied) ? '' : 'ran');

    wp_set_current_user($admin->ID);
    $guide = wp_get_ability('valolink/accesslink-guide')->execute([]);
    check('guide runs', is_array($guide) && str_contains((string) ($guide['guide'] ?? ''), 'Accesslink'), is_wp_error($guide) ? $guide->get_error_message() : '');
    $section = wp_get_ability('valolink/accesslink-guide')->execute(['section' => 'proposing']);
    check('guide section by name', is_array($section) && str_contains((string) ($section['guide'] ?? ''), 'Proposing'), is_wp_error($section) ? $section->get_error_message() : '');

    if (\Valolink\Plugin\Modules\Accesslink\StyleReader::available()) {
        $ds = wp_get_ability('valolink/design-system')->execute(['usage' => true]);
        check('design system runs', is_array($ds) && array_key_exists('tokens', $ds) && array_key_exists('patterns', $ds), is_wp_error($ds) ? $ds->get_error_message() : '');
        (new \Valolink\Plugin\Modules\Accesslink\StyleApplier())->apply_style('.vlab-x', ['marginTop' => '0'], 'Test');
        $c = wp_get_ability('valolink/compile-section')->execute(['tree' => ['element' => 'div', 'class' => 'vlab-x', 'children' => [['paragraph' => 'Hei']]]]);
        check('compile runs', is_array($c) && str_contains((string) ($c['markup'] ?? ''), '<p>Hei</p>'), is_wp_error($c) ? $c->get_error_message() : wp_json_encode($c));
        $bad = wp_get_ability('valolink/compile-section')->execute(['tree' => ['element' => 'div', 'class' => 'nope']]);
        check('compile errors come back as errors', is_wp_error($bad));
    }

    $page = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1])[0] ?? null;
    if ($page) {
        $read = wp_get_ability('valolink/read-content')->execute(['id' => $page->ID]);
        check('read content runs', is_array($read) && (int) ($read['id'] ?? 0) === $page->ID, is_wp_error($read) ? $read->get_error_message() : '');
        $structure = wp_get_ability('valolink/read-structure')->execute(['id' => $page->ID]);
        check('read structure runs', is_array($structure) && isset($structure['blocks'], $structure['format']), is_wp_error($structure) ? $structure->get_error_message() : '');
    }
    if (wp_get_ability('valolink/avada-layouts') !== null) {
        $layouts = wp_get_ability('valolink/avada-layouts')->execute([]);
        check('avada layouts runs', is_array($layouts) && isset($layouts['layouts'], $layouts['areas']), is_wp_error($layouts) ? $layouts->get_error_message() : '');
    }

    // The module's settings are read once per request, so the writes switch is
    // whatever it was when WordPress loaded: run once with it off, once on.
    $writes = (new \Valolink\Plugin\Modules\Accesslink\AccesslinkAuth(new \Valolink\Plugin\Settings()))->writes_enabled();
    echo 'note writes are ' . ($writes ? 'on' : 'off') . "\n";
    if (!$writes) {
        $off = wp_get_ability('valolink/propose-change')->execute(['action' => 'update', 'target_id' => $page->ID ?? 0, 'fields' => ['title' => 'x']]);
        // WordPress wraps a permission callback's error as ability_invalid_permissions.
        check('propose refused while writes are off', is_wp_error($off), 'ran');
    } elseif ($page) {
        $p = wp_get_ability('valolink/propose-change')->execute(['action' => 'update', 'target_id' => $page->ID, 'fields' => ['post_title' => $page->post_title . ' (testi)'], 'note' => 'abilities test', 'agent' => 'test-agent']);
        check('propose files a pending change', is_array($p) && ($p['status'] ?? '') === 'pending', is_wp_error($p) ? $p->get_error_message() : wp_json_encode($p));
        $p = is_array($p) ? $p : [];
        check('requested_by names the WordPress user', str_starts_with((string) ($p['requested_by'] ?? ''), 'wp:' . $admin->user_login), (string) ($p['requested_by'] ?? ''));
        check('nothing applied', get_post($page->ID)->post_title === $page->post_title);
        $st = wp_get_ability('valolink/change-status')->execute(['id' => (int) ($p['id'] ?? 0)]);
        check('change status runs', is_array($st) && ($st['status'] ?? '') === 'pending', is_wp_error($st) ? $st->get_error_message() : '');
    }

    $req = new WP_REST_Request('GET', '/wp-abilities/v1/abilities');
    $req->set_param('category', 'valolink-accesslink');
    $res = rest_do_request($req);
    $listed = array_column((array) $res->get_data(), 'name');
    check('listed over REST', $res->get_status() === 200 && count(array_intersect($names, $listed)) === count($names), $res->get_status() . ' ' . wp_json_encode($listed));
} finally {
    $wpdb->query('ROLLBACK');
    wp_cache_flush_runtime();
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user((int) $subscriber);
    if (class_exists(\Valolink\Plugin\Modules\Accesslink\StyleApplier::class)) {
        \Valolink\Plugin\Modules\Accesslink\StyleApplier::rebuild();
    }
}

exit($GLOBALS['failures'] === 0 ? 0 : 1);
