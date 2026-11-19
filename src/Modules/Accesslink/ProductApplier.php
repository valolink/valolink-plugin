<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * The fields WooCommerce owns on a product, read and written through its own
 * CRUD rather than around it.
 *
 * A product is a post, so its title, description, SEO and image already go
 * through PostApplier. What is not a post column is where a product's money
 * lives: price, sale and stock sit in postmeta *and* in wc_product_meta_lookup,
 * which the shop's filtering and sorting query instead of the meta. Writing the
 * meta directly desynchronises the two. So every write here goes through
 * wc_get_product() setters and one save(), which keeps the lookup table, Woo's
 * caches and every integration listening on woocommerce_update_product in step.
 *
 * Field names are WooCommerce's own REST API names, so what an agent already
 * knows about Woo carries over.
 */
final class ProductApplier
{
    public const POST_TYPE = 'product';

    /**
     * What is sold and for how much. Opt-in per site, like menus: these change
     * what customers pay and can order the moment a change is approved.
     */
    public const COMMERCE_FIELDS = [
        'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to',
        'sku', 'stock_status', 'manage_stock', 'stock_quantity', 'backorders',
    ];

    /** Where a product shows up — the same class of decision as publishing it. */
    public const MERCHANDISING_FIELDS = ['catalog_visibility', 'featured'];

    public const FIELDS = [...self::COMMERCE_FIELDS, ...self::MERCHANDISING_FIELDS];

    public const PRICE_FIELDS = ['regular_price', 'sale_price'];

    public const DATE_FIELDS = ['date_on_sale_from', 'date_on_sale_to'];

    private const STOCK_FIELDS = ['stock_status', 'manage_stock', 'stock_quantity', 'backorders'];

    /** Product taxonomies. `categories` and `tags` are the blog's; products have their own. */
    public const TERM_FIELDS = ['product_categories' => 'product_cat', 'product_tags' => 'product_tag'];

    /**
     * The words of a product's own (custom) attributes: their names and
     * options, which a translation keeps in its own language. Only words —
     * which attributes and how many options stay as they are, and the
     * variations follow the new words. A global attribute's words are terms,
     * translated with `translate_term`.
     */
    public const ATTRIBUTE_FIELD = 'attribute_words';

    /**
     * What each product type accepts. A variable product's prices and stock
     * live on its variations, a grouped one has none of its own, and an
     * external one is sold elsewhere. Types a plugin adds — bundles,
     * subscriptions — get the conservative set, since their prices are
     * computed somewhere this class has never seen.
     */
    private const TYPE_FIELDS = [
        'simple'   => self::FIELDS,
        'external' => [
            'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to',
            'sku', 'catalog_visibility', 'featured',
        ],
    ];

    private const OTHER_TYPE_FIELDS = ['sku', 'catalog_visibility', 'featured'];

    public static function available(): bool
    {
        return function_exists('wc_get_product') && post_type_exists(self::POST_TYPE);
    }

    /** Product-level stock does nothing while the shop-wide setting is off. */
    public static function store_manages_stock(): bool
    {
        return get_option('woocommerce_manage_stock', 'yes') === 'yes';
    }

