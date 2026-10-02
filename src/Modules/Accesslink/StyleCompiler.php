<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * GenerateBlocks' style object → CSS, server-side.
 *
 * GenerateBlocks compiles a global style's CSS in the browser (the Styles
 * Builder's getCss) and saves data and CSS together; nothing in PHP compiles.
 * A style proposed through Accesslink is applied without a browser, so this
 * does the same job for the same object shape:
 *
 *   {"paddingTop": "var(--space-lg)", "--local": "1px",
 *    "&:hover": {"backgroundColor": "…"},          nested on the selector itself
 *    " > .child": {…}, ".desc": {…},               descendants
 *    "@media (max-width:767px)": {"display": "grid", "&:hover": {…}}}
 *
 * Properties are camelCase (the UI stores longhands) or custom properties,
 * emitted alphabetically and minified like GenerateBlocks' own pipeline. The
 * output is equivalent rather than byte-identical (no longhand merging); the
 * next save in the Styles Builder recompiles from the same data.
 *
 * validate() is the safety boundary: the CSS ends up in a stylesheet on every
 * page, so nothing that can leave a declaration (braces, semicolons, comments,
 * markup) or load anything (@import, url() other than a plain address,
 * expression(), javascript:) gets through.
 */
final class StyleCompiler
{
    public const MAX_DECLARATIONS = 200;
    public const MAX_DEPTH        = 3;

    private const PROPERTY = '/^(--[a-zA-Z0-9_-]+|[a-zA-Z][a-zA-Z0-9]*)$/';
    private const AT_RULE  = '/^@(media|supports|container)\s+[^{};<>\\\\]+$/';
    private const NESTED   = '/^[&a-zA-Z0-9\s>+~:._()\[\]="\'#*,-]+$/';

    /**
     * Problems with a style object, in words an agent can act on; [] when fine.
     *
     * @return list<string>
     */
    public static function validate(array $styles): array
    {
        $issues = [];
        $count = 0;
        self::walk($styles, 0, $issues, $count, '');
        if ($count === 0 && $issues === []) {
            $issues[] = 'The style has no declarations.';
        }
        if ($count > self::MAX_DECLARATIONS) {
            $issues[] = sprintf('%d declarations; at most %d per style.', $count, self::MAX_DECLARATIONS);
        }

        return array_values(array_unique($issues));
    }

    /** The CSS for a selector; call validate() first. */
    public static function compile(string $selector, array $styles): string
    {
        $out = self::rule($selector, $styles);
        foreach (self::at_rules($styles) as $at => $body) {
            $inner = self::rule($selector, $body);
            if ($inner !== '') {
                $out .= $at . '{' . $inner . '}';
            }
        }

        return $out;
    }

