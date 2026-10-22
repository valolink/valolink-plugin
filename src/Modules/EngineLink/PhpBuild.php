<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\EngineLink;

/**
 * When the running PHP was built. Hosts that backport security fixes into
 * the release they ship keep the old version number: CloudLinux rebuilt
 * alt-php 8.4.26 on 2026-10-21 22:10 with the fixes for CVE-2026-109618/
 * 109619 and it still says 8.4.26. EngineLink compares this date with an
 * advisory's publication to tell such a build from a genuinely old one.
 *
 * PHP has no function for it; phpinfo()'s "Build Date" line (__DATE__
 * __TIME__ of the build, in the build machine's clock — UTC on the vendors
 * we see) has it under every SAPI. Where phpinfo() is disabled, the binary's
 * modification time stands in. Never throws.
 */
final class PhpBuild
{
    /**
     * @return array{build_date: string|null, build_date_source: string|null, sapi: string}
     */
    public static function collect(): array
    {
        $date   = null;
        $source = null;
        try {
            // A function in disable_functions is not defined at all on PHP 8.
            if (function_exists('phpinfo')) {
                ob_start();
                try {
                    phpinfo(INFO_GENERAL);
                    $text = (string) ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $date = self::parse($text);
                if ($date !== null) {
                    $source = 'phpinfo';
                }
            }
            if ($date === null && PHP_BINARY !== '' && @is_file(PHP_BINARY)) {
                $mtime = @filemtime(PHP_BINARY);
                if ($mtime !== false) {
                    $date   = gmdate('Y-m-d\TH:i:s\Z', $mtime);
                    $source = 'binary_mtime';
                }
            }
        } catch (\Throwable) {
            $date   = null;
            $source = null;
        }

        return ['build_date' => $date, 'build_date_source' => $source, 'sapi' => PHP_SAPI];
    }

    /**
     * The "Build Date" from phpinfo() output, HTML (web) or text (CLI), as
     * ISO 8601 read as UTC; null when there is none.
     */
    public static function parse(string $phpinfo): ?string
    {
        $text = html_entity_decode(strip_tags($phpinfo), ENT_QUOTES);
        if (!preg_match('/Build Date\s*(?:=>)?\s*([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d{4})\s+(\d{2}:\d{2}:\d{2})/', $text, $m)) {
            return null;
        }
        $t = \DateTimeImmutable::createFromFormat('!M j Y H:i:s', "{$m[1]} {$m[2]} {$m[3]} {$m[4]}", new \DateTimeZone('UTC'));

        return $t !== false ? $t->format('Y-m-d\TH:i:s\Z') : null;
    }
}
