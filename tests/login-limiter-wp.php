<?php
/**
 * The failed-login limit against a real WordPress, through wp_signon() — the
 * call wp-login.php and WooCommerce's My account login both make:
 *   WP_LOAD=/path/to/wp-load.php php tests/login-limiter-wp.php
 *
 * Any throwaway install works (~/valolink/localwp, SQLite). Makes a user,
 * fails ten logins from one address, checks the lock, its message (also over a
 * generic login_errors filter), another address, and that the lock lifts and a
 * successful login clears the count. Removes the user and its transients.
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
require_once ABSPATH . 'wp-admin/includes/user.php';
use Valolink\Plugin\Modules\Security\LoginLimiter;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
    if (!$ok) {
        $failures++;
    }
}
function key_for(string $ip): string
{
    return 'valolink_login_fails_' . substr(hash('sha256', $ip), 0, 32);
}
function attempt(string $login, string $pass, string $ip)
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    $user = @wp_signon(['user_login' => $login, 'user_password' => $pass, 'remember' => false]);
    wp_set_current_user(0);
    return $user;
}

(new LoginLimiter())->register();
// What the generic-error toggle does: replace every message.
add_filter('login_errors', static fn () => 'generic', 10);

$login = 'vl-limit-test-' . wp_generate_password(6, false);
$pass  = wp_generate_password(20);
$uid   = wp_insert_user(['user_login' => $login, 'user_pass' => $pass, 'role' => 'subscriber']);
$a = '192.0.2.10';
$b = '192.0.2.11';
delete_transient(key_for($a));
delete_transient(key_for($b));

try {
    for ($i = 1; $i <= 9; $i++) {
        $r = attempt($login, 'wrong', $a);
    }
    check('nine failures are ordinary errors', is_wp_error($r) && $r->get_error_code() === 'incorrect_password', is_wp_error($r) ? $r->get_error_code() : 'logged in');
    $ok = attempt($login, $pass, $a);
    check('the right password still works after nine', $ok instanceof WP_User);
    check('a successful login clears the count', get_transient(key_for($a)) === false);

    for ($i = 1; $i <= 10; $i++) {
        $r = attempt($login, 'wrong', $a);
    }
    check('the tenth failure is still the ordinary error', is_wp_error($r) && $r->get_error_code() === 'incorrect_password');
    $locked = attempt($login, $pass, $a);
    check('then even the right password is refused', is_wp_error($locked) && $locked->get_error_code() === LoginLimiter::ERROR_CODE, is_wp_error($locked) ? $locked->get_error_code() : 'logged in');
    $msg = apply_filters('login_errors', 'whatever');
    check('the shown message says too many attempts, over a generic filter', str_contains($msg, 'Too many login attempts') || str_contains($msg, 'Liian monta kirjautumisyritystä'), $msg);
    check('it names the minutes left', (bool) preg_match('/15 (minutes|minuutin)/', $msg), $msg);

    $data = get_transient(key_for($a));
    attempt($login, 'wrong', $a);
    check('attempts while locked do not extend the lock', get_transient(key_for($a)) == $data);

    $other = attempt($login, $pass, $b);
    check('another address is not locked', $other instanceof WP_User);

    set_transient(key_for($a), ['n' => 10, 'first' => time() - 1000, 'until' => time() - 1], 1800);
    $after = attempt($login, $pass, $a);
    check('the lock lifts when its time is up', $after instanceof WP_User);

    set_transient(key_for($a), ['n' => 9, 'first' => time() - 1801, 'until' => 0], 1800);
    attempt($login, 'wrong', $a);
    check('failures older than the window start a new count', (get_transient(key_for($a))['n'] ?? null) === 1);
} finally {
    wp_delete_user((int) $uid);
    delete_transient(key_for($a));
    delete_transient(key_for($b));
}

echo $failures === 0 ? "all passed\n" : "$failures failed\n";
exit($failures === 0 ? 0 : 1);
