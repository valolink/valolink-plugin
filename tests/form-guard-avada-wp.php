<?php
/**
 * Form guard's Avada Forms check against a real WordPress, through the
 * admin-ajax action Avada's script posts to:
 *   WP_LOAD=/path/to/wp-load.php php tests/form-guard-avada-wp.php
 *
 * Any throwaway install works (~/valolink/localwp, SQLite); Avada itself is not
 * needed. A stand-in for Avada's handler sits on the action at priority 10 and
 * records whether a submit reached it. The posts are shaped like
 * fusion-form.js sends them: the form's fields serialized in `formData`.
 * Settings come through a filter, nothing is saved; used-token transients are
 * removed at the end.
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
use Valolink\Plugin\Modules\FormGuard\FormGuardModule;
use Valolink\Plugin\Settings;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
    if (!$ok) {
        $failures++;
    }
}

final class SentJson extends \RuntimeException {}

const ACTION = 'wp_ajax_nopriv_fusion_form_submit_ajax';

$nonces = [];
function token(int $age): string
{
    global $nonces;
    $ts    = time() - $age;
    $nonce = bin2hex(random_bytes(8));
    $sign  = new ReflectionMethod(FormGuardModule::class, 'sign');
    $nonces[] = $nonce;
    return $ts . '.' . $nonce . '.' . $sign->invoke(null, $ts, $nonce);
}

/**
 * Posts a form; returns [reached Avada's handler, decoded JSON answer or null].
 *
 * @param array<string, string> $fields
 * @param array<string, string> $top    extra top-level POST values
 */
function submit(array $fields, array $top = []): array
{
    global $reached;
    $reached = false;
    $_POST   = wp_slash(['action' => 'fusion_form_submit_ajax', 'form_id' => '71', 'formData' => http_build_query($fields)] + $top);
    ob_start();
    try {
        do_action(ACTION);
    } catch (SentJson $e) {
        // wp_send_json() ended the request.
    }
    $out = (string) ob_get_clean();
    return [$reached, $out === '' ? null : json_decode($out, true)];
}
function rejected(array $result): bool
{
    [$reached, $json] = $result;
    return !$reached && is_array($json) && ($json['status'] ?? '') === 'error'
        && is_array($json['info'] ?? null) && ($json['info']['valolink_form_guard'] ?? '') !== '';
}

ini_set('error_log', '/dev/null'); // rejections are logged there while the Event Log is off
$_SERVER['REMOTE_ADDR'] = '192.0.2.20';
$stored = ['modules' => ['form_guard' => ['enabled' => true, 'settings' => ['avada' => false]]]];
add_filter('pre_option_valolink_settings', static function () use (&$stored) {
    return $stored;
});
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', static fn () => static function (): void {
    throw new SentJson();
});

(new FormGuardModule(new Settings()))->register();
check('nothing is hooked while the Avada toggle is off', !has_action(ACTION) && !has_filter('fusion_builder_form_submission_data'));

$stored['modules']['form_guard']['settings']['avada'] = true;
(new FormGuardModule(new Settings()))->register();
add_action(ACTION, static function (): void {
    global $reached;
    $reached = true;
}, 10);

$message = ['nimi' => 'Testi', 'viesti' => 'Hei & terve "siivous"'];

try {
    $good = token(5);
    [$reached, $json] = submit($message + ['_vlg_token' => $good, '_vlg_note' => '']);
    check('a form with a valid token reaches Avada', $reached && $json === null, (string) wp_json_encode($json));
    check('the same token is refused a second time', rejected(submit($message + ['_vlg_token' => $good, '_vlg_note' => ''])));

    check('a form without a token is refused', rejected(submit($message)));
    check('a filled decoy is refused', rejected(submit($message + ['_vlg_token' => token(5), '_vlg_note' => 'x'])));
    check('a token issued a second ago is refused', rejected(submit($message + ['_vlg_token' => token(0), '_vlg_note' => ''])));
    check('a token over a day old is refused', rejected(submit($message + ['_vlg_token' => token(DAY_IN_SECONDS + 10), '_vlg_note' => ''])));
    $forged = preg_replace('/.$/', 'g', token(5));
    check('a token with a wrong signature is refused', rejected(submit($message + ['_vlg_token' => $forged, '_vlg_note' => ''])));
    check('a token outside formData does not count', rejected(submit($message, ['_vlg_token' => token(5)])));
    check('an array in place of the token is refused', rejected(submit($message + ['_vlg_token' => [token(5)]])));
    $_POST = ['action' => 'fusion_form_submit_ajax'];
    ob_start();
    try {
        do_action(ACTION);
    } catch (SentJson $e) {
    }
    check('a post without formData is refused', ($j = json_decode((string) ob_get_clean(), true)) && $j['status'] === 'error');

    $data = apply_filters('fusion_builder_form_submission_data', [
        'submission' => ['form_id' => 1],
        'data'       => $message + ['_vlg_token' => $good, '_vlg_note' => ''],
    ]);
    check('the guard fields are kept out of the stored entry and mail', $data['data'] === $message && $data['submission'] === ['form_id' => 1]);
    check('odd submission data passes through untouched', apply_filters('fusion_builder_form_submission_data', 'x') === 'x');
} finally {
    foreach ($nonces as $nonce) {
        delete_transient('vlg_used_' . $nonce);
    }
}

echo $failures === 0 ? "all passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
