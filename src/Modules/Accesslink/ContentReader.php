<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Modules\Accesslink\Seo\SeoAdapterFactory;

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
 * one widens the other. Password-protected posts are included: a post password
 * gates the public front end, nothing more, and an agent that already sees
 * drafts and private posts is not kept out by it. Operators put a password on
 * a page precisely while a client reviews it, which is when the agent's edits
 * are wanted — refusing it here stalled exactly that work.
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

    public function __construct(
        private readonly ChangeService $service,
        private readonly PostApplier $applier,
    ) {}

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

        // WP_Query's search clause drops password-protected posts whenever no
        // user is logged in — and a Bearer request has no user. The key already
        // reads those posts one by one, so a search that cannot find them is
        // simply wrong; strip the clause for the duration of this query.
        global $wpdb;
        $strip = static fn (string $search): string => (string) preg_replace(
            '/\s*AND\s*\(\s*' . preg_quote($wpdb->posts, '/') . '\.post_password\s*=\s*\'\'\s*\)\s*/',
            ' ',
            $search,
        );
        add_filter('posts_search', $strip);
        $query = new \WP_Query([
            'post_type'           => $types,
            'post_status'         => $statuses,
            'posts_per_page'      => $limit,
            's'                   => isset($args['search']) ? sanitize_text_field((string) $args['search']) : '',
            'orderby'             => 'modified',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => false,
        ]);
        remove_filter('posts_search', $strip);

        // Opt-in extras so an audit is one call instead of one per post,
        // while the default row stays as lean as an agent's context needs.
        $extra = self::extras((string) ($args['fields'] ?? ''));
        $items = array_map(fn (\WP_Post $p): array => $this->summarize($p, $extra), $query->posts);

        return [
            'items'    => $items,
            'total'    => (int) $query->found_posts,
            'returned' => count($items),
            'fields'   => $extra,
        ];
    }

    public const EXTRA_FIELDS = ['seo', 'terms', 'media', 'layout'];

    /** @return array<int, string> */
    public static function extras(string $raw): array
    {
        $wanted = array_map('trim', explode(',', strtolower($raw)));

        return array_values(array_intersect(self::EXTRA_FIELDS, $wanted));
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
        $content = (string) $post->post_content;
        $truncated = mb_strlen($content) > self::CONTENT_MAX_CHARS;

        $out = $this->summarize($post);
        // post_content is the field an agent actually needs in full — it is
        // what it will diff against and resend in an update proposal.
        $out['post_content'] = $truncated ? mb_substr($content, 0, self::CONTENT_MAX_CHARS) : $content;
        $out['post_excerpt'] = (string) $post->post_excerpt;
        $out['truncated']    = $truncated;
        unset($out['excerpt']);

        // Everything else an agent may propose, read back through the same
        // resolver that hashes and diffs it — so what it reads here is exactly
        // what a staleness check will compare against.
        $seo = $this->applier->seo();
        $out['seo_plugin'] = $seo->id();
        if ($seo->can_write()) {
            foreach (SeoAdapterFactory::FIELDS as $field) {
                $out[$field] = $this->applier->current_value($id, $field);
            }
        }
        foreach (array_keys(PostApplier::TERM_FIELDS) as $field) {
            $value = $this->applier->current_value($id, $field);
            $out[$field] = $value === '' ? [] : array_map('trim', explode(',', $value));
        }
        $thumb = (int) get_post_thumbnail_id($id);
        $out['featured_media'] = $thumb;
        $out['featured_media_url'] = $thumb ? (string) wp_get_attachment_image_url($thumb, 'medium') : null;

        // Per-page theme layout, so a page an agent builds from full-width
        // sections can also ask for the layout those sections were made for.
        if (LayoutMeta::applies_to($post->post_type)) {
            $out['layout'] = (new LayoutMeta())->read($id);
        }

        // An Element's behaviour, in the same names an update may send back.
        if ($post->post_type === ElementReader::POST_TYPE && ElementReader::available()) {
            $described = (new ElementReader())->get($id);
            if (!is_wp_error($described)) {
                $out['element'] = array_intersect_key(
                    $described,
                    array_flip(array_merge(ElementReader::WRITABLE, ['language'])),
                );
            }
        }

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

    /**
     * Terms an agent may assign. Accesslink refuses to create new ones, so
     * without this the only way to discover valid slugs is to guess and read
     * the error.
     */
    public function taxonomies(): array
    {
        $out = [];
        foreach (PostApplier::TERM_FIELDS as $field => $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 200]);
            // ids as well as slugs: proposals take slugs, but a query-loop
            // block references terms by id, and an agent reading one should
            // not have to guess that term 1 is the default category.
            $out[$field] = is_wp_error($terms) ? [] : array_map(
                static fn (\WP_Term $t): array => [
                    'id'    => (int) $t->term_id,
                    'slug'  => $t->slug,
                    'name'  => $t->name,
                    'count' => (int) $t->count,
                ],
                $terms,
            );
        }

        return $out;
    }

    /**
     * Images available for `featured_media`. Uploading is not possible through
     * Accesslink, so this is the whole of what an agent may choose from.
     */
    public function media(array $args): array
    {
        $limit = max(1, min(self::LIST_MAX, (int) ($args['limit'] ?? self::LIST_DEFAULT)));

        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => $limit,
            's'              => isset($args['search']) ? sanitize_text_field((string) $args['search']) : '',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $items = array_map(static function (\WP_Post $a): array {
            $meta = wp_get_attachment_metadata($a->ID);
            // The post the file was uploaded to, when there was one. A library
            // of images titled by camera filename is unchoosable without it.
            $parent = $a->post_parent > 0 ? get_post($a->post_parent) : null;

            return [
                'id'          => $a->ID,
                'title'       => (string) $a->post_title,
                'alt'         => (string) get_post_meta($a->ID, '_wp_attachment_image_alt', true),
                'mime'        => (string) $a->post_mime_type,
                'width'       => isset($meta['width']) ? (int) $meta['width'] : null,
                'height'      => isset($meta['height']) ? (int) $meta['height'] : null,
                'url'         => (string) wp_get_attachment_image_url($a->ID, 'medium'),
                'attached_to' => $parent instanceof \WP_Post
                    ? ['id' => $parent->ID, 'title' => (string) $parent->post_title, 'post_type' => $parent->post_type]
                    : null,
            ];
        }, $query->posts);

        return ['items' => $items, 'total' => (int) $query->found_posts, 'returned' => count($items)];
    }

    /** @param array<int, string> $extra subset of self::EXTRA_FIELDS */
    private function summarize(\WP_Post $post, array $extra = []): array
    {
        $plain = trim(wp_strip_all_tags((string) $post->post_content));

        $row = [
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

        if (in_array('seo', $extra, true) && $this->applier->seo()->can_write()) {
            foreach (SeoAdapterFactory::FIELDS as $field) {
                $row[$field] = $this->applier->current_value($post->ID, $field);
            }
        }
        if (in_array('terms', $extra, true)) {
            foreach (PostApplier::TERM_FIELDS as $field => $taxonomy) {
                if (!is_object_in_taxonomy($post->post_type, $taxonomy)) {
                    continue;
                }
                $value = $this->applier->current_value($post->ID, $field);
                $row[$field] = $value === '' ? [] : array_map('trim', explode(',', $value));
            }
        }
        if (in_array('media', $extra, true)) {
            $thumb = (int) get_post_thumbnail_id($post->ID);
            $row['featured_media'] = $thumb;
            $row['featured_media_url'] = $thumb ? (string) wp_get_attachment_image_url($thumb, 'medium') : null;
        }
        if (in_array('layout', $extra, true) && LayoutMeta::applies_to($post->post_type)) {
            $row['layout'] = (new LayoutMeta())->read($post->ID);
        }

        return $row;
    }
}
