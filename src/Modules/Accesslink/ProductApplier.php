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

        // Said outright rather than left to a refused proposal: which of the
        // fields above this product takes here, given its type and whether
        // the operator has switched price and stock edits on.
        $out['proposable'] = array_values(array_filter(
            self::fields_for_type($product->get_type()),
            static fn (string $f): bool => $commerce || !in_array($f, self::COMMERCE_FIELDS, true),
        ));

        return $out;
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
        $fields = self::normalise($fields);
        if (is_wp_error($fields)) {
            return $fields;
        }

        try {
            foreach ($fields as $field => $value) {
                self::set($product, $field, $value);
            }
            // Aligns stock status with quantity, then writes meta, terms and
            // the lookup row, and clears Woo's caches.
            $product->save();
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
