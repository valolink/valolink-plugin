<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Library;

use Valolink\Plugin\Modules\Accesslink\BlockCompiler;

/**
 * A library's section patterns (wp_block) from patterns.json, each tree
 * compiled by Accesslink's BlockCompiler seeded with the slug, so the markup
 * is the same on every run and built only from the site's global styles.
 * Synced patterns are shared sections whose slots a page fills in; unsynced
 * ones are starting points copied into a page. Matched by slug (post_name).
 * Ported from valolink-kuumalahde's fold PatternsStep.
 */
final class PatternWriter
{
    private const CATEGORY_TAXONOMY = 'wp_pattern_category';

    public function __construct(private readonly Library $library)
    {
    }

    /** @return list<string> */
    public function plan(): array
    {
        $counts = ['new' => 0, 'change' => 0, 'same' => 0];
        $lines = [];
        foreach ($this->library->patterns() as $p) {
            $compiled = (new BlockCompiler())->compile($p['tree'], $p['slug']);
            if (is_wp_error($compiled)) {
                // Before the design is applied the classes may not exist yet.
                $issues = (array) ($compiled->get_error_data()['issues'] ?? [$compiled->get_error_message()]);
                $lines[] = sprintf('%s: does not compile yet (%d issue(s), first: %s)', $p['slug'], count($issues), mb_substr((string) $issues[0], 0, 120));
                continue;
            }
            $post = self::find($p['slug']);
            $counts[$post === null ? 'new' : ($this->same($post, $p, $compiled['markup']) ? 'same' : 'change')]++;
        }

        return array_merge([sprintf('patterns: %d new, %d to change, %d already so', $counts['new'], $counts['change'], $counts['same'])], $lines);
    }

    public function apply(Undo $undo): string
    {
        $term_id = $this->category();
        $written = 0;
        foreach ($this->library->patterns() as $p) {
            $compiled = (new BlockCompiler())->compile($p['tree'], $p['slug']);
            if (is_wp_error($compiled)) {
                throw new \RuntimeException($p['slug'] . ': ' . $compiled->get_error_message());
            }
            $markup = $compiled['markup'];
            $post = self::find($p['slug']);
            if ($post !== null && $this->same($post, $p, $markup)) {
                continue;
            }
            $undo->keep('pattern:' . $p['slug'], $post === null ? ['absent' => true] : [
                'title'   => $post->post_title,
                'content' => $post->post_content,
                'synced'  => get_post_meta($post->ID, 'wp_pattern_sync_status', true) !== 'unsynced',
            ]);
            $id = self::write($post?->ID, [
                'post_type'    => 'wp_block',
                'post_status'  => 'publish',
                'post_name'    => $p['slug'],
                'post_title'   => $p['title'],
                'post_content' => $markup,
            ]);
            if ($p['synced']) {
                delete_post_meta($id, 'wp_pattern_sync_status');
            } else {
                update_post_meta($id, 'wp_pattern_sync_status', 'unsynced');
            }
            if ($term_id > 0) {
                wp_set_object_terms($id, [$term_id], self::CATEGORY_TAXONOMY, true);
            }
            $written++;
        }

        return sprintf('%d patterns written', $written);
    }

    /** Delete what the library created, restore what it changed; edited or in-use patterns are kept unless $force. */
    public function undo(Undo $undo, bool $force): string
    {
        $restored = 0;
        $kept = 0;
        foreach (array_reverse($this->library->patterns()) as $p) {
            $key = 'pattern:' . $p['slug'];
            if (!$undo->has($key)) {
                continue;
            }
            $post = self::find($p['slug']);
            $compiled = (new BlockCompiler())->compile($p['tree'], $p['slug']);
            $ours = $post !== null && is_array($compiled) && $post->post_content === $compiled['markup'];
            if ($post !== null && !$ours && !$force) {
                \WP_CLI::warning(sprintf('%s edited since the library wrote it — kept (--force restores it)', $p['slug']));
                $kept++;
                continue;
            }
            $old = $undo->get($key);
            if (isset($old['absent'])) {
                if ($post !== null) {
                    $used = self::used_on($post->ID);
                    if ($used > 0 && !$force) {
                        \WP_CLI::warning(sprintf('%s is used on %d posts — kept, deleting it would leave a missing block there (--force deletes it)', $p['slug'], $used));
                        $kept++;
                        continue;
                    }
                    wp_delete_post($post->ID, true);
                }
            } elseif ($post !== null) {
                self::write($post->ID, ['post_title' => $old['title'], 'post_content' => (string) $old['content']]);
                if ($old['synced']) {
                    delete_post_meta($post->ID, 'wp_pattern_sync_status');
                } else {
                    update_post_meta($post->ID, 'wp_pattern_sync_status', 'unsynced');
                }
            }
            $undo->forget($key);
            $restored++;
        }

        return sprintf('%d patterns restored, %d kept', $restored, $kept);
    }

    public static function find(string $slug): ?\WP_Post
    {
        $posts = get_posts(['post_type' => 'wp_block', 'name' => $slug, 'post_status' => ['publish', 'draft', 'private'], 'numberposts' => 1, 'suppress_filters' => true]);

        return $posts[0] ?? null;
    }

    /**
     * Insert or update a post with compiled markup. Without a user who has
     * unfiltered_html (the CLI), kses would rewrite the markup on save and the
     * next run would see a change that is not there.
     */
    public static function write(?int $id, array $args): int
    {
        $kses = has_filter('content_save_pre', 'wp_filter_post_kses');
        kses_remove_filters();
        try {
            if (isset($args['post_content'])) {
                $args['post_content'] = wp_slash($args['post_content']);
            }
            $result = $id === null ? wp_insert_post($args, true) : wp_update_post(['ID' => $id] + $args, true);
        } finally {
            if ($kses) {
                kses_init_filters();
            }
        }
        if (is_wp_error($result)) {
            throw new \RuntimeException(($args['post_name'] ?? (string) $id) . ': ' . $result->get_error_message());
        }

        return (int) $result;
    }

    // -------------------------------------------------------------------------

    private function same(\WP_Post $post, array $p, string $markup): bool
    {
        return $post->post_content === $markup
            && $post->post_title === $p['title']
            && $post->post_status === 'publish'
            && (get_post_meta($post->ID, 'wp_pattern_sync_status', true) !== 'unsynced') === $p['synced'];
    }

    private function category(): int
    {
        $cat = $this->library->pattern_category();
        if (empty($cat['slug']) || !taxonomy_exists(self::CATEGORY_TAXONOMY)) {
            return 0;
        }
        $term = get_term_by('slug', (string) $cat['slug'], self::CATEGORY_TAXONOMY);
        if ($term instanceof \WP_Term) {
            return (int) $term->term_id;
        }
        $made = wp_insert_term((string) ($cat['label'] ?? $cat['slug']), self::CATEGORY_TAXONOMY, ['slug' => (string) $cat['slug']]);

        return is_wp_error($made) ? 0 : (int) $made['term_id'];
    }

    private static function used_on(int $id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status IN ('publish','private','draft','future','pending')
             AND post_type NOT IN ('revision') AND post_content LIKE %s",
            '%' . $wpdb->esc_like('"ref":' . $id) . '%',
        ));
    }
}