    /** @return array<int, string> */
    public static function fields_for_type(string $type): array
    {
        return self::TYPE_FIELDS[$type] ?? self::OTHER_TYPE_FIELDS;
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * What the shop charges now, and every field in the names a proposal sends
     * back.
     *
     * @return array<string, mixed>|null
     */
    public function read(int $post_id, bool $commerce): ?array
    {
        $product = wc_get_product($post_id);
        if (!$product instanceof \WC_Product) {
            return null;
        }

        $out = [
            'type'               => $product->get_type(),
            'currency'           => get_woocommerce_currency(),
            'prices_include_tax' => wc_prices_include_tax(),
            'price'              => (string) $product->get_price('edit'),
            'on_sale'            => $product->is_on_sale('edit'),
        ];
        foreach (self::FIELDS as $field) {
            $out[$field] = self::typed($field, self::value_of($product, $field));
        }
        if ($product->is_type('variable')) {
            $out['variations'] = count($product->get_children());
        }
        $out['attributes'] = self::attributes($product);

        // Said outright rather than left to a refused proposal: which of the
        // fields above this product takes here, given its type and whether
        // the operator has switched price and stock edits on.
        $out['proposable'] = array_values(array_filter(
            self::fields_for_type($product->get_type()),
            static fn (string $f): bool => $commerce || !in_array($f, self::COMMERCE_FIELDS, true),
        ));
        if (self::custom_attributes($product) !== []) {
            $out['proposable'][] = self::ATTRIBUTE_FIELD;
        }

        return $out;
    }

    /**
     * The product's attributes as the shop shows them: a custom one with its
     * options, a global one with its terms (whose ids `translate_term` takes).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function attributes(\WC_Product $product): array
    {
        $out = [];
        foreach ($product->get_attributes('edit') as $attribute) {
            if (!$attribute instanceof \WC_Product_Attribute) {
                continue;
            }
            $row = [
                'name'      => $attribute->is_taxonomy() ? wc_attribute_label($attribute->get_name()) : $attribute->get_name(),
                'taxonomy'  => $attribute->is_taxonomy() ? $attribute->get_name() : null,
                'variation' => $attribute->get_variation(),
            ];
            if ($attribute->is_taxonomy()) {
                $row['terms'] = array_map(
                    static fn (\WP_Term $t): array => ['id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug],
                    (array) ($attribute->get_terms() ?: []),
                );
            } else {
                $row['options'] = array_values(array_map('strval', $attribute->get_options()));
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @return array<int, \WC_Product_Attribute> */
    private static function custom_attributes(\WC_Product $product): array
    {
        return array_values(array_filter(
            $product->get_attributes('edit'),
            static fn ($a): bool => $a instanceof \WC_Product_Attribute && !$a->is_taxonomy(),
        ));
    }

    /** The current value of attribute_words, for the staleness hash and the review diff. */
    public function read_attribute_words(int $post_id): string
    {
        $product = wc_get_product($post_id);

        return $product instanceof \WC_Product ? self::attribute_lines($product, []) : '';
    }

    /**
     * One line per custom attribute, "Name: option | option", with $words
     * applied — so the queue diffs a proposal line by line against what the
     * product has.
     *
     * @param array<string, array<string, mixed>> $words
     */
    public static function attribute_lines(\WC_Product $product, array $words): string
    {
        $lines = [];
        foreach (self::custom_attributes($product) as $attribute) {
            $name = $attribute->get_name();
            $new = is_array($words[$name] ?? null) ? $words[$name] : [];
            $map = is_array($new['options'] ?? null) ? $new['options'] : [];
            $options = array_map(
                static fn ($o): string => (string) ($map[(string) $o] ?? $o),
                $attribute->get_options(),
            );
            $lines[] = (string) ($new['name'] ?? $name) . ': ' . implode(' | ', $options);
        }

        return implode("\n", $lines);
    }

    /**
     * Strings cleaned, shape kept. A malformed entry stays in, marked, so
     * check() refuses it by name instead of the proposal quietly shrinking.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sanitize_attribute_words(mixed $value): array
    {
        if (!is_array($value)) {
            return ['' => ['invalid' => true]];
        }

        $out = [];
        foreach ($value as $name => $words) {
            $name = sanitize_text_field((string) $name);
            if (!is_array($words)) {
                $out[$name] = ['invalid' => true];
                continue;
            }
            $entry = [];
            if (array_key_exists('name', $words)) {
                $entry['name'] = sanitize_text_field((string) $words['name']);
            }
            if (array_key_exists('options', $words)) {
                if (!is_array($words['options'])) {
                    $entry['invalid'] = true;
                } else {
                    foreach ($words['options'] as $old => $new) {
                        $entry['options'][sanitize_text_field((string) $old)] = sanitize_text_field(is_scalar($new) ? (string) $new : '');
                    }
                }
            }
            $out[$name] = $entry;
        }

        return $out;
    }

    /**
     * Whether attribute_words fits this product: every name a custom attribute
     * it has, every option one of that attribute's, and the result still a
     * valid set — no empty or duplicate option, no two attributes left with
     * the same name.
     *
     * @param array<string, array<string, mixed>> $words
     */
    private static function check_attribute_words(\WC_Product $product, array $words): true|\WP_Error
    {
        $shape = 'attribute_words maps a custom attribute\'s current name to its words: '
            . '{"Malli": {"name": "Model", "options": {"Tappi": "Stud", "Koukku": "Hook"}}}.';
        if ($words === []) {
            return new \WP_Error('bad_attribute_words', $shape, ['status' => 400]);
        }
        if ($product->get_id() === 0) {
            return new \WP_Error(
                'attribute_words_on_create',
                'attribute_words translates the attributes a product already has; it cannot be part of a create.',
                ['status' => 400],
            );
        }

        $custom = [];
        foreach (self::custom_attributes($product) as $attribute) {
            $custom[$attribute->get_name()] = $attribute;
        }
        $global = [];
        foreach ($product->get_attributes('edit') as $attribute) {
            if ($attribute instanceof \WC_Product_Attribute && $attribute->is_taxonomy()) {
                $global[wc_attribute_label($attribute->get_name())] = $attribute->get_name();
                $global[$attribute->get_name()] = $attribute->get_name();
            }
        }

        foreach ($words as $name => $entry) {
            if (!isset($custom[$name])) {
                if (isset($global[$name])) {
                    return new \WP_Error(
                        'global_attribute',
                        sprintf(
                            '%s is a global attribute (%s), so its words are terms. Translate them with translate_term — see GET /terms?taxonomy=%s.',
                            $name,
                            $global[$name],
                            $global[$name],
                        ),
                        ['status' => 400],
                    );
                }

                return new \WP_Error(
                    'unknown_attribute',
                    sprintf(
                        'This product has no custom attribute "%s". Its custom attributes: %s.',
                        $name,
                        $custom === [] ? 'none' : implode(', ', array_keys($custom)),
                    ),
                    ['status' => 400],
                );
            }
            if (!empty($entry['invalid'])) {
                return new \WP_Error('bad_attribute_words', $shape, ['status' => 400]);
            }
            if (array_key_exists('name', $entry) && $entry['name'] === '') {
                return new \WP_Error('bad_attribute_name', sprintf('The new name for %s is empty.', $name), ['status' => 400]);
            }

            $options = array_map('strval', $custom[$name]->get_options());
            $map = (array) ($entry['options'] ?? []);
            foreach ($map as $old => $new) {
                if (!in_array((string) $old, $options, true)) {
                    return new \WP_Error(
                        'unknown_option',
                        sprintf('"%s" is not an option of %s. Its options: %s.', $old, $name, implode(' | ', $options)),
                        ['status' => 400],
                    );
                }
                if ($new === '' || str_contains((string) $new, WC_DELIMITER)) {
                    return new \WP_Error(
                        'bad_option',
                        sprintf('The new word for "%s" must be non-empty and contain no "%s", which separates options.', $old, WC_DELIMITER),
                        ['status' => 400],
                    );
                }
            }
            $after = array_map(static fn (string $o): string => mb_strtolower((string) ($map[$o] ?? $o)), $options);
            if (count(array_unique($after)) !== count($after)) {
                return new \WP_Error(
                    'duplicate_option',
                    sprintf('Two options of %s would read the same, and a customer could not tell them apart.', $name),
                    ['status' => 400],
                );
            }
        }

        // WooCommerce keys a custom attribute, and its variations' choices, by
        // the sanitised name, so two names that sanitise alike would merge.
        $keys = [];
        foreach ($product->get_attributes('edit') as $attribute) {
            if (!$attribute instanceof \WC_Product_Attribute) {
                continue;
            }
            if ($attribute->is_taxonomy()) {
                $keys[] = $attribute->get_name();
                continue;
            }
            $key = sanitize_title((string) ($words[$attribute->get_name()]['name'] ?? $attribute->get_name()));
            if (str_starts_with($key, 'pa_')) {
                return new \WP_Error('bad_attribute_name', 'A custom attribute\'s name cannot start with "pa_", which marks global attributes.', ['status' => 400]);
            }
            $keys[] = $key;
        }
        if (count(array_unique($keys)) !== count($keys)) {
            return new \WP_Error(
                'duplicate_attribute',
                'Two attributes would end up with the same name. Give each its own.',
                ['status' => 400],
            );
        }

        return true;
    }

    /**
     * Put the new words on the product (saved by the caller) and its default
     * choices. Returns what the variations need: per old attribute key, the
     * new key and the option renames.
     *
     * Each attribute is replaced by a changed clone. Modified in place, the
     * array WooCommerce compares against would be the same objects, the
     * product would see no change and the save would write nothing.
     *
     * @param array<string, array<string, mixed>> $words
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    private static function set_attribute_words(\WC_Product $product, array $words): array
    {
        $renames = [];
        $attributes = [];
        foreach ($product->get_attributes('edit') as $key => $attribute) {
            if (!$attribute instanceof \WC_Product_Attribute || $attribute->is_taxonomy() || !isset($words[$attribute->get_name()])) {
                $attributes[$key] = $attribute;
                continue;
            }
            $name = $attribute->get_name();
            $entry = $words[$name];
            $map = [];
            foreach (array_map('strval', $attribute->get_options()) as $option) {
                $map[$option] = (string) ($entry['options'][$option] ?? $option);
            }
            $new_name = (string) ($entry['name'] ?? $name);

            $changed = clone $attribute;
            $changed->set_name($new_name);
            $changed->set_options(array_values($map));
            $attributes[sanitize_title($new_name)] = $changed;
            $renames[sanitize_title($name)] = [sanitize_title($new_name), $map];
        }

        $product->set_attributes($attributes);
        $product->set_default_attributes(self::rename_choices($product->get_default_attributes('edit'), $renames));

        return $renames;
    }

    /**
     * A variation's choices (or the product's defaults), attribute key =>
     * option, in the new words. A choice of "any" stays any.
     *
     * @param array<string, string> $choices
     * @param array<string, array{0: string, 1: array<string, string>}> $renames
     * @return array<string, string>
     */
    private static function rename_choices(array $choices, array $renames): array
    {
        $out = [];
        foreach ($choices as $key => $value) {
            if (!isset($renames[$key])) {
                $out[$key] = $value;
                continue;
            }
            [$new_key, $map] = $renames[$key];
            $new_value = (string) $value;
            if ($new_value !== '') {
                foreach ($map as $old => $new) {
                    if ($value === $old || sanitize_title((string) $value) === sanitize_title($old)) {
                        $new_value = $new;
                        break;
                    }
                }
            }
            $out[$new_key] = $new_value;
        }

        return $out;
    }

    /**
     * Every variation of the product, published or not, re-pointed at the
     * renamed attributes — without this the variation menu on the product
     * page offers words no variation matches, and nothing can be bought.
     *
     * @param array<string, array{0: string, 1: array<string, string>}> $renames
     */
    private static function rename_variation_choices(\WC_Product $product, array $renames): void
    {
        if ($renames === [] || !$product->is_type('variable')) {
            return;
        }
        $ids = get_posts([
            'post_type'   => 'product_variation',
            'post_parent' => $product->get_id(),
            'post_status' => ['publish', 'private'],
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        // The choices are re-keyed in the variations' own `attribute_*` meta. WC_Product_Variation
        // cannot do it: once the parent is saved it builds a variation's attributes from the
        // parent's new keys, finds no meta under them and reports every choice as "any".
        foreach ($ids as $id) {
            $id = (int) $id;
            $choices = [];
            foreach (array_keys($renames) as $key) {
                if (metadata_exists('post', $id, 'attribute_' . $key)) {
                    $choices[$key] = (string) get_post_meta($id, 'attribute_' . $key, true);
                }
            }
            if ($choices === []) {
                continue;
            }
            // All old keys go before any new one is written, so swapped names survive.
            foreach (array_keys($choices) as $key) {
                delete_post_meta($id, 'attribute_' . $key);
            }
            foreach (self::rename_choices($choices, $renames) as $key => $value) {
                update_post_meta($id, 'attribute_' . $key, wp_slash($value));
            }
        }
        wc_delete_product_transients($product->get_id());
    }

    /**
     * The short version for list rows, so a price audit is one call.
     *
     * @return array<string, mixed>|null
     */
    public function summary(int $post_id): ?array
    {
        $product = wc_get_product($post_id);
        if (!$product instanceof \WC_Product) {
            return null;
        }

        return [
            'type'           => $product->get_type(),
            'price'          => (string) $product->get_price('edit'),
            'regular_price'  => (string) $product->get_regular_price('edit'),
            'sale_price'     => (string) $product->get_sale_price('edit'),
            'on_sale'        => $product->is_on_sale('edit'),
            'sku'            => (string) $product->get_sku('edit'),
            'stock_status'   => (string) $product->get_stock_status('edit'),
            'stock_quantity' => self::typed('stock_quantity', self::value_of($product, 'stock_quantity')),
        ];
    }

    /** One line for the review card: what the product is and costs right now. */
    public function describe(int $post_id): string
    {
        $product = wc_get_product($post_id);
        if (!$product instanceof \WC_Product) {
            return '';
        }

        $parts = [$product->get_type()];
        $price = (string) $product->get_price('edit');
        if ($price !== '') {
            $parts[] = self::money($price) . ($product->is_on_sale('edit') ? ' (on sale)' : '');
        }
        $parts[] = (string) $product->get_stock_status('edit')
            . ($product->get_manage_stock('edit') ? sprintf(' (%s)', self::value_of($product, 'stock_quantity')) : '');
        $sku = (string) $product->get_sku('edit');
        if ($sku !== '') {
            $parts[] = 'SKU ' . $sku;
        }

        return implode(' · ', $parts);
    }

    /** A price as the shop prints it, as plain text. */
    public static function money(string $amount): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price((float) $amount)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * The current value of a field as a string, so the staleness hash and the
     * review diff read it the same way.
     */
    public function read_field(int $post_id, string $field): string
    {
        $product = wc_get_product($post_id);

        return $product instanceof \WC_Product ? self::value_of($product, $field) : '';
    }

    private static function value_of(\WC_Product $product, string $field): string
    {
        return match ($field) {
            'regular_price'      => (string) $product->get_regular_price('edit'),
            'sale_price'         => (string) $product->get_sale_price('edit'),
            'date_on_sale_from'  => self::date_of($product->get_date_on_sale_from('edit')),
            'date_on_sale_to'    => self::date_of($product->get_date_on_sale_to('edit')),
            'sku'                => (string) $product->get_sku('edit'),
            'stock_status'       => (string) $product->get_stock_status('edit'),
            'manage_stock'       => $product->get_manage_stock('edit') ? 'true' : 'false',
            'stock_quantity'     => $product->get_stock_quantity('edit') === null
                ? ''
                : (string) wc_stock_amount($product->get_stock_quantity('edit')),
            'backorders'         => (string) $product->get_backorders('edit'),
            'catalog_visibility' => (string) $product->get_catalog_visibility('edit'),
            'featured'           => $product->get_featured('edit') ? 'true' : 'false',
            default              => '',
        };
    }

    /** A sale date as the day it falls on in the site's timezone. */
    private static function date_of(mixed $date): string
    {
        return $date instanceof \WC_DateTime ? $date->date('Y-m-d') : '';
    }

    private static function typed(string $field, string $value): mixed
    {
        return match ($field) {
            'manage_stock', 'featured' => $value === 'true',
            'stock_quantity'           => $value === '' ? null : (int) $value,
            default                    => $value,
        };
    }

    // -------------------------------------------------------------------------
    // Checking
    // -------------------------------------------------------------------------

    /**
     * First pass at propose time: the canonical string when the value parses —
     * "49,90" becomes "49.90" — so the queue shows exactly what will be
     * written. Left as sent otherwise, for check() to refuse with the reason
     * rather than have it vanish here.
     */
    public static function sanitize(string $field, mixed $value): string
    {
        $normalised = self::normalise_one($field, $value);
        if (!is_wp_error($normalised)) {
            return $normalised;
        }

        return sanitize_text_field(is_scalar($value) ? (string) $value : (string) wp_json_encode($value));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>|\WP_Error
     */
    public static function normalise(array $fields): array|\WP_Error
    {
        $out = [];
        foreach (array_intersect_key($fields, array_flip(self::FIELDS)) as $field => $value) {
            $normalised = self::normalise_one($field, $value);
            if (is_wp_error($normalised)) {
                return $normalised;
            }
            $out[$field] = $normalised;
        }

        return $out;
    }

    private static function normalise_one(string $field, mixed $value): string|\WP_Error
    {
        if (in_array($field, self::PRICE_FIELDS, true)) {
            if ($value === '' || $value === null) {
                return '';
            }
            $decimal = self::decimal($value);

            return $decimal ?? new \WP_Error(
                'bad_price',
                sprintf('%s must be a plain number such as "49.90" — no currency sign, no thousands separator.', $field),
                ['status' => 400],
            );
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            $date = trim(is_scalar($value) ? (string) $value : '');
            if ($date === '') {
                return '';
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $date;
            }

            return new \WP_Error(
                'bad_date',
                sprintf('%s must be a date as YYYY-MM-DD, in the site\'s timezone.', $field),
                ['status' => 400],
            );
        }

        switch ($field) {
            case 'sku':
                $sku = trim(sanitize_text_field(is_scalar($value) ? (string) $value : ''));
                // The lookup table's column is 100 characters wide.
                return mb_strlen($sku) <= 100
                    ? $sku
                    : new \WP_Error('bad_sku', 'sku is limited to 100 characters.', ['status' => 400]);

            case 'stock_quantity':
                if (is_bool($value) || !is_scalar($value) && $value !== null) {
                    return new \WP_Error('bad_stock_quantity', 'stock_quantity must be a whole number.', ['status' => 400]);
                }
                $quantity = trim((string) $value);
                if ($quantity === '') {
                    return '';
                }

                return preg_match('/^\d+$/', $quantity)
                    ? (string) (int) $quantity
                    : new \WP_Error('bad_stock_quantity', 'stock_quantity must be a whole number, zero or more.', ['status' => 400]);

            case 'manage_stock':
            case 'featured':
                return LayoutMeta::truthy($value) ? 'true' : 'false';

            case 'stock_status':
                return self::one_of($field, $value, array_keys(wc_get_product_stock_status_options()));

            case 'backorders':
                return self::one_of($field, $value, array_keys(wc_get_product_backorder_options()));

            case 'catalog_visibility':
                return self::one_of($field, $value, array_keys(wc_get_product_visibility_options()));
        }

        return new \WP_Error('bad_product_field', sprintf('%s is not a product field.', $field), ['status' => 400]);
    }

    /** @param array<int, string> $options */
    private static function one_of(string $field, mixed $value, array $options): string|\WP_Error
    {
        $value = sanitize_key(is_scalar($value) ? (string) $value : '');

        return in_array($value, $options, true)
            ? $value
            : new \WP_Error(
                'bad_' . $field,
                sprintf('%s must be one of: %s.', $field, implode(', ', $options)),
                ['status' => 400],
            );
    }

    /**
     * A price as a dot-decimal string, or null. Strict on purpose: a thousands
     * separator read as a decimal point turns 1.290 into 1,29, and nothing
     * downstream would notice.
     */
    private static function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d+(?:[.,]\d{1,6})?$/', $value) ? str_replace(',', '.', $value) : null;
    }

    /**
     * Whether these fields can be written to this product, and why not when
     * they can't. Runs at propose time and again at approval, because a queued
     * change sits while the shop moves: a sale ends, an SKU gets taken, an
     * order changes the stock.
     *
     * For a create, pass a fresh WC_Product_Simple — its defaults are the
     * "current" values a new product starts from.
     *
     * @param array<string, mixed> $fields
     */
    public function check(array $fields, \WC_Product $product): true|\WP_Error
    {
        if (array_key_exists(self::ATTRIBUTE_FIELD, $fields)) {
            $words = self::check_attribute_words($product, (array) $fields[self::ATTRIBUTE_FIELD]);
            if (is_wp_error($words)) {
                return $words;
            }
        }

        $fields = self::normalise($fields);
        if (is_wp_error($fields)) {
            return $fields;
        }
        if ($fields === []) {
            return true;
        }

        $type = $product->get_type();
        $refused = array_diff(array_keys($fields), self::fields_for_type($type));
        if ($refused !== []) {
            return new \WP_Error(
                'product_field_unsupported',
                sprintf('%s cannot be set on a %s product.%s', implode(', ', $refused), $type, match ($type) {
                    'variable' => ' Its prices and stock live on its variations, which cannot be proposed yet.',
                    'grouped'  => ' A grouped product has no price or stock of its own; propose against the products in it.',
                    'external' => ' An external product is sold elsewhere, so WooCommerce keeps no stock for it.',
                    default    => '',
                }),
                ['status' => 400],
            );
        }

        // The value each field will have once this applies: proposed where
        // given, as it stands otherwise. Rules between fields are judged on
        // that, so a new regular price is checked against the existing sale.
        $after = static fn (string $field): string => array_key_exists($field, $fields)
            ? $fields[$field]
            : self::value_of($product, $field);

        if (($fields['regular_price'] ?? null) === '') {
            return new \WP_Error(
                'price_required',
                'regular_price cannot be cleared: a product without one cannot be bought. To stop selling it, '
                    . 'propose post_status "draft" or stock_status "outofstock".',
                ['status' => 400],
            );
        }

        if (array_intersect_key($fields, array_flip(self::PRICE_FIELDS)) !== [] && $after('sale_price') !== '') {
            if ($after('regular_price') === '') {
                return new \WP_Error('sale_without_price', 'A sale_price needs a regular_price to reduce.', ['status' => 400]);
            }
            if ((float) $after('sale_price') >= (float) $after('regular_price')) {
                return new \WP_Error(
                    'sale_not_lower',
                    sprintf(
                        'sale_price %s is not below regular_price %s, and WooCommerce would quietly treat the product as not on sale.%s',
                        $after('sale_price'),
                        $after('regular_price'),
                        array_key_exists('sale_price', $fields) ? '' : ' Propose sale_price too, or "" to end the sale.',
                    ),
                    ['status' => 400],
                );
            }
        }

        if (array_intersect_key($fields, array_flip(self::DATE_FIELDS)) !== []) {
            $from = $after('date_on_sale_from');
            $to   = $after('date_on_sale_to');
            if (($from !== '' || $to !== '') && $after('sale_price') === '') {
                return new \WP_Error('schedule_without_sale', 'A sale schedule needs a sale_price.', ['status' => 400]);
            }
            if ($from !== '' && $to !== '' && $from > $to) {
                return new \WP_Error('bad_schedule', 'date_on_sale_from is after date_on_sale_to.', ['status' => 400]);
            }
            if (($fields['date_on_sale_to'] ?? '') !== '' && $to < wp_date('Y-m-d')) {
                return new \WP_Error('schedule_over', 'date_on_sale_to is in the past, so that sale is already over.', ['status' => 400]);
            }
        }

        if (array_intersect_key($fields, array_flip(self::STOCK_FIELDS)) !== []) {
            $managed = $after('manage_stock') === 'true';

            if (($fields['manage_stock'] ?? '') === 'true' && !self::store_manages_stock()) {
                return new \WP_Error(
                    'store_stock_off',
                    'Stock management is switched off for the whole shop (WooCommerce → Settings → Products → '
                        . 'Inventory), so a product cannot manage its own.',
                    ['status' => 400],
                );
            }
            if (!$managed && (array_key_exists('stock_quantity', $fields) || array_key_exists('backorders', $fields))) {
                return new \WP_Error(
                    'stock_not_managed',
                    'stock_quantity and backorders apply only while manage_stock is true — send manage_stock: true with them.',
                    ['status' => 400],
                );
            }
            if ($managed && $after('stock_quantity') === '') {
                return new \WP_Error(
                    'stock_quantity_required',
                    'With manage_stock true a product needs a stock_quantity; without one WooCommerce counts it as none.',
                    ['status' => 400],
                );
            }
            if ($managed && array_key_exists('stock_status', $fields)) {
                return new \WP_Error(
                    'stock_status_derived',
                    'With manage_stock on, WooCommerce derives stock_status from stock_quantity and would overwrite '
                        . 'this. Propose stock_quantity instead.',
                    ['status' => 400],
                );
            }
        }

        if (($fields['sku'] ?? '') !== '' && !wc_product_has_unique_sku($product->get_id(), $fields['sku'])) {
            $owner = (int) wc_get_product_id_by_sku($fields['sku']);

            return new \WP_Error(
                'duplicate_sku',
                sprintf(
                    'SKU %s is already used%s. SKUs are unique across the shop.',
                    $fields['sku'],
                    $owner > 0 ? sprintf(' by product %d', $owner) : '',
                ),
                ['status' => 400],
            );
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Set the fields and save — always save, even with nothing to set.
     *
     * The empty save is the point after PostApplier has written a product's
     * title or description as a post: WooCommerce's own editor ends every edit
     * in WC_Product::save(), and feed and search integrations listen for the
     * woocommerce_update_product it fires. It also fills in whatever Woo meta
     * a product drafted as a bare post lacks, so a created draft becomes a
     * complete simple product, lookup row included.
     *
     * @param array<string, mixed> $fields
     */
    public function apply(int $post_id, array $fields): true|\WP_Error
    {
        $product = wc_get_product($post_id);
        if (!$product instanceof \WC_Product) {
            return new \WP_Error('not_a_product', sprintf('%d is not a WooCommerce product.', $post_id));
        }

        $check = $this->check($fields, $product);
        if (is_wp_error($check)) {
            return $check;
        }
        $words = (array) ($fields[self::ATTRIBUTE_FIELD] ?? []);
        $fields = self::normalise($fields);
        if (is_wp_error($fields)) {
            return $fields;
        }

        try {
            foreach ($fields as $field => $value) {
                self::set($product, $field, $value);
            }
            $renames = $words !== [] ? self::set_attribute_words($product, $words) : [];
            // Aligns stock status with quantity, then writes meta, terms and
            // the lookup row, and clears Woo's caches.
            $product->save();
            self::rename_variation_choices($product, $renames);
        } catch (\WC_Data_Exception $e) {
            return new \WP_Error($e->getErrorCode(), $e->getMessage(), ['status' => 400]);
        } catch (\Throwable $e) {
            return new \WP_Error('product_save_failed', $e->getMessage());
        }

        return true;
    }

    private static function set(\WC_Product $product, string $field, string $value): void
    {
        switch ($field) {
            case 'regular_price':
                $product->set_regular_price($value);
                break;
            case 'sale_price':
                $product->set_sale_price($value);
                break;
            // The product editor's own convention: a sale starts at the first
            // moment of its first day and ends at the last moment of its last,
            // in the site's timezone — which is how Woo reads a date string
            // that carries no offset.
            case 'date_on_sale_from':
                $product->set_date_on_sale_from($value !== '' ? $value . ' 00:00:00' : null);
                break;
            case 'date_on_sale_to':
                $product->set_date_on_sale_to($value !== '' ? $value . ' 23:59:59' : null);
                break;
            case 'sku':
                $product->set_sku($value);
                break;
            case 'stock_status':
                $product->set_stock_status($value);
                break;
            case 'manage_stock':
                $product->set_manage_stock($value === 'true');
                break;
            case 'stock_quantity':
                $product->set_stock_quantity($value);
                break;
            case 'backorders':
                $product->set_backorders($value);
                break;
            case 'catalog_visibility':
                $product->set_catalog_visibility($value);
                break;
            case 'featured':
                $product->set_featured($value === 'true');
                break;
        }
    }
}
