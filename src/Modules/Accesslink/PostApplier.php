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
 * A WooCommerce applier still slots in beside this later — product price and
 * stock live in postmeta *and* Woo's lookup table, so they need wc_get_product()
 * setters rather than any of the paths below.
 */
final class PostApplier
{
    /** Plain post columns. */
    public const POST_FIELDS = ['post_title', 'post_content', 'post_excerpt'];

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

    /** Every field an agent may set on this site right now. */
    public function allowed_fields(): array
    {
        $fields = self::POST_FIELDS;
        if ($this->seo->can_write()) {
            $fields = array_merge($fields, SeoAdapterFactory::FIELDS);
        }
        $fields = array_merge($fields, array_keys(self::TERM_FIELDS));
        $fields[] = self::MEDIA_FIELD;

        return $fields;
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

        if (isset(self::TERM_FIELDS[$field])) {
            $terms = wp_get_object_terms($post_id, self::TERM_FIELDS[$field], ['fields' => 'slugs']);
            if (is_wp_error($terms)) {
                return '';
            }
            sort($terms);

            return implode(', ', $terms);
        }

        if ($field === self::MEDIA_FIELD) {
            return (string) (int) get_post_thumbnail_id($post_id);
        }

        return '';
    }

    /**
     * Digest of the fields this change intends to touch, plus the modification
     * timestamp. Compared again at approval time; a mismatch means somebody
     * edited the post in between and the proposal is answering a stale question.
     */
    public function hash(int $post_id, array $field_names): string
    {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return '';
        }

        $parts = ['modified' => $post->post_modified_gmt];
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
    public function create_draft(array $fields, string $post_type): int|\WP_Error
    {
        $data = [
            'post_type'    => $post_type,
            'post_status'  => 'draft',
            'post_title'   => (string) ($fields['post_title'] ?? ''),
            'post_content' => $this->filter_content((string) ($fields['post_content'] ?? '')),
            'post_excerpt' => (string) ($fields['post_excerpt'] ?? ''),
        ];

        // wp_insert_post expects slashed data — it unslashes internally.
        $id = wp_insert_post(wp_slash($data), true);
        if (is_wp_error($id)) {
            return $id;
        }

        // Terms, SEO and the featured image land on the draft too, so the
        // preview shows the whole proposal rather than a headline with no
        // image and no category.
        $rest = $this->apply_non_post_fields((int) $id, $fields);

        return is_wp_error($rest) ? $rest : (int) $id;
    }

    public function apply_update(int $post_id, array $fields): bool|\WP_Error
    {
        $data = ['ID' => $post_id];
        foreach (self::POST_FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = (string) $fields[$field];
            $data[$field] = $field === 'post_content' ? $this->filter_content($value) : $value;
        }

        if (count($data) > 1) {
            $result = wp_update_post(wp_slash($data), true);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        $rest = $this->apply_non_post_fields($post_id, $fields);
        if (is_wp_error($rest)) {
            return $rest;
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
     */
    public function validate(string $post_type, array $fields): true|\WP_Error
    {
        $seo_fields = array_intersect_key($fields, array_flip(SeoAdapterFactory::FIELDS));
        if ($seo_fields !== [] && !$this->seo->can_write()) {
            return new \WP_Error(
                'seo_unavailable',
                sprintf('SEO fields cannot be written on this site (%s).', $this->seo->label()),
                ['status' => 400],
            );
        }

        foreach (self::TERM_FIELDS as $field => $taxonomy) {
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

        if (array_key_exists(self::MEDIA_FIELD, $fields) && (int) $fields[self::MEDIA_FIELD] !== 0) {
            $check = $this->validate_attachment((int) $fields[self::MEDIA_FIELD]);
            if (is_wp_error($check)) {
                $check->add_data(['status' => 400], $check->get_error_code());

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

        foreach (self::TERM_FIELDS as $field => $taxonomy) {
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

    public function set_status(int $post_id, string $status): bool|\WP_Error
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return new \WP_Error('bad_status', 'Unsupported post status.');
        }

        $result = wp_update_post(wp_slash(['ID' => $post_id, 'post_status' => $status]), true);

        return is_wp_error($result) ? $result : true;
    }

    /**
     * Mirror WordPress's own rule rather than inventing one: content is passed
     * through kses unless the acting user could have written it by hand. On a
     * single site an administrator has unfiltered_html, so approving an agent's
     * post is equivalent to pasting it into the editor yourself.
     *
     * KNOWN RISK: kses strips HTML comments, and block markup *is* HTML
     * comments, so a non-administrator approving a block-based post flattens it
     * to classic HTML. On a site where nearly everything is blocks that is data
     * loss, not a safe fallback. Tracked as the next fix.
     */
    private function filter_content(string $content): string
    {
        return current_user_can('unfiltered_html') ? $content : wp_kses_post($content);
    }
}
