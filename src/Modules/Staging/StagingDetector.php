<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Staging;

/**
 * The staging decision.
 *
 * A site declares its production host once, on production. The declaration is
 * stored as a SHA-256 hash (search-replace cannot rewrite it) plus a plaintext
 * copy for display. Any copy of the database whose `home` option no longer
 * hashes to the declaration is a clone, and the staging safeties apply.
 *
 *   force_staging                 → staging
 *   no declaration                → not staging (the module nags instead)
 *   hash(home host) ≠ declaration → staging
 *   otherwise                     → production
 *
 * The mu-loader mirrors this inline (no classes at bootstrap); the test in
 * tests/staging-decision.php proves the two agree. The old heuristics
 * (hostname labels, reserved TLDs, managed hosts, private IPs) survive only as
 * looks_like_staging(), which is advisory: it warns when someone is about to
 * declare a host that looks like a clone. It never decides.
 */
final class StagingDetector
{
    public const KEY_HOST      = 'production_host';
    public const KEY_HOST_HASH = 'production_host_hash';
    public const KEY_FORCE     = 'force_staging';

    public const REASON_FORCED     = 'forced';
    public const REASON_UNDECLARED = 'undeclared';
    public const REASON_MISMATCH   = 'mismatch';
    public const REASON_MATCH      = 'match';

    private const STAGING_LABELS = [
        'staging', 'stage', 'dev', 'develop', 'development',
        'local', 'test', 'sandbox', 'preprod', 'kopio', 'testi',
    ];

    /** TLDs reserved for local/testing use (RFC 2606, RFC 6761). */
    private const STAGING_TLDS = ['local', 'test', 'localhost', 'example', 'invalid'];

    /** Managed-host domains that are always staging/development environments. */
    private const STAGING_HOST_SUFFIXES = [
        'kinsta.cloud',
        'flywheelsites.com',
        'cloudwaysapps.com',
        'wpsandbox.net',
    ];

    // -------------------------------------------------------------------------
    // The decision

    /** @param array<string, mixed> $settings The Staging module's raw settings array. */
    public static function is_staging_with(array $settings): bool
    {
        return self::decision($settings)['staging'];
    }

    /**
     * @param  array<string, mixed> $settings
     * @return array{staging: bool, reason: string, home_host: string, declared_host: string}
     */
    public static function decision(array $settings): array
    {
        $home     = self::home_hostname();
        $declared = self::declared_host($settings);

        if (!empty($settings[self::KEY_FORCE])) {
            return ['staging' => true, 'reason' => self::REASON_FORCED, 'home_host' => $home, 'declared_host' => $declared];
        }

        $hash = self::declared_hash($settings);
        if ($hash === '') {
            return ['staging' => false, 'reason' => self::REASON_UNDECLARED, 'home_host' => $home, 'declared_host' => $declared];
        }

        if ($home === '' || !hash_equals($hash, self::host_hash($home))) {
            return ['staging' => true, 'reason' => self::REASON_MISMATCH, 'home_host' => $home, 'declared_host' => $declared];
        }

        return ['staging' => false, 'reason' => self::REASON_MATCH, 'home_host' => $home, 'declared_host' => $declared];
    }

    /** @param array<string, mixed> $settings */
    public static function is_declared(array $settings): bool
    {
        return self::declared_hash($settings) !== '';
    }

    /** Plaintext declared host, for display only ('' when undeclared). It may have been rewritten by a search-replace; the hash decides. */
    public static function declared_host(array $settings): string
    {
        $host = $settings[self::KEY_HOST] ?? '';
        return is_string($host) ? $host : '';
    }

    private static function declared_hash(array $settings): string
    {
        $hash = $settings[self::KEY_HOST_HASH] ?? '';
        return is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) ? $hash : '';
    }

    /**
     * Settings fragment that declares $host_or_url as production.
     *
     * @return array{production_host: string, production_host_hash: string}
     */
    public static function declaration_for(string $host_or_url): array
    {
        $host = self::normalise_host($host_or_url);
        return [
            self::KEY_HOST      => $host,
            self::KEY_HOST_HASH => $host === '' ? '' : self::host_hash($host),
        ];
    }

    /**
     * Canonical host for comparison: host part of a URL or a bare host,
     * lower-cased, trailing dot and port stripped, leading "www." stripped.
     * MUST stay identical to the inline copy in mu-loader.php.
     */
    public static function normalise_host(string $host_or_url): string
    {
        $value = strtolower(trim($host_or_url));
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '://')) {
            $value = (string) (parse_url($value, PHP_URL_HOST) ?? '');
        } else {
            $value = explode('/', $value)[0];
        }
        $value = preg_replace('/:\d+$/', '', $value) ?? $value;
        $value = rtrim($value, '.');
        if (str_starts_with($value, 'www.')) {
            $value = substr($value, 4);
        }
        return $value;
    }

    public static function host_hash(string $normalised_host): string
    {
        return hash('sha256', $normalised_host);
    }

    /** Normalised host of the `home` option; '' outside WordPress or when unset. */
    public static function home_hostname(): string
    {
        if (!function_exists('get_option')) {
            return '';
        }
        return self::normalise_host((string) get_option('home', ''));
    }

    // -------------------------------------------------------------------------
    // Advisory only — warns, never decides

    /** Does $host (or the current request host) look like a clone? */
    public static function looks_like_staging(?string $host = null): bool
    {
        if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE !== 'production') {
            return true;
        }
        if (defined('WP_LOCAL_DEV') && WP_LOCAL_DEV) {
            return true;
        }

        $host = $host === null ? self::current_hostname() : strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        if ($host === '') {
            return false;
        }
        if ($host === 'localhost') {
            return true;
        }

        $dot_pos = strrpos($host, '.');
        if ($dot_pos !== false && in_array(substr($host, $dot_pos + 1), self::STAGING_TLDS, true)) {
            return true;
        }
        if (in_array(explode('.', $host)[0], self::STAGING_LABELS, true)) {
            return true;
        }
        foreach (self::STAGING_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when $host has a non-www subdomain prefix.
     * E.g. "pohja.demolink.fi" → true, "www.demolink.fi" → false, "demolink.fi" → false.
     */
    public static function has_non_www_subdomain(string $host): bool
    {
        if ($host === '') {
            return false;
        }
        $parts = explode('.', $host);
        if (count($parts) < 3) {
            return false;
        }
        return $parts[0] !== 'www';
    }

    public static function current_hostname(): string
    {
        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            return strtolower(trim($_SERVER['HTTP_HOST']));
        }
        if (isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME'])) {
            return strtolower(trim($_SERVER['SERVER_NAME']));
        }
        if (function_exists('get_option')) {
            $siteurl = (string) get_option('siteurl', '');
            if ($siteurl !== '') {
                return strtolower((string) (parse_url($siteurl, PHP_URL_HOST) ?? ''));
            }
        }
        return '';
    }
}
