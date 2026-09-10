<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Modules\Accesslink\Seo\SeoAdapter;
use Valolink\Plugin\Modules\Accesslink\Seo\SeoAdapterFactory;

/**
 * Knows how to read, hash and mutate the agent-editable surface of a post.
 *
 * Four families of field, each stored somewhere different: post columns, SEO
 * postmeta (via whichever plugin adapter the site resolves to), taxonomy terms,
 * and the featured-image attachment id. They are routed here rather than in
 * ChangeService so the queue, the staleness gate and the review diff all get
 * them for free.
 *
 * WooCommerce products add one more family, the fields Woo owns, which
 * ProductApplier writes beside this: price and stock live in postmeta *and*
 * Woo's lookup table, so they need wc_get_product() setters rather than any of
 * the paths below. Every write to a product ends in its save().
 */
final class PostApplier
{
    /** Plain post columns. */
    public const POST_FIELDS = ['post_title', 'post_content', 'post_excerpt'];

    /** Settable on an update as well as a create. */
    public const STATUS_FIELD = 'post_status';

    /** Taxonomy assignment. Values are arrays of existing term slugs. */
    public const TERM_FIELDS = ['categories' => 'category', 'tags' => 'post_tag'];

    /** Featured image, as an attachment id. */
    public const MEDIA_FIELD = 'featured_media';

    /** Statuses a change is allowed to move a post into. */
    public const ALLOWED_STATUSES = ['draft', 'pending', 'publish', 'private'];

    private SeoAdapter $seo;

    public function __construct(?SeoAdapter $seo = null)
    {
        $this->seo = $seo ?? SeoAdapterFactory::detect();
    }

    public function seo(): SeoAdapter
    {
        return $this->seo;
    }

    /**
     * Every field an agent may set on this site right now.
     *
     * Element and product fields are listed only when the site both has the
     * plugin and allows agents at that post type — otherwise they would
     * advertise a capability every proposal naming them is refused for.
     * Prices, stock and SKUs need the operator's commerce switch as well.
     *
     * @param array<int, string>|null $post_types the site's allowed post types, if known
     */
    public function allowed_fields(?array $post_types = null, bool $commerce = false): array
    {
        $fields = self::POST_FIELDS;
        if ($this->seo->can_write()) {
            $fields = array_merge($fields, SeoAdapterFactory::FIELDS);
        }
        $fields = array_merge($fields, array_keys(self::TERM_FIELDS));
        $fields[] = self::MEDIA_FIELD;
        $fields[] = self::STATUS_FIELD;
        if (LayoutMeta::available()) {
            $fields = array_merge($fields, LayoutMeta::FIELDS);
        }
        if (ElementReader::available()
            && ($post_types === null || in_array(ElementReader::POST_TYPE, $post_types, true))) {
            $fields = array_merge($fields, ElementReader::WRITABLE);
        }
        if (ProductApplier::available()
            && ($post_types === null || in_array(ProductApplier::POST_TYPE, $post_types, true))) {
            $fields = array_merge($fields, array_keys(ProductApplier::TERM_FIELDS), ProductApplier::MERCHANDISING_FIELDS);
            if ($commerce) {
                $fields = array_merge($fields, ProductApplier::COMMERCE_FIELDS);
            }
        }

        return $fields;
    }

    /**
     * Taxonomy fields across post types: the blog's categories and tags, and
     * WooCommerce's product ones where it is active.
     *
     * @return array<string, string> field => taxonomy
     */
    public static function term_fields(): array
    {
        return ProductApplier::available() ? self::TERM_FIELDS + ProductApplier::TERM_FIELDS : self::TERM_FIELDS;
    }

    /**
     * Normalised current value of any supported field, as a string. Used for
     * both the staleness hash and the review diff, so those two can never
     * disagree about what "current" means.
     */
    public function current_value(int $post_id, string $field): string
    {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return '';
        }

        if (in_array($field, self::POST_FIELDS, true)) {
            return (string) $post->{$field};
        }

        if (in_array($field, SeoAdapterFactory::FIELDS, true)) {
            return (string) ($this->seo->read($post_id)[$field] ?? '');
        }

