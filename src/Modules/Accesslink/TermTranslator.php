<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Modules\Accesslink\Translation\TranslationAdapter;

/**
 * Categories, tags and product attribute terms in another language.
 *
 * A translated shop is mostly words that are not in any post: the category a
 * product sits in, the "Material: Silver" under its title, the filter in the
 * sidebar. Polylang gives each of those terms a translation of its own, and
 * until this existed an agent could translate every product and still leave
 * the shop speaking the source language around them.
 *
 * Proposing writes nothing. A term has no draft state — a new one shows up in
 * menus, filters and the term widgets the moment it exists — so the whole
 * write waits for approval, and the staleness question is whether the
 * translation still reads as it did when the agent proposed.
 */
final class TermTranslator
{
    /** WooCommerce's per-term product counts belong to the term they count. */
    private const META_NOT_COPIED_PREFIX = 'product_count_';

    private const LIST_MAX = 500;

    public function __construct(
        private readonly TranslationAdapter $tr,
        private readonly bool $products,
    ) {}

    /**
     * Taxonomies whose terms can be translated here: the blog's and the site's
     * own, and WooCommerce's categories, tags, brands and global attributes
     * where agents may touch products.
     *
     * @return array<string, string> taxonomy => label
     */
    public function taxonomies(): array
    {
        $names = array_values(PostApplier::term_fields());
        if ($this->products) {
            $names[] = 'product_brand';
            if (function_exists('wc_get_attribute_taxonomy_names')) {
                $names = array_merge($names, wc_get_attribute_taxonomy_names());
            }
        } else {
            $names = array_diff($names, array_values(ProductApplier::TERM_FIELDS));
        }

        $out = [];
        foreach (array_unique($names) as $taxonomy) {
            $object = get_taxonomy($taxonomy);
            if ($object instanceof \WP_Taxonomy && $this->tr->is_translated_taxonomy($taxonomy)) {
                $out[$taxonomy] = (string) $object->labels->name;
            }
        }

        return $out;
    }

    /**
     * A taxonomy's terms in every language, with their translation groups.
     *
     * A translation that still carries its original's name is marked
     * `untranslated`: that is what the shop's own sync leaves behind when a
     * translated product needs a category that has no translation yet.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function list(string $taxonomy, string $lang = '', string $search = ''): array|\WP_Error
    {
        $taxonomies = $this->taxonomies();
        if (!isset($taxonomies[$taxonomy])) {
            return self::bad_taxonomy($taxonomy, $taxonomies);
        }

        // 'lang' => '' asks Polylang for every language; without it, get_terms
        // answers in whatever language the request happens to resolve to.
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => self::LIST_MAX,
            'search'     => $search,
            'lang'       => '',
        ]);
        if (is_wp_error($terms)) {
            return $terms;
        }

        $default = $this->tr->default_language();
        $rows = [];
        foreach ($terms as $term) {
            $term_lang = $this->tr->language_of_term((int) $term->term_id);
            if ($lang !== '' && $term_lang !== $lang) {
                continue;
            }
            $group = $this->tr->term_translations((int) $term->term_id);
            $row = [
                'id'           => (int) $term->term_id,
                'name'         => $term->name,
                'slug'         => $term->slug,
                'description'  => $term->description,
                'parent'       => (int) $term->parent,
                'count'        => (int) $term->count,
                'lang'         => $term_lang,
                'translations' => $group,
            ];
            $source_id = (int) ($group[$default] ?? 0);
            if ($term_lang !== $default && $source_id > 0 && $source_id !== (int) $term->term_id) {
                $row['source_id'] = $source_id;
                $source = get_term($source_id, $taxonomy);
                if ($source instanceof \WP_Term && $source->name === $term->name) {
                    $row['untranslated'] = true;
                }
            }
            $rows[] = $row;
        }

        return [
            'taxonomy'         => $taxonomy,
            'label'            => $taxonomies[$taxonomy],
            'default_language' => $default,
            'terms'            => $rows,
        ];
    }

    /**
     * Check a translate_term proposal and settle what it will write.
     *
     * @param array<string, mixed> $input
     * @return array{taxonomy: string, source_id: int, lang: string, existing_id: int, name: string, slug: string, description: ?string}|\WP_Error
     */
    public function prepare(array $input): array|\WP_Error
    {
        $taxonomy = sanitize_key((string) ($input['taxonomy'] ?? ''));
        $taxonomies = $this->taxonomies();
        if (!isset($taxonomies[$taxonomy])) {
            return self::bad_taxonomy($taxonomy, $taxonomies);
        }

        $term_id = (int) ($input['target_id'] ?? 0);
        $term = $term_id > 0 ? get_term($term_id, $taxonomy) : null;
        if (!$term instanceof \WP_Term) {
            return new \WP_Error(
                'term_not_found',
                sprintf('No %s term with id %d — see GET /terms?taxonomy=%s.', $taxonomy, $term_id, $taxonomy),
                ['status' => 404],
            );
        }

        $languages = array_column($this->tr->languages(), 'slug');
        $lang = sanitize_key((string) ($input['lang'] ?? ''));
        if (!in_array($lang, $languages, true)) {
            return new \WP_Error('bad_language', sprintf('lang must be one of: %s.', implode(', ', $languages)), ['status' => 400]);
        }
        $default = $this->tr->default_language();
        if ($lang === $default) {
            return new \WP_Error(
                'source_language',
                sprintf('translate_term writes translations; the %s terms are the originals. Rename an original in wp-admin.', $default),
                ['status' => 400],
            );
        }
        if ($this->tr->language_of_term($term_id) === '') {
            return new \WP_Error(
                'term_has_no_language',
                'This term has no language assigned, so nothing can be linked to it. Assign one in wp-admin first.',
                ['status' => 409],
            );
        }

        $group = array_filter(
            $this->tr->term_translations($term_id),
            static fn (int $id): bool => get_term($id, $taxonomy) instanceof \WP_Term,
        );
        $source_id = (int) ($group[$default] ?? 0);
        if ($source_id <= 0) {
            return new \WP_Error(
                'no_source',
                sprintf('This term has no %s original to translate from.', $default),
                ['status' => 409],
            );
        }
        $existing_id = (int) ($group[$lang] ?? 0);

        $name = trim(sanitize_text_field((string) ($input['name'] ?? '')));
        if ($name === '') {
            return new \WP_Error('no_name', 'name is required: the term\'s name in the target language.', ['status' => 400]);
        }
        $slug = isset($input['slug']) ? sanitize_title((string) $input['slug']) : '';
        $description = array_key_exists('description', $input)
            ? trim(wp_kses_post((string) $input['description']))
            : null;

        if ($slug !== '') {
            $taken = get_terms(['taxonomy' => $taxonomy, 'slug' => $slug, 'hide_empty' => false, 'lang' => '']);
            $owner = !is_wp_error($taken) && $taken !== [] ? $taken[0] : null;
            if ($owner instanceof \WP_Term && (int) $owner->term_id !== $existing_id) {
                return new \WP_Error(
                    'slug_taken',
                    sprintf(
                        'The slug %s is already used by the term "%s" (%d). Slugs are unique within a taxonomy; choose another.',
                        $slug,
                        $owner->name,
                        (int) $owner->term_id,
                    ),
                    ['status' => 409],
                );
            }
        }

        if ($existing_id > 0) {
            $existing = get_term($existing_id, $taxonomy);
            if ($existing instanceof \WP_Term
                && $existing->name === $name
                && ($slug === '' || $existing->slug === $slug)
                && ($description === null || $existing->description === $description)) {
                return new \WP_Error('no_change', 'The translation already reads exactly like that.', ['status' => 400]);
            }
        }

        return [
            'taxonomy'    => $taxonomy,
            'source_id'   => $source_id,
            'lang'        => $lang,
            'existing_id' => $existing_id,
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description,
        ];
    }

