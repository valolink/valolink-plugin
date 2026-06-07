<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Staging;

final class StagingDetector
{
    /** Leftmost hostname label keywords that indicate a non-production environment. */
    private const STAGING_LABELS = [
        'staging', 'stage', 'dev', 'develop', 'development',
        'local', 'test', 'sandbox', 'preprod',
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

    public static function is_staging(): bool
    {
        static $result = null;
        if ($result !== null) {
            return $result;
        }
        return $result = self::detect();
    }

    private static function detect(): bool
    {
        // WP_ENVIRONMENT_TYPE is authoritative when defined.
        // WP core recognises: production, staging, development, local.
        if (defined('WP_ENVIRONMENT_TYPE')) {
            return WP_ENVIRONMENT_TYPE !== 'production';
        }

        if (self::check_constants()) {
            return true;
        }

        if (self::check_hostname()) {
            return true;
        }

        // Private SERVER_ADDR is a weak signal — require at least one corroborating signal
        // (WP_DEBUG or search-engine indexing explicitly disabled) to avoid false positives
        // on containerised production environments.
        if (self::check_server_ip()) {
            $debug   = defined('WP_DEBUG') && WP_DEBUG === true;
            $no_index = function_exists('get_option')
                && in_array(get_option('blog_public'), ['0', 0], true);
            if ($debug || $no_index) {
                return true;
            }
        }

        return false;
    }

    private static function check_constants(): bool
    {
        return (defined('WP_LOCAL_DEV') && WP_LOCAL_DEV)
            || (defined('VALOLINK_STAGING') && VALOLINK_STAGING);
    }

    private static function check_hostname(): bool
    {
        $host = self::current_hostname();
        if ($host === '') {
            return false;
        }

        if ($host === 'localhost') {
            return true;
        }

        // Strip port number.
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        // Check TLD (segment after the final dot).
        $dot_pos = strrpos($host, '.');
        if ($dot_pos !== false) {
            $tld = substr($host, $dot_pos + 1);
            if (in_array($tld, self::STAGING_TLDS, true)) {
                return true;
            }
        }

        // Check leftmost label only (avoids false positives like "staging-solutions.com").
        $leftmost = explode('.', $host)[0];
        if (in_array($leftmost, self::STAGING_LABELS, true)) {
            return true;
        }

        // Check known managed-host staging domains.
        foreach (self::STAGING_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function check_server_ip(): bool
    {
        $ip = isset($_SERVER['SERVER_ADDR']) && is_string($_SERVER['SERVER_ADDR'])
            ? $_SERVER['SERVER_ADDR']
            : '';

        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Returns false (validation fails) when the IP is in a private or reserved range.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function current_hostname(): string
    {
        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            return strtolower(trim($_SERVER['HTTP_HOST']));
        }
        if (isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME'])) {
            return strtolower(trim($_SERVER['SERVER_NAME']));
        }

        // CLI fallback: parse the registered site URL.
        if (function_exists('get_option')) {
            $siteurl = (string) get_option('siteurl', '');
            if ($siteurl !== '') {
                return strtolower((string) (parse_url($siteurl, PHP_URL_HOST) ?? ''));
            }
        }

        return '';
    }
}
