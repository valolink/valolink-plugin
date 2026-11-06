<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Security;

/**
 * Per-IP failed-login limit for every form that logs in through wp_signon():
 * wp-login.php, WooCommerce's My account login, XML-RPC, application passwords.
 *
 * Lenient on purpose (Reima 2026-11-06: "not too tight"): ATTEMPTS failures from
 * one address within WINDOW lock that address out of logging in for LOCKOUT, and
 * the form says so in plain words — the site keeps working, only the login answers
 * "too many login attempts, try again in N minutes". A successful login clears the
 * count. While locked, even the right password is refused (otherwise the limit
 * would not stop a brute force), and attempts made while locked neither count nor
 * extend the lock.
 *
 * The address is REMOTE_ADDR only: X-Forwarded-For is the client's to write, so a
 * limit keyed on it could be dodged by rotating the header. Our nginx sets
 * REMOTE_ADDR from Cloudflare's header itself (conf.d/cloudflare.inc) where a site
 * is behind it.
 *
 * GRACEFUL FAILURE: every hook fails OPEN. A broken limiter lets a login through;
 * it never locks a real customer out.
 */
final class LoginLimiter
{
    public const ERROR_CODE = 'valolink_login_locked';

    private const ATTEMPTS = 10;
    private const WINDOW   = 1800;   // 30 min, counted from the first failure
    private const LOCKOUT  = 900;    // 15 min

    /** The message this request refused a login with; login_errors keeps it. */
    private ?string $issued = null;

    public function register(): void
    {
        // After core's own authenticate callbacks (20, 30, 99), so a right password
        // cannot overrule the lock.
        add_filter('authenticate', [$this, 'refuse_when_locked'], 100);
        add_action('wp_login_failed', [$this, 'record_failure'], 10, 2);
        add_action('wp_login', [$this, 'clear'], 10);
        // Last word on the shown message: the generic-error toggle (and anything
        // else) would otherwise replace "too many attempts" with "check your password".
        add_filter('login_errors', [$this, 'keep_message'], PHP_INT_MAX);
    }

    /**
     * @param \WP_User|\WP_Error|null $user
     * @return \WP_User|\WP_Error|null
     */
    public function refuse_when_locked($user)
    {
        try {
            $left = $this->seconds_locked(self::read($this->key()));
            if ($left <= 0) {
                return $user;
            }
            $this->issued = self::message($left);
            return new \WP_Error(self::ERROR_CODE, $this->issued);
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] login limit: check failed (open): ' . $e->getMessage());
            return $user;
        }
    }

    public function record_failure(string $username, $error = null): void
    {
        try {
            if ($error instanceof \WP_Error && $error->get_error_code() === self::ERROR_CODE) {
                return; // refused by the lock itself: neither counts nor extends it
            }
            $key  = $this->key();
            $now  = time();
            $data = self::read($key);
            if ($this->seconds_locked($data, $now) > 0) {
                return;
            }
            if ($data['n'] === 0 || $now - $data['first'] > self::WINDOW) {
                $data = ['n' => 0, 'first' => $now, 'until' => 0];
            }
            $data['n']++;
            if ($data['n'] >= self::ATTEMPTS) {
                $data['until'] = $now + self::LOCKOUT;
                // fail2ban-friendly, like the login guard's line.
                error_log(sprintf('[valolink-plugin] login limit: %s locked for %d s after %d failures', self::ip(), self::LOCKOUT, $data['n']));
            }
            set_transient($key, $data, max(self::WINDOW, self::LOCKOUT));
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] login limit: record failed: ' . $e->getMessage());
        }
    }

    public function clear(): void
    {
        try {
            delete_transient($this->key());
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] login limit: clear failed: ' . $e->getMessage());
        }
    }

    public function keep_message($errors)
    {
        return $this->issued ?? $errors;
    }

    /** @param array{n: int, first: int, until: int} $data */
    private function seconds_locked(array $data, ?int $now = null): int
    {
        return $data['until'] - ($now ?? time());
    }

    /** @return array{n: int, first: int, until: int} */
    private static function read(string $key): array
    {
        $data = get_transient($key);
        if (!is_array($data)) {
            return ['n' => 0, 'first' => 0, 'until' => 0];
        }
        return [
            'n'     => (int) ($data['n'] ?? 0),
            'first' => (int) ($data['first'] ?? 0),
            'until' => (int) ($data['until'] ?? 0),
        ];
    }

    private function key(): string
    {
        return 'valolink_login_fails_' . substr(hash('sha256', self::ip()), 0, 32);
    }

    private static function ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '?';
    }

    /**
     * The plugin has no translation files and our sites are Finnish, so the one
     * visitor-facing text is chosen by locale here.
     */
    private static function message(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        if (str_starts_with(determine_locale(), 'fi')) {
            return sprintf(
                'Liian monta kirjautumisyritystä. Yritä uudelleen %s. Jos olet unohtanut salasanasi, voit tilata uuden salasanan.',
                $minutes === 1 ? 'minuutin kuluttua' : sprintf('%d minuutin kuluttua', $minutes),
            );
        }
        return sprintf(
            'Too many login attempts. Please try again in %s. If you have forgotten your password, you can reset it.',
            $minutes === 1 ? 'a minute' : sprintf('%d minutes', $minutes),
        );
    }
}
