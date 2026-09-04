<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink\Translation;

/**
 * Polylang, driven entirely through its documented pll_* API.
 *
 * Polylang stores the language as a hidden `language` taxonomy and the
 * translation group as a `post_translations` term whose description holds a
 * serialised map. None of that is a public contract, so nothing here touches it
 * — every read and write goes through the api.php helpers, which have been
 * stable across 3.x and are what the plugin's own docs point at.
 */
final class PolylangAdapter implements TranslationAdapter
{
    public function available(): bool
    {
        // pll_insert_post landed in 3.7; without it a translation cannot be
        // created and linked atomically, and doing it in three steps leaves
        // orphaned posts behind when the middle one fails.
        return function_exists('pll_languages_list') && function_exists('pll_insert_post');
    }

    public function plugin(): string
    {
        return 'polylang';
    }

    public function languages(): array
    {
        if (!$this->available()) {
            return [];
        }

        // Three parallel calls rather than reaching into PLL()->model: each
        // returns the same filtered list in the same order, and only the
        // documented `fields` argument is used.
        $slugs   = (array) pll_languages_list(['fields' => 'slug']);
        $names   = (array) pll_languages_list(['fields' => 'name']);
        $locales = (array) pll_languages_list(['fields' => 'locale']);
        $default = $this->default_language();

        $out = [];
        foreach (array_values($slugs) as $i => $slug) {
            $out[] = [
                'slug'       => (string) $slug,
                'name'       => (string) ($names[$i] ?? $slug),
                'locale'     => (string) ($locales[$i] ?? ''),
                'is_default' => (string) $slug === $default,
            ];
        }

        // Default first — an agent reading the list should see the language
        // everything is translated *from* before the ones it translates into.
        usort($out, static fn (array $a, array $b): int => ($b['is_default'] <=> $a['is_default']));

        return $out;
    }

    public function default_language(): string
    {
        return $this->available() ? (string) pll_default_language('slug') : '';
    }

    public function language_of(int $post_id): string
    {
        if (!$this->available()) {
            return '';
        }

        $lang = pll_get_post_language($post_id, 'slug');

        return is_string($lang) ? $lang : '';
    }

    public function language_of_term(int $term_id): string
    {
        if (!$this->available() || !function_exists('pll_get_term_language')) {
            return '';
        }

        $lang = pll_get_term_language($term_id, 'slug');

        return is_string($lang) ? $lang : '';
    }

    public function translations(int $post_id): array
    {
        if (!$this->available()) {
            return [];
        }

        $out = [];
        foreach ((array) pll_get_post_translations($post_id) as $lang => $id) {
            $out[(string) $lang] = (int) $id;
        }

        return $out;
    }

    public function is_translated_type(string $post_type): bool
    {
        return $this->available()
            && function_exists('pll_is_translated_post_type')
            && (bool) pll_is_translated_post_type($post_type);
    }

    public function set_language(int $post_id, string $lang): bool|\WP_Error
    {
        if (!$this->available()) {
            return new \WP_Error('no_translation_plugin', 'No usable multilingual plugin on this site.');
        }

        // Returns false both when the language is already assigned and when the
        // assignment fails, so the caller checks the result rather than the
        // return value — see ChangeService, which re-reads the language.
        pll_set_post_language($post_id, $lang);

        return $this->language_of($post_id) === $lang
            ? true
            : new \WP_Error('set_language_failed', 'Polylang did not accept that language for this post.');
    }

    public function insert(array $postarr, string $lang, array $translations): int|\WP_Error
    {
        if (!$this->available()) {
            return new \WP_Error('no_translation_plugin', 'No usable multilingual plugin on this site.');
        }

        $postarr['translations'] = $translations;
        $id = pll_insert_post($postarr, $lang);
        if (is_wp_error($id)) {
            return $id;
        }

        // pll_insert_post joins the group, but the new post's own id cannot be
        // in the array it was given. Saving the completed group is what makes
        // the pair navigable from either side.
        if (function_exists('pll_save_post_translations')) {
            pll_save_post_translations($translations + [$lang => (int) $id]);
        }

        return (int) $id;
    }
}