    /** The translation in $lang of the term group $source_id belongs to, 0 when there is none. */
    public function translation_of(int $source_id, string $taxonomy, string $lang): int
    {
        $id = (int) ($this->tr->term_translations($source_id)[$lang] ?? 0);

        return $id > 0 && get_term($id, $taxonomy) instanceof \WP_Term ? $id : 0;
    }

    /** A translation still named like its original: made by a sync, not by a person. */
    public function is_placeholder(int $term_id, int $source_id, string $taxonomy): bool
    {
        $term = get_term($term_id, $taxonomy);
        $source = get_term($source_id, $taxonomy);

        return $term instanceof \WP_Term && $source instanceof \WP_Term && $term->name === $source->name;
    }

    /** What the staleness gate compares: the translation's words, or that there is none. */
    public function state(int $term_id, string $taxonomy): string
    {
        $term = $term_id > 0 ? get_term($term_id, $taxonomy) : null;

        return $term instanceof \WP_Term
            ? hash('sha256', (string) wp_json_encode([$term->name, $term->slug, $term->description]))
            : 'none';
    }

    /**
     * Write the translation: rename the existing one, or create it in the
     * source's group, under the translation of the source's parent where that
     * exists, with the source's term meta (a category's image and display
     * settings).
     *
     * @param array<string, mixed> $p a prepare() result
     * @return int|\WP_Error the translation's term id
     */
    public function apply(array $p): int|\WP_Error
    {
        $taxonomy = (string) $p['taxonomy'];
        $args = ['name' => (string) $p['name']];
        if ((string) $p['slug'] !== '') {
            $args['slug'] = (string) $p['slug'];
        }
        if ($p['description'] !== null) {
            $args['description'] = (string) $p['description'];
        }

        $existing = (int) $p['existing_id'];
        if ($existing > 0) {
            $updated = $this->tr->update_term($existing, $taxonomy, $args);

            return is_wp_error($updated) ? $updated : $existing;
        }

        $source = get_term((int) $p['source_id'], $taxonomy);
        if (!$source instanceof \WP_Term) {
            return new \WP_Error('term_not_found', 'The original term no longer exists.');
        }
        $lang = (string) $p['lang'];
        $parent = $source->parent > 0 ? $this->translation_of((int) $source->parent, $taxonomy, $lang) : 0;
        unset($args['name']);
        $id = $this->tr->insert_term(
            (string) $p['name'],
            $taxonomy,
            $lang,
            $args + ['parent' => $parent],
            $this->tr->term_translations((int) $source->term_id),
        );
        if (is_wp_error($id)) {
            return $id;
        }

        foreach (get_term_meta((int) $source->term_id) as $key => $values) {
            if (str_starts_with((string) $key, self::META_NOT_COPIED_PREFIX)) {
                continue;
            }
            foreach ((array) $values as $value) {
                add_term_meta($id, (string) $key, maybe_unserialize($value));
            }
        }

        return $id;
    }

    /** @param array<string, string> $taxonomies */
    private static function bad_taxonomy(string $taxonomy, array $taxonomies): \WP_Error
    {
        return new \WP_Error(
            'bad_taxonomy',
            sprintf(
                '%s is not a translatable taxonomy here. Translatable: %s.',
                $taxonomy === '' ? 'taxonomy is required;' : $taxonomy,
                $taxonomies === [] ? 'none' : implode(', ', array_keys($taxonomies)),
            ),
            ['status' => 400],
        );
    }
}
