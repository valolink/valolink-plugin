<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * The read half of Accesslink.
 *
 * WordPress core's own /wp/v2 already exposes published content, but it can't
 * be reached with an Accesslink key, it can't see drafts, and its payloads are
 * enormous — a single post carries rendered *and* raw fields plus _links, most
 * of which is noise to an agent paying for every token. This returns a small,
 * predictable shape instead, and can see the drafts Accesslink itself created.
 *
 * Scope is the same allowed_post_types list that governs proposing, so widening
 * one widens the other. Password-protected posts are excluded outright.
 */
final class ContentReader
{
    /** Hard caps. An agent's context window is the real constraint here. */
    public const LIST_MAX          = 50;
    public const LIST_DEFAULT      = 20;
    public const EXCERPT_CHARS     = 280;
    public const CONTENT_MAX_CHARS = 60000;

    /** Statuses an agent may see. Trash and auto-drafts are never listed. */
    public const VISIBLE_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    public function __construct(private readonly ChangeService $service) {}

    /**
     * @return array{items: array<int, array>, total: int, returned: int}
     */
    public function list(array $args): array
    {
        $limit = (int) ($args['limit'] ?? self::LIST_DEFAULT);
        $limit = max(1, min(self::LIST_MAX, $limit));

        $post_type = isset($args['post_type']) ? sanitize_key((string) $args['post_type']) : '';
        $types = $post_type !== '' && in_array($post_type, $this->service->allowed_post_types(), true)
            ? [$post_type]
            : $this->service->allowed_post_types();

        $status = isset($args['status']) ? sanitize_key((string) $args['status']) : '';
        $statuses = $status !== '' && in_array($status, self::VISIBLE_STATUSES, true)
            ? [$status]
            : self::VISIBLE_STATUSES;

        $query = new \WP_Query([
            'post_type'           => $types,
            'post_status'         => $statuses,
            'posts_per_page'      => $limit,
            's'                   => isset($args['search']) ? sanitize_text_field((string) $args['search']) : '',
            'orderby'             => 'modified',
            'order'               => 'DESC',
            'has_password'        => false,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => false,
        ]);

        $items = array_map([$this, 'summarize'], $query->posts);

        return [
            'items'    => $items,
            'total'    => (int) $query->found_posts,
            'returned' => count($items),
        ];
    }

    public function get(int $id): array|\WP_Error
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('not_found', 'No post with that id.', ['status' => 404]);
        }
        if (!in_array($post->post_type, $this->service->allowed_post_types(), true)) {
            return new \WP_Error('bad_post_type', 'post_type not permitted on this site.', ['status' => 403]);
        }
        if (!in_array($post->post_status, self::VISIBLE_STATUSES, true)) {
            return new \WP_Error('not_readable', 'That post is not readable through Accesslink.', ['status' => 403]);
        }
        if ($post->post_password !== '') {
            return new \WP_Error('protected', 'Password-protected posts are not exposed.', ['status' => 403]);
        }

        $content = (string) $post->post_content;
        $truncated = mb_strlen($content) > self::CONTENT_MAX_CHARS;

        $out = $this->summarize($post);
        // post_content is the field an agent actually needs in full — it is
        // what it will diff against and resend in an update proposal.
        $out['post_content'] = $truncated ? mb_substr($content, 0, self::CONTENT_MAX_CHARS) : $content;
        $out['post_excerpt'] = (string) $post->post_excerpt;
        $out['truncated']    = $truncated;
        unset($out['excerpt']);

        // Anything already queued against this post — proposing a second edit
        // on top of a pending one is how you get a stale rejection later.
        $out['pending_changes'] = $this->pending_for($id);

        return $out;
    }

    /** @return array<int, int> ids of pending changes targeting this post */
    private function pending_for(int $post_id): array
    {
        $ids = [];
        foreach ((new ChangeRepository())->list(ChangeRepository::STATUS_PENDING, 100) as $change) {
            if ((int) $change['target_id'] === $post_id) {
                $ids[] = (int) $change['id'];
            }
        }

        return $ids;
    }

    private function summarize(\WP_Post $post): array
    {
        $plain = trim(wp_strip_all_tags((string) $post->post_content));

        return [
            'id'            => $post->ID,
            'post_type'     => $post->post_type,
            'status'        => $post->post_status,
            'title'         => (string) $post->post_title,
            'slug'          => (string) $post->post_name,
            'modified_gmt'  => (string) $post->post_modified_gmt,
            'link'          => (string) get_permalink($post),
            'content_chars' => mb_strlen((string) $post->post_content),
            'excerpt'       => mb_substr($plain, 0, self::EXCERPT_CHARS)
                . (mb_strlen($plain) > self::EXCERPT_CHARS ? '…' : ''),
        ];
    }
}
