<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\MaintenancePage;

/**
 * What the maintenance page shows: the module's own settings, with defaults
 * for anything left empty. Nothing here reads the theme or another plugin —
 * that is ThemeImport's job, run on request, and what it finds is saved into
 * these settings like anything an operator typed. So the page never depends
 * on Avada or GeneratePress being installed, healthy, or the same version.
 */
final class Branding
{
    public const DEFAULT_BACKGROUND = '#f5f5f4';
    public const DEFAULT_TEXT       = '#1c1917';
    public const DEFAULT_ACCENT     = '#57534e';
    public const SYSTEM_FONTS       = 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';

    /** Every setting the module stores, with its kind. */
    public const FIELDS = [
        'site_name'         => 'text',
        'logo_url'          => 'url',
        'logo_width'        => 'int',
        'heading'           => 'text',
        'message'           => 'text',
        'secondary'         => 'text',
        'background'        => 'colour',
        'text'              => 'colour',
        'accent'            => 'colour',
        'heading_font'      => 'font',
        'heading_weight'    => 'weight',
        'heading_font_url'  => 'url',
        'body_font'         => 'font',
        'body_weight'       => 'weight',
        'body_font_url'     => 'url',
    ];

    /**
     * Default copy by site language. Finnish and Swedish sites get an English
     * line under their own, for visitors who read neither.
     *
     * @return array{heading: string, message: string, secondary: string}
     */
    public static function default_texts(string $locale): array
    {
        $english = 'Sorry for the interruption — the site is being updated and will be back in a moment.';
        if (str_starts_with($locale, 'fi')) {
            return [
                'heading'   => 'Anteeksi häiriö, sivustoa huolletaan pieni hetki!',
                'message'   => 'Palaamme aivan pian. Sivu päivittyy itsestään.',
                'secondary' => $english,
            ];
        }
        if (str_starts_with($locale, 'sv')) {
            return [
                'heading'   => 'Ursäkta störningen, webbplatsen underhålls en kort stund!',
                'message'   => 'Vi är snart tillbaka. Sidan uppdateras automatiskt.',
                'secondary' => $english,
            ];
        }

        return [
            'heading'   => 'Sorry for the interruption — the site is under maintenance for a moment.',
            'message'   => 'We will be back shortly. This page refreshes on its own.',
            'secondary' => '',
        ];
    }

    public static function resolve(array $settings): array
    {
        $locale   = (string) get_locale();
        $defaults = self::default_texts($locale);
        $str      = static fn (string $key): string => trim((string) ($settings[$key] ?? ''));
        $text     = static fn (string $key): string => $str($key) !== '' ? $str($key) : $defaults[$key];

        // Cleaned again here, not only on save: whatever reached the stored
        // settings, only an http(s) URL makes it into the page.
        $url = static fn (string $key): string => $str($key) === '' ? '' : esc_url_raw($str($key), ['http', 'https']);
        $logo_url = $url('logo_url');
        $heading_font_url = $url('heading_font_url');
        $body_font_url = $url('body_font_url');

        return [
            'site_name'         => $str('site_name') !== ''
                ? $str('site_name')
                : html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'lang'              => substr($locale, 0, 2) ?: 'en',
            'heading'           => $text('heading'),
            'message'           => $text('message'),
            // "-" switches the second line off.
            'secondary'         => $str('secondary') === '-' ? '' : $text('secondary'),
            'background'        => self::colour($settings['background'] ?? '') ?? self::DEFAULT_BACKGROUND,
            'text'              => self::colour($settings['text'] ?? '') ?? self::DEFAULT_TEXT,
            'accent'            => self::colour($settings['accent'] ?? '') ?? self::DEFAULT_ACCENT,
            'logo_url'          => $logo_url,
            'logo_path'         => self::local_path($logo_url),
            'logo_width'        => max(0, min(600, (int) ($settings['logo_width'] ?? 0))),
            'heading_font'      => self::font($settings['heading_font'] ?? ''),
            'heading_weight'    => self::weight($settings['heading_weight'] ?? '') ?? 700,
            'heading_font_url'  => $heading_font_url,
            'heading_font_path' => self::local_path($heading_font_url),
            'body_font'         => self::font($settings['body_font'] ?? ''),
            'body_weight'       => self::weight($settings['body_weight'] ?? '') ?? 400,
            'body_font_url'     => $body_font_url,
            'body_font_path'    => self::local_path($body_font_url),
        ];
    }

    /** Clean one submitted or imported value by its kind; null drops it. */
    public static function clean(string $field, mixed $value): mixed
    {
        $kind = self::FIELDS[$field] ?? null;
        if ($kind === null || (!is_scalar($value) && $value !== null)) {
            return null;
        }
        $value = trim((string) $value);

        return match ($kind) {
            'text'   => sanitize_text_field($value),
            'url'    => $value === '' ? '' : esc_url_raw($value, ['http', 'https']),
            'int'    => $value === '' ? 0 : max(0, min(600, (int) $value)),
            'colour' => $value === '' ? '' : self::colour($value),
            'font'   => self::font($value),
            'weight' => $value === '' ? '' : self::weight($value),
            default  => null,
        };
    }

    /** A hex colour, or null. Anything else would end up inside a <style>. */
    public static function colour(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) ? strtolower($value) : null;
    }

    /** One font family name: letters, digits, spaces and hyphens only. */
    public static function font(mixed $value): string
    {
        $value = trim(str_replace(['"', "'"], '', (string) $value));
        $value = (string) preg_replace('/,.*$/', '', $value);

        return preg_match('/^[\p{L}\p{N} \-]{1,60}$/u', $value) ? $value : '';
    }

    public static function weight(mixed $value): ?int
    {
        $w = (int) $value;

        return $w >= 100 && $w <= 900 && $w % 100 === 0 ? $w : null;
    }

    /**
     * A URL into this site's uploads whose file is not there: it would show
     * as a broken image or not load at all, so the page leaves it out.
     */
    public static function missing_upload(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $uploads = wp_get_upload_dir();
        $base = (string) preg_replace('#^https?:#', '', (string) ($uploads['baseurl'] ?? ''));
        $rel  = (string) preg_replace('#^https?:#', '', strtok($url, '?#') ?: '');

        return $base !== '' && str_starts_with($rel, $base) && self::local_path($url) === '';
    }

    /** The file behind an uploads URL, for embedding; '' when it is not local. */
    public static function local_path(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $uploads = wp_get_upload_dir();
        $base = (string) preg_replace('#^https?:#', '', (string) ($uploads['baseurl'] ?? ''));
        $rel  = (string) preg_replace('#^https?:#', '', strtok($url, '?#') ?: '');
        if ($base === '' || !str_starts_with($rel, $base) || str_contains($rel, '..')) {
            return '';
        }
        $path = ($uploads['basedir'] ?? '') . substr($rel, strlen($base));

        return is_file($path) ? $path : '';
    }
}
