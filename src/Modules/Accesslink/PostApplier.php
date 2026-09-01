<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Knows how to hash and mutate a post. Kept separate from ChangeService so a
 * WooCommerce applier can slot in beside it later — Woo products are a CPT,
 * but price/stock/SKU live in postmeta *and* in Woo's own lookup table, so
 * they have to go through wc_get_product() setters rather than wp_update_post
 * or the two desynchronise. Nothing here assumes it is the only applier.
 */
final class PostApplier
{
    /** Fields an agent is allowed to propose. Anything else is rejected at the boundary. */
    public const ALLOWED_FIELDS = ['post_title', 'post_content', 'post_excerpt'];

    /** Statuses a change is allowed to move a post into. */
    public const ALLOWED_STATUSES = ['draft', 'pending', 'publish', 'private'];

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
            if (in_array($field, self::ALLOWED_FIELDS, true)) {
                $parts[$field] = (string) $post->{$field};
            }
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
            'post_type'   => $post_type,
            'post_status' => 'draft',
            'post_title'  => (string) ($fields['post_title'] ?? ''),
            'post_content' => $this->filter_content((string) ($fields['post_content'] ?? '')),
            'post_excerpt' => (string) ($fields['post_excerpt'] ?? ''),
        ];

        // wp_insert_post expects slashed data — it unslashes internally.
        $id = wp_insert_post(wp_slash($data), true);

        return is_wp_error($id) ? $id : (int) $id;
    }

    public function apply_update(int $post_id, array $fields): bool|\WP_Error
    {
        $data = ['ID' => $post_id];
        foreach (self::ALLOWED_FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = (string) $fields[$field];
            $data[$field] = $field === 'post_content' ? $this->filter_content($value) : $value;
        }

        if (count($data) === 1) {
            return new \WP_Error('nothing_to_apply', 'No supported fields in payload.');
        }

        $result = wp_update_post(wp_slash($data), true);

        return is_wp_error($result) ? $result : true;
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
     * post is equivalent to pasting it into the editor yourself. When there is
     * no acting user — the REST approve path — it always gets filtered.
     *
     * Note that kses strips HTML comments, which is what block markup is made
     * of, so filtered content loses its block structure and falls back to
     * classic HTML. That is the safe direction to fail in.
     */
    private function filter_content(string $content): string
    {
        return current_user_can('unfiltered_html') ? $content : wp_kses_post($content);
    }
}