    /** A safe declaration value: no way out of the declaration, nothing loaded. */
    public static function safe_value(string $value): bool
    {
        if ($value === '' || strlen($value) > 500) {
            return false;
        }
        if (preg_match('/[<>{};\\\\\n\r]|\/\*|\*\/|@import|expression\s*\(|javascript:|behavior\s*:/i', $value)) {
            return false;
        }
        if (substr_count($value, '(') !== substr_count($value, ')')
            || substr_count($value, '"') % 2 !== 0
            || substr_count($value, "'") % 2 !== 0) {
            return false;
        }
        // url() only for a plain http(s), root-relative or relative address.
        if (preg_match_all('/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i', $value, $m)) {
            foreach ($m[2] as $address) {
                if (!preg_match('#^(https?://|/|\./|\.\./|[a-z0-9_-]+/)#i', $address)) {
                    return false;
                }
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------

    private static function walk(array $node, int $depth, array &$issues, int &$count, string $where): void
    {
        if ($depth >= self::MAX_DEPTH) {
            $issues[] = sprintf('Nested deeper than %d levels at "%s".', self::MAX_DEPTH, $where);

            return;
        }
        foreach ($node as $key => $value) {
            $key = (string) $key;
            $at = $where === '' ? $key : $where . ' → ' . $key;
            if (is_array($value)) {
                if (str_starts_with($key, '@')) {
                    if (!preg_match(self::AT_RULE, $key)) {
                        $issues[] = sprintf('"%s": only @media, @supports and @container queries.', $key);
                        continue;
                    }
                } elseif (!preg_match(self::NESTED, $key) || str_contains($key, '/*')) {
                    $issues[] = sprintf('"%s" is not a selector this accepts (use "&:hover", " > .child", ".desc", "@media (…)").', $key);
                    continue;
                }
                self::walk($value, $depth + 1, $issues, $count, $at);
                continue;
            }
            if (!preg_match(self::PROPERTY, $key)) {
                $issues[] = sprintf('"%s" is not a property name (camelCase, e.g. paddingTop, or a custom property --name).', $key);
                continue;
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                $issues[] = sprintf('"%s": the value must be a string.', $at);
                continue;
            }
            if (!self::safe_value(trim((string) $value))) {
                $issues[] = sprintf('"%s": the value "%s" is not allowed (no braces, semicolons, comments, markup, @import, expression(), url() other than a plain address).', $at, mb_substr((string) $value, 0, 60));
                continue;
            }
            $count++;
        }
    }

    /** One selector's declarations plus its nested selectors (at-rules excluded). */
    private static function rule(string $selector, array $styles): string
    {
        $declarations = [];
        $nested = [];
        foreach ($styles as $key => $value) {
            $key = (string) $key;
            if (is_array($value)) {
                if (!str_starts_with($key, '@')) {
                    $nested[$key] = $value;
                }
                continue;
            }
            $declarations[self::property($key)] = trim((string) $value);
        }
        ksort($declarations, SORT_STRING);

        $out = '';
        if ($declarations !== []) {
            $out .= $selector . '{' . self::declarations($declarations) . '}';
        }
        ksort($nested, SORT_STRING);
        foreach ($nested as $key => $body) {
            $out .= self::rule(self::nest($selector, $key), $body);
            // At-rules inside a nested selector are hoisted, as GenerateBlocks does.
            foreach (self::at_rules($body) as $at => $inner_body) {
                $inner = self::rule(self::nest($selector, $key), $inner_body);
                if ($inner !== '') {
                    $out .= $at . '{' . $inner . '}';
                }
            }
        }

        return $out;
    }

    /** @return array<string, array> at-rule => body, in the order given */
    private static function at_rules(array $styles): array
    {
        $out = [];
        foreach ($styles as $key => $value) {
            if (is_array($value) && str_starts_with((string) $key, '@')) {
                $out[preg_replace('/\s*:\s*/', ':', (string) $key) ?? (string) $key] = $value;
            }
        }

        return $out;
    }

    /** "&:hover" on ".a" → ".a:hover"; " > .b" or ".b" → ".a > .b" / ".a .b"; comma lists cross. */
    private static function nest(string $selector, string $key): string
    {
        $parents = array_map('trim', explode(',', $selector));
        $children = array_map('trim', explode(',', $key));
        $out = [];
        foreach ($parents as $parent) {
            foreach ($children as $child) {
                if (str_starts_with($child, '&')) {
                    $out[] = $parent . substr($child, 1);
                } else {
                    $out[] = $parent . ' ' . ltrim($child);
                }
            }
        }

        return implode(',', $out);
    }

    private static function property(string $key): string
    {
        if (str_starts_with($key, '--')) {
            return $key;
        }
        $kebab = strtolower((string) preg_replace('/([A-Z])/', '-$1', $key));
        // Vendor prefixes arrive as WebkitX / MozX / msX.
        return preg_match('/^(webkit|moz|ms|o)-/', $kebab) ? '-' . $kebab : $kebab;
    }

    /** @param array<string, string> $declarations */
    private static function declarations(array $declarations): string
    {
        $out = [];
        foreach ($declarations as $property => $value) {
            $out[] = $property . ':' . $value;
        }

        return implode(';', $out);
    }
}