        $term_fields = self::term_fields();
        if (isset($term_fields[$field])) {
            $terms = wp_get_object_terms($post_id, $term_fields[$field], ['fields' => 'slugs']);
            if (is_wp_error($terms)) {
                return '';
            }
            sort($terms);

            return implode(', ', $terms);
        }

        if (in_array($field, ProductApplier::FIELDS, true)) {
            return $post->post_type === ProductApplier::POST_TYPE && ProductApplier::available()
                ? (new ProductApplier())->read_field($post_id, $field)
                : '';
        }

        if ($field === self::MEDIA_FIELD) {
            return (string) (int) get_post_thumbnail_id($post_id);
        }

        if ($field === self::STATUS_FIELD) {
            return (string) $post->post_status;
        }

        if (in_array($field, LayoutMeta::FIELDS, true)) {
            return LayoutMeta::applies_to($post->post_type)
                ? (new LayoutMeta())->read_field($post_id, $field)
                : '';
        }

        if (in_array($field, ElementReader::WRITABLE, true)) {
            return $post->post_type === ElementReader::POST_TYPE
                ? (new ElementReader())->read_field($post_id, $field)
                : '';
        }

        return '';
    }

    /**
     * Digest of the fields this change intends to touch. Compared again at
     * approval time; a mismatch means somebody changed one of those values in
     * between and the proposal is answering a stale question.
     *
     * Deliberately *not* the modification timestamp. It used to be, and then
     * two proposals touching disjoint fields on one post could never both
     * apply: the first bumped the stamp and parked the second as stale, though
     * nothing it was replacing had changed. The values are the honest question.
     */
    public function hash(int $post_id, array $field_names): string
    {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return '';
        }

        $parts = [];
        foreach ($field_names as $field) {
            $parts[$field] = $this->current_value($post_id, $field);
        }

        return hash('sha256', (string) wp_json_encode($parts));
    }

    /**
     * Materialise a proposed new post as a draft straight away, so the operator
     * reviews it in the real editor with the real theme rather than squinting at
     * raw HTML in a queue. A draft is not publicly reachable, and approving is
     * then just a status flip — nothing is re-created from the payload later.
     */
    public function create_draft(array $fields, string $post_type, string $slug = ''): int|\WP_Error
    {
        $data = [
            'post_type'    => $post_type,
            'post_status'  => 'draft',
            'post_title'   => (string) ($fields['post_title'] ?? ''),
            'post_content' => (string) ($fields['post_content'] ?? ''),
            'post_excerpt' => (string) ($fields['post_excerpt'] ?? ''),
        ];
        // A new draft has nothing to redirect from, so a slug here needs none
        // of the care a rename does. WordPress makes it unique on publish.
        if ($slug !== '') {
            $data['post_name'] = sanitize_title($slug);
        }

        // wp_insert_post expects slashed data — it unslashes internally.
        $id = self::without_kses(static fn () => wp_insert_post(wp_slash($data), true));
        if (is_wp_error($id)) {
            return $id;
        }

        // Terms, SEO and the featured image land on the draft too, so the
        // preview shows the whole proposal rather than a headline with no
        // image and no category.
        $rest = $this->apply_non_post_fields((int) $id, $fields);
        if (is_wp_error($rest)) {
            return $rest;
        }

        // A product drafted as a bare post has none of WooCommerce's meta and
        // no lookup row. One save through its CRUD fills that in and sets the
        // proposed price and stock — what the product editor does with the
        // auto-draft it starts from. Removed outright if that fails: nobody
        // has reviewed it, so there is nothing to recover.
        if ($post_type === ProductApplier::POST_TYPE && ProductApplier::available()) {
            $product = (new ProductApplier())->apply((int) $id, $fields);
            if (is_wp_error($product)) {
                wp_delete_post((int) $id, true);

                return $product;
            }
        }

        return (int) $id;
    }

    public function apply_update(int $post_id, array $fields): bool|\WP_Error
    {
        // Checked before anything is written: a product change that fails
        // half-way — title applied, SKU refused — is worse than one that
        // fails whole.
        $product = self::product_of($post_id);
        if ($product !== null) {
            $check = (new ProductApplier())->check($fields, $product);
            if (is_wp_error($check)) {
                return $check;
            }
        }

        $data = ['ID' => $post_id];
        foreach (self::POST_FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = (string) $fields[$field];
            $data[$field] = $value;
        }

        if (count($data) > 1) {
            $slashed = wp_slash($data);
            $result = self::without_kses(static fn () => wp_update_post($slashed, true));
            if (is_wp_error($result)) {
                return $result;
            }
        }

        $rest = $this->apply_non_post_fields($post_id, $fields);
        if (is_wp_error($rest)) {
            return $rest;
        }

        // Last, so the save WooCommerce's integrations hear about sees the
        // title, terms and SEO just written as well as its own fields.
        if ($product !== null) {
            return (new ProductApplier())->apply($post_id, $fields);
        }

        if (count($data) === 1 && $rest === false) {
            return new \WP_Error('nothing_to_apply', 'No supported fields in payload.');
        }

        return true;
    }

    /**
     * Check a payload without writing anything.
     *
     * Called at propose time so an agent finds out immediately that a term
     * doesn't exist — and gets told which ones do — instead of receiving a
     * cheerful 201, and so the reviewer's queue doesn't fill with proposals
     * that were never applicable. Apply time re-checks anyway, because the
     * site can move underneath a queued change.
     *
     * @param int $post_id the post an update targets; 0 for a create
     */
    public function validate(string $post_type, array $fields, int $post_id = 0): true|\WP_Error
    {
        $seo_fields = array_intersect_key($fields, array_flip(SeoAdapterFactory::FIELDS));
        if ($seo_fields !== [] && !$this->seo->can_write()) {
            return new \WP_Error(
                'seo_unavailable',
                sprintf('SEO fields cannot be written on this site (%s).', $this->seo->label()),
                ['status' => 400],
            );
        }

        foreach (self::term_fields() as $field => $taxonomy) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            if (!is_object_in_taxonomy($post_type, $taxonomy)) {
                return new \WP_Error(
                    'taxonomy_unsupported',
                    sprintf('%s does not support %s.', $post_type, $taxonomy),
                    ['status' => 400],
                );
            }
            $ids = $this->resolve_terms((array) $fields[$field], $taxonomy);
            if (is_wp_error($ids)) {
                $ids->add_data(['status' => 400], $ids->get_error_code());

                return $ids;
            }
        }

        if (array_key_exists(self::STATUS_FIELD, $fields)
            && !in_array((string) $fields[self::STATUS_FIELD], self::ALLOWED_STATUSES, true)
        ) {
            return new \WP_Error(
                'bad_status',
                sprintf('post_status must be one of: %s.', implode(', ', self::ALLOWED_STATUSES)),
                ['status' => 400],
            );
        }

        if (array_key_exists(self::MEDIA_FIELD, $fields) && (int) $fields[self::MEDIA_FIELD] !== 0) {
            $check = $this->validate_attachment((int) $fields[self::MEDIA_FIELD]);
            if (is_wp_error($check)) {
                $check->add_data(['status' => 400], $check->get_error_code());

                return $check;
            }
        }

        $layout = array_intersect_key($fields, array_flip(LayoutMeta::FIELDS));
        if ($layout !== []) {
            if (!LayoutMeta::applies_to($post_type)) {
                return new \WP_Error(
                    'layout_unavailable',
                    sprintf('Layout fields (%s) do not apply to %s on this site.', implode(', ', array_keys($layout)), $post_type),
                    ['status' => 400],
                );
            }
            $check = (new LayoutMeta())->normalise($layout);
            if (is_wp_error($check)) {
                return $check;
            }
        }

        $element = array_intersect_key($fields, array_flip(ElementReader::WRITABLE));
        if ($element !== []) {
            if ($post_type !== ElementReader::POST_TYPE || !ElementReader::available()) {
                return new \WP_Error(
                    'element_fields_unsupported',
                    sprintf('Element fields (%s) apply only to %s.', implode(', ', array_keys($element)), ElementReader::POST_TYPE),
                    ['status' => 400],
                );
            }
            $check = (new ElementReader())->normalise_fields($element);
            if (is_wp_error($check)) {
                return $check;
            }
        }

        $product_fields = array_intersect_key($fields, array_flip(ProductApplier::FIELDS));
        if ($product_fields !== []) {
            if ($post_type !== ProductApplier::POST_TYPE || !ProductApplier::available()) {
                return new \WP_Error(
                    'product_fields_unsupported',
                    sprintf('Product fields (%s) apply only to %s.', implode(', ', array_keys($product_fields)), ProductApplier::POST_TYPE),
                    ['status' => 400],
                );
            }
            // A create is checked against a fresh simple product, whose
            // defaults are what the new one starts from.
            $product = $post_id > 0 ? wc_get_product($post_id) : new \WC_Product_Simple();
            if (!$product instanceof \WC_Product) {
                return new \WP_Error('not_a_product', sprintf('%d is not a WooCommerce product.', $post_id), ['status' => 404]);
            }
            $check = (new ProductApplier())->check($product_fields, $product);
            if (is_wp_error($check)) {
                return $check;
            }
        }

        return true;
    }

    /** @return bool|\WP_Error true if something was written, false if nothing applied */
    private function apply_non_post_fields(int $post_id, array $fields): bool|\WP_Error
    {
        $wrote = false;

        $seo_fields = array_intersect_key($fields, array_flip(SeoAdapterFactory::FIELDS));
        if ($seo_fields !== []) {
            if (!$this->seo->can_write()) {
                return new \WP_Error(
                    'seo_unavailable',
                    sprintf('SEO fields cannot be written on this site (%s).', $this->seo->label()),
                );
            }
            $this->seo->write($post_id, $seo_fields);
            $wrote = true;
        }

        foreach (self::term_fields() as $field => $taxonomy) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            // Pages have no categories. Silently writing terms nothing renders
            // would look like success and produce nothing.
            $post_type = (string) get_post_type($post_id);
            if (!is_object_in_taxonomy($post_type, $taxonomy)) {
                return new \WP_Error(
                    'taxonomy_unsupported',
                    sprintf('%s does not support %s.', $post_type, $taxonomy),
                );
            }
            $ids = $this->resolve_terms((array) $fields[$field], $taxonomy);
            if (is_wp_error($ids)) {
                return $ids;
            }
            $set = wp_set_object_terms($post_id, $ids, $taxonomy, false);
            if (is_wp_error($set)) {
                return $set;
            }
            $wrote = true;
        }

        if (array_key_exists(self::STATUS_FIELD, $fields)) {
            $status = $this->write_status($post_id, (string) $fields[self::STATUS_FIELD]);
            if (is_wp_error($status)) {
                return $status;
            }
            $wrote = true;
        }

        if (array_key_exists(self::MEDIA_FIELD, $fields)) {
            $attachment = (int) $fields[self::MEDIA_FIELD];
            if ($attachment === 0) {
                delete_post_thumbnail($post_id);
            } else {
                $check = $this->validate_attachment($attachment);
                if (is_wp_error($check)) {
                    return $check;
                }
                set_post_thumbnail($post_id, $attachment);
            }
            $wrote = true;
        }

        $layout = array_intersect_key($fields, array_flip(LayoutMeta::FIELDS));
        if ($layout !== []) {
            if (!LayoutMeta::applies_to((string) get_post_type($post_id))) {
                return new \WP_Error('layout_unavailable', 'Layout fields do not apply to this post.');
            }
            $normalised = (new LayoutMeta())->normalise($layout);
            if (is_wp_error($normalised)) {
                return $normalised;
            }
            (new LayoutMeta())->write($post_id, $normalised);
            $wrote = true;
        }

        $element = array_intersect_key($fields, array_flip(ElementReader::WRITABLE));
        if ($element !== []) {
            if ((string) get_post_type($post_id) !== ElementReader::POST_TYPE) {
                return new \WP_Error('element_fields_unsupported', 'Element fields apply only to ' . ElementReader::POST_TYPE . '.');
            }
            $normalised = (new ElementReader())->normalise_fields($element);
            if (is_wp_error($normalised)) {
                return $normalised;
            }
            (new ElementReader())->write_fields($post_id, $normalised);
            $wrote = true;
        }

        return $wrote;
    }

    /**
     * Slugs (or names) to term ids, existing terms only.
     *
     * Creating terms on the fly is deliberately refused: an agent inventing
     * "Verkkosivut", "verkkosivut" and "Verkkosivu" across three proposals
     * quietly shreds a taxonomy, and the error message below tells it exactly
     * what it may choose from instead.
     *
     * @return array<int, int>|\WP_Error
     */
    private function resolve_terms(array $slugs, string $taxonomy): array|\WP_Error
    {
        $ids = [];
        $unknown = [];

        foreach ($slugs as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }
            $term = get_term_by('slug', $slug, $taxonomy) ?: get_term_by('name', $slug, $taxonomy);
            if ($term instanceof \WP_Term) {
                $ids[] = (int) $term->term_id;
            } else {
                $unknown[] = $slug;
            }
        }

        if ($unknown !== []) {
            $available = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'slugs']);
            $available = is_wp_error($available) ? [] : array_slice($available, 0, 40);

            return new \WP_Error(
                'unknown_term',
                sprintf(
                    'Unknown %s: %s. Accesslink does not create terms — choose from: %s',
                    $taxonomy,
                    implode(', ', $unknown),
                    implode(', ', $available),
                ),
            );
        }

        return $ids;
    }

    private function validate_attachment(int $id): true|\WP_Error
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') {
            return new \WP_Error('bad_attachment', sprintf('%d is not an attachment.', $id));
        }
        if (!str_starts_with((string) $post->post_mime_type, 'image/')) {
            return new \WP_Error('not_an_image', sprintf('%d is not an image.', $id));
        }

        return true;
    }

    /**
     * Write a whole post_content that was produced by re-serialising the block
     * tree. Applied as it is: the agent's fragment inside it was sanitised at
     * propose time, and the rest is the site's own markup.
     */
    public function apply_raw_content(int $post_id, string $content): bool|\WP_Error
    {
        $data = wp_slash(['ID' => $post_id, 'post_content' => $content]);
        $result = self::without_kses(static fn () => wp_update_post($data, true));
        if (is_wp_error($result)) {
            return $result;
        }

        return self::product_of($post_id) !== null ? (new ProductApplier())->apply($post_id, []) : true;
    }

    /** Approving a create or a translation; ends in the product save where there is one. */
    public function set_status(int $post_id, string $status): bool|\WP_Error
    {
        $result = $this->write_status($post_id, $status);
        if (is_wp_error($result)) {
            return $result;
        }

        return self::product_of($post_id) !== null ? (new ProductApplier())->apply($post_id, []) : true;
    }

    private function write_status(int $post_id, string $status): bool|\WP_Error
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return new \WP_Error('bad_status', 'Unsupported post status.');
        }

        $data = wp_slash(['ID' => $post_id, 'post_status' => $status]);
        $result = self::without_kses(static fn () => wp_update_post($data, true));

        return is_wp_error($result) ? $result : true;
    }

    /** The WooCommerce product behind a post, when there is one. */
    private static function product_of(int $post_id): ?\WC_Product
    {
        if (!ProductApplier::available() || get_post_type($post_id) !== ProductApplier::POST_TYPE) {
            return null;
        }
        $product = wc_get_product($post_id);

        return $product instanceof \WC_Product ? $product : null;
    }

    /**
     * Run a save with WordPress's own kses filters lifted.
     *
     * Nothing here filters content any more, on purpose. What an agent authored
     * — a post body, a block fragment, inserted markup — is sanitised once at
     * propose time by ChangeService, so the queue shows exactly what will be
     * applied whoever approves it. What the site already had is never filtered:
     * the documents rebuilt for a block edit are the site's own markup around
     * one sanitised fragment, and passing them through any filter is how an
     * Editor's approval used to strip every SVG icon on the page.
     *
     * WordPress adds wp_filter_post_kses to content_save_pre whenever the
     * current user lacks unfiltered_html — and a propose-time request has no
     * user at all — so without this it stripped every <svg> back out again,
     * silently, undoing the exact thing ContentSanitizer exists to keep.
     */
    private static function without_kses(callable $save): mixed
    {
        $was_on = has_filter('content_save_pre', 'wp_filter_post_kses');
        if ($was_on) {
            kses_remove_filters();
        }

        try {
            return $save();
        } finally {
            if ($was_on) {
                kses_init_filters();
            }
        }
    }
}
