<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\MaintenancePage;

/**
 * Fetches one weight of a Google font, Latin subset, into this site's uploads.
 *
 * The maintenance page cannot load anything from WordPress while it is shown,
 * and should not send visitors to Google either, so the font has to be a file
 * the page can embed. Both Avada and GeneratePress sites mostly use Google
 * fonts, but each stores them its own way (hashed file names, or not locally
 * at all), so asking Google once, at import time, from the server, is the one
 * route that works the same everywhere. The Latin subset covers ä, ö and å.
 *
 * Returns the stored file's URL, or null for any failure — the caller falls
 * back to the visitor's own copy of the family, then a system font.
 */
final class GoogleFonts
{
    private const DIR       = 'valolink-maintenance';
    private const MAX_BYTES = 300 * 1024;
    /** Google serves woff2 only to browsers it recognises. */
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    public static function fetch(string $family, int $weight): ?string
    {
        $family = Branding::font($family);
        if ($family === '') {
            return null;
        }
        $weight = Branding::weight($weight) ?? 400;

        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error'])) {
            return null;
        }
        $name = sanitize_file_name(strtolower(str_replace(' ', '-', $family)) . '-' . $weight . '.woff2');
        $dir  = trailingslashit((string) $uploads['basedir']) . self::DIR;
        $path = $dir . '/' . $name;
        $url  = trailingslashit((string) $uploads['baseurl']) . self::DIR . '/' . $name;
        if (is_file($path)) {
            return $url;
        }

        $font = self::download($family, $weight) ?? ($weight !== 400 ? self::download($family, 400) : null);
        if ($font === null || !wp_mkdir_p($dir) || @file_put_contents($path, $font, LOCK_EX) === false) {
            return null;
        }

        return $url;
    }

    /** The woff2 bytes, or null. */
    private static function download(string $family, int $weight): ?string
    {
        $css = wp_remote_get(
            'https://fonts.googleapis.com/css2?family=' . rawurlencode($family) . ':wght@' . $weight . '&display=swap',
            ['timeout' => 8, 'user-agent' => self::USER_AGENT],
        );
        if (is_wp_error($css) || wp_remote_retrieve_response_code($css) !== 200) {
            return null;
        }

        // One block per subset, each introduced by a comment naming it.
        if (!preg_match('#/\*\s*latin\s*\*/\s*@font-face\s*\{[^}]*?url\((https://fonts\.gstatic\.com/[^)]+\.woff2)\)#s', (string) wp_remote_retrieve_body($css), $m)) {
            return null;
        }

        $file = wp_remote_get($m[1], ['timeout' => 8, 'user-agent' => self::USER_AGENT]);
        if (is_wp_error($file) || wp_remote_retrieve_response_code($file) !== 200) {
            return null;
        }
        $bytes = (string) wp_remote_retrieve_body($file);

        return strlen($bytes) > 0 && strlen($bytes) <= self::MAX_BYTES && str_starts_with($bytes, 'wOF2') ? $bytes : null;
    }

    /** Removes everything this class stored. */
    public static function forget(): void
    {
        $uploads = wp_get_upload_dir();
        $dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . self::DIR;
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir . '/*.woff2') as $file) {
            @unlink((string) $file);
        }
        @rmdir($dir);
    }
}
