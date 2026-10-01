<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\MaintenancePage;

/**
 * The customer-branded page visitors see while WordPress updates.
 *
 * During an update WordPress writes `.maintenance` into the site root, and
 * every request then stops in wp_maintenance() — line 79 of wp-settings.php,
 * before the database is connected or any plugin is loaded. Its only hook is
 * a drop-in: if `wp-content/maintenance.php` exists, that file is included
 * instead of core's grey "Briefly unavailable" box. So nothing a plugin does
 * at request time can brand that page; the page has to be written out ahead
 * of time as a self-contained file — text escaped, logo embedded — and kept
 * in step with the settings from wp-admin.
 *
 * The file is ours only when it carries the marker; a maintenance.php
 * someone else put there is never overwritten or removed.
 */
final class DropIn
{
    public const MARKER = 'valolink-maintenance:';

    /** Bump when the page's markup changes, so existing files are rewritten. */
    private const FORMAT = 1;

    /** Files up to this size are embedded, so the page needs nothing else from the server. */
    private const EMBED_MAX_BYTES = 300 * 1024;

    public static function path(): string
    {
        return WP_CONTENT_DIR . '/maintenance.php';
    }

    /** 'ours', 'foreign' or 'absent'. */
    public static function status(): string
    {
        $path = self::path();
        if (!file_exists($path)) {
            return 'absent';
        }

        return self::signature_in_file() !== null ? 'ours' : 'foreign';
    }

    /**
     * Write the file when what it would contain has changed. Cheap when it
     * has not: the inputs are hashed and compared with the hash the file
     * carries in its first line, so the logo is read only on a change.
     */
    public static function sync(array $settings): string
    {
        $status = self::status();
        if ($status === 'foreign') {
            return $status;
        }

        $branding  = Branding::resolve($settings);
        $signature = self::signature($branding);
        if ($status === 'ours' && self::signature_in_file() === $signature) {
            return 'ours';
        }

        return self::write(self::build(self::with_assets($branding), $signature)) ? 'ours' : 'unwritable';
    }

    public static function remove(): void
    {
        if (self::status() === 'ours') {
            @unlink(self::path());
        }
    }

    /** The page as visitors will see it, for the admin preview. */
    public static function html(array $settings): string
    {
        return self::page(self::with_assets(Branding::resolve($settings)), true);
    }

    /** Logo and fonts as the page uses them: embedded when small and local, else their URL. */
    private static function with_assets(array $b): array
    {
        $src = static fn (string $url, string $path, string $kind): string => $url === '' || Branding::missing_upload($url)
            ? ''
            : self::embed($path, $url, $kind);
        $b['logo_src'] = $src((string) $b['logo_url'], (string) $b['logo_path'], 'image/');
        foreach (['heading', 'body'] as $role) {
            $b[$role . '_font_src'] = $src((string) $b[$role . '_font_url'], (string) $b[$role . '_font_path'], 'font/');
        }

        return $b;
    }

    // -------------------------------------------------------------------------

    private static function build(array $b, string $signature): string
    {
        $header = <<<PHP
            <?php
            // {MARKER}{$signature}
            /*
             * Maintenance page written by valolink-plugin (Valolink → Maintenance page).
             * WordPress shows it while an update runs. Regenerated when its settings
             * change: edit them there, not here.
             */
            if (!headers_sent()) {
                http_response_code(503);
                header('Retry-After: 60');
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('X-Robots-Tag: noindex');
            }
            ?>
            PHP;

        return str_replace('{MARKER}', self::MARKER, $header) . self::page($b, false);
    }

    /**
     * Static HTML. Every value is escaped, the logo is a data URI or an
     * escaped URL, and none of it can contain `<?`, so the file stays inert
     * outside the header block above.
     */
    private static function page(array $b, bool $preview): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // A logo that fails to load in the browser gives way to the name.
        $name = '<div class="name">' . $e($b['site_name']) . '</div>';
        $logo = $b['logo_src'] !== ''
            ? '<img class="logo" src="' . $e($b['logo_src']) . '" alt="' . $e($b['site_name']) . '"'
                . ' onerror="this.hidden=true;this.nextElementSibling.hidden=false">'
                . str_replace('<div class="name">', '<div class="name" hidden>', $name)
            : $name;
        $message   = $b['message'] !== '' ? '<p>' . $e($b['message']) . '</p>' : '';
        $secondary = $b['secondary'] !== '' ? '<p class="secondary" lang="en">' . $e($b['secondary']) . '</p>' : '';
        $refresh   = $preview ? '' : '<meta http-equiv="refresh" content="30">';

