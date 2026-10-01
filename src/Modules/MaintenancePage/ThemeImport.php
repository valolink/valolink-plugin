<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\MaintenancePage;

/**
 * Pre-fills the maintenance page's settings from the active theme.
 *
 * Run only when an operator asks, never on a visitor request, and what it
 * finds is saved as ordinary settings: the page itself never reads the
 * theme. Every source is read in its own try/catch and treated as a guess —
 * Avada and GeneratePress change their option shapes between versions, and
 * a theme that answers strangely costs one missing value and a line in the
 * report, never an error.
 */
final class ThemeImport
{
    /**
     * @return array{values: array<string, mixed>, found: array<int, string>, missed: array<int, string>}
     */
    public static function run(): array
    {
        $values = [];
        $found  = [];
        $missed = [];

        $sources = [
            'Avada'          => [self::class, 'avada'],
            'GeneratePress'  => [self::class, 'generatepress'],
            'WordPress logo' => [self::class, 'core'],
        ];
        foreach ($sources as $label => $reader) {
            try {
                $got = $reader();
            } catch (\Throwable $e) {
                $missed[] = sprintf('%s: could not be read (%s)', $label, $e->getMessage());
                continue;
            }
            if ($got === null) {
                continue;
            }
            foreach ($got as $field => $value) {
                $clean = Branding::clean($field, $value);
                // The first source to find a value wins: the theme's own
                // logo before WordPress's generic one.
                if ($clean !== null && $clean !== '' && $clean !== 0 && !array_key_exists($field, $values)) {
                    $values[$field] = $clean;
                    $found[] = sprintf('%s: %s', $label, $field);
                }
            }
        }

        // One line per family, not per role: a heading and body set in the
        // same family would otherwise report the same miss twice.
        $missing = [];
        foreach (['heading', 'body'] as $role) {
            $family = (string) ($values[$role . '_font'] ?? '');
            if ($family === '' || isset($missing[$family])) {
                continue;
            }
            try {
                $url = GoogleFonts::fetch($family, (int) ($values[$role . '_weight'] ?? ($role === 'heading' ? 700 : 400)));
            } catch (\Throwable $e) {
                $url = null;
            }
            if ($url !== null) {
                $values[$role . '_font_url'] = $url;
                $found[] = sprintf('Google Fonts: %s %s, stored on this site', $family, $role);
            } else {
                $missing[$family] = true;
                $missed[] = sprintf('%s font file: not found on Google Fonts, so the page uses %s if the visitor has it and a system font otherwise. Upload a .woff2 and set its URL to be exact.', $family, $family);
            }
        }

        if ($found === []) {
            $missed[] = 'Nothing recognisable in the theme. Set the logo, colours and fonts by hand.';
        }

        return ['values' => $values, 'found' => $found, 'missed' => $missed];
    }

    /*
     * The readers below return values as they find them, of whatever type.
     * Branding::clean() decides what is usable — a non-scalar or malformed
     * value is dropped there — so nothing here casts an array to a string.
     */

    /** @return array<string, mixed>|null */
    private static function avada(): ?array
    {
        $o = get_option('fusion_options');
        if (!is_array($o)) {
            return null;
        }
        $body = is_array($o['body_typography'] ?? null) ? $o['body_typography'] : [];
        $h1   = is_array($o['h1_typography'] ?? null) ? $o['h1_typography'] : [];

        return [
            'logo_url'       => is_array($o['logo'] ?? null) ? ($o['logo']['url'] ?? '') : '',
            'background'     => $o['bg_color'] ?? '',
            'text'           => $body['color'] ?? '',
            'accent'         => $o['primary_color'] ?? '',
            'body_font'      => $body['font-family'] ?? '',
            'body_weight'    => $body['font-weight'] ?? '',
            'heading_font'   => $h1['font-family'] ?? '',
            'heading_weight' => $h1['font-weight'] ?? '',
        ];
    }

    /** @return array<string, mixed>|null */
    private static function generatepress(): ?array
    {
        $g = get_option('generate_settings');
        if (!is_array($g)) {
            return null;
        }

        // Colours are often var(--accent) and friends, defined in the
        // global colour list.
        $palette = [];
        foreach (is_array($g['global_colors'] ?? null) ? $g['global_colors'] : [] as $c) {
            if (is_array($c) && is_scalar($c['slug'] ?? null) && is_scalar($c['color'] ?? null)) {
                $palette[(string) $c['slug']] = (string) $c['color'];
            }
        }
        $colour = static function (mixed $v) use ($palette): mixed {
            if (is_string($v) && preg_match('/^\s*var\(--([\w-]+)\)\s*$/', $v, $m)) {
                return $palette[$m[1]] ?? '';
            }

            return $v;
        };

        $body = [];
        $heading = [];
        foreach (is_array($g['typography'] ?? null) ? $g['typography'] : [] as $t) {
            if (!is_array($t) || !is_string($t['selector'] ?? null)) {
                continue;
            }
            if ($t['selector'] === 'body' && $body === []) {
                $body = $t;
            } elseif (in_array($t['selector'], ['all-headings', 'h1'], true) && $heading === []) {
                $heading = $t;
            }
        }

        return [
            'background'     => $colour($g['background_color'] ?? ''),
            'text'           => $colour($g['text_color'] ?? ''),
            'accent'         => $palette['accent'] ?? $colour($g['link_color'] ?? ''),
            'body_font'      => $body['fontFamily'] ?? ($g['font_body'] ?? ''),
            'body_weight'    => $body['fontWeight'] ?? '',
            'heading_font'   => $heading['fontFamily'] ?? '',
            'heading_weight' => $heading['fontWeight'] ?? '',
        ];
    }

    /** WordPress's own: the theme logo setting most themes use, then the site icon. @return array<string, mixed> */
    private static function core(): array
    {
        $id = (int) get_theme_mod('custom_logo');
        $logo = $id > 0 ? (string) wp_get_attachment_url($id) : '';

        return ['logo_url' => $logo !== '' ? $logo : (string) get_site_icon_url(512)];
    }
}