        // A family with a file gets an @font-face under a private name, so a
        // visitor's own copy of the family can't shadow it; without one, the
        // family is still asked for by name, then the system fonts.
        $faces = '';
        $stacks = [];
        foreach (['heading', 'body'] as $role) {
            $family = (string) $b[$role . '_font'];
            $stack = [];
            if ((string) $b[$role . '_font_src'] !== '') {
                $faces .= sprintf(
                    "@font-face { font-family: \"vlm-%s\"; src: url(\"%s\") format(\"%s\"); font-weight: %d; font-display: swap; }\n",
                    $role,
                    $e((string) $b[$role . '_font_src']),
                    str_contains((string) $b[$role . '_font_url'], '.woff2') || str_starts_with((string) $b[$role . '_font_src'], 'data:font/woff2') ? 'woff2' : 'woff',
                    (int) $b[$role . '_weight'],
                );
                $stack[] = '"vlm-' . $role . '"';
            }
            if ($family !== '') {
                $stack[] = '"' . $e($family) . '"';
            }
            $stacks[$role] = implode(', ', array_merge($stack, [Branding::SYSTEM_FONTS]));
        }
        $logo_width = (int) $b['logo_width'] > 0 ? (int) $b['logo_width'] . 'px' : 'min(260px, 70vw)';

        $page = <<<HTML
            <!doctype html>
            <html lang="{$e($b['lang'])}">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            {$refresh}
            <title>{$e($b['site_name'])}</title>
            <style>
            {$faces}:root { --bg: {$e($b['background'])}; --fg: {$e($b['text'])}; --accent: {$e($b['accent'])}; }
            * { box-sizing: border-box; }
            html, body { height: 100%; margin: 0; }
            body { display: flex; align-items: center; justify-content: center; padding: 24px; background: var(--bg); color: var(--fg);
              font: {$b['body_weight']} 17px/1.55 {$stacks['body']}; text-align: center; }
            main { max-width: 560px; }
            .logo { display: block; width: {$logo_width}; max-width: 70vw; max-height: 140px; object-fit: contain; width: auto; height: auto; margin: 0 auto 32px; }
            .name[hidden], .logo[hidden] { display: none; }
            .name { font-family: {$stacks['heading']}; font-size: 28px; font-weight: {$b['heading_weight']}; margin-bottom: 32px; }
            h1 { font-family: {$stacks['heading']}; font-size: clamp(22px, 4vw, 30px); line-height: 1.25; margin: 0 0 12px; font-weight: {$b['heading_weight']}; text-wrap: balance; }
            p { margin: 0 0 8px; opacity: .85; }
            .secondary { margin-top: 20px; font-size: 15px; opacity: .6; }
            .bar { width: 120px; height: 3px; margin: 28px auto 0; border-radius: 3px; overflow: hidden; background: color-mix(in srgb, var(--accent) 20%, transparent); }
            .bar::after { content: ""; display: block; width: 40%; height: 100%; background: var(--accent); border-radius: 3px; animation: slide 1.4s ease-in-out infinite; }
            @keyframes slide { 0% { transform: translateX(-100%); } 100% { transform: translateX(250%); } }
            @media (prefers-reduced-motion: reduce) { .bar::after { animation: none; width: 100%; } }
            </style>
            </head>
            <body>
            <main>
            {$logo}
            <h1>{$e($b['heading'])}</h1>
            {$message}
            <div class="bar" aria-hidden="true"></div>
            {$secondary}
            </main>
            </body>
            </html>

            HTML;

        return $page;
    }

    private static function signature(array $branding): string
    {
        // Files are identified by path and mtime, so checking for a change
        // never reads them. FORMAT is in so that a new page design
        // reaches every site on its next admin visit.
        $mtimes = [];
        foreach (['logo_path', 'heading_font_path', 'body_font_path'] as $key) {
            $path = (string) ($branding[$key] ?? '');
            $mtimes[$key] = $path !== '' ? (int) @filemtime($path) : 0;
        }

        return substr(hash('sha256', (string) wp_json_encode([$branding, $mtimes, self::FORMAT])), 0, 16);
    }

    private static function signature_in_file(): ?string
    {
        $head = @file_get_contents(self::path(), false, null, 0, 200);
        if (!is_string($head) || !preg_match('/' . preg_quote(self::MARKER, '/') . '([0-9a-f]+)/', $head, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Written beside the target and renamed into place, so a request arriving
     * mid-write includes either the old file or the new one, never half.
     */
    private static function write(string $contents): bool
    {
        $target = self::path();
        $tmp = $target . '.' . wp_generate_password(8, false) . '.tmp';
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    private static function embed(string $path, string $url, string $kind): string
    {
        if ($path === '' || !is_readable($path) || filesize($path) > self::EMBED_MAX_BYTES) {
            return $url;
        }
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'woff2' => 'font/woff2',
            'woff'  => 'font/woff',
            'svg'   => 'image/svg+xml',
            default => (string) (wp_check_filetype($path)['type'] ?? ''),
        };
        if (!str_starts_with($mime, $kind)) {
            return $url;
        }
        $data = @file_get_contents($path);

        return is_string($data) ? 'data:' . $mime . ';base64,' . base64_encode($data) : $url;
    }
}
