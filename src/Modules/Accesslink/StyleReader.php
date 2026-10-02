<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * The site's design system as an agent needs it: GenerateBlocks Pro's design
 * tokens (the managed `:root` global style) and global styles, and the block
 * patterns (`wp_block`) with their override slots.
 *
 * Agent-written block content uses these instead of styling each block, so
 * the guide and `GET /styles` list them, and style proposals are checked
 * against them. Read-only; StyleApplier writes.
 */
final class StyleReader
{
    public const POST_TYPE = 'gblocks_styles';

    /** Post types GenerateBlocks itself scans for usage, minus the noise. */
    private const USAGE_STATUSES = ['publish', 'private', 'draft', 'future', 'pending'];

    public static function available(): bool
    {
        return class_exists('GenerateBlocks_Pro_Styles') && post_type_exists(self::POST_TYPE);
    }

    public static function tokens_available(): bool
    {
        return self::available()
            && class_exists('GenerateBlocks_Pro_Styles_Root')
            && class_exists('GenerateBlocks_Pro_Design_Tokens');
    }

    /**
     * Design tokens: every registered token with its current value, plus any
     * custom property on :root that has no registry row (type "unregistered").
     *
     * @return list<array{name: string, value: string, type: string, label: string, category: string, scope: list<string>}>
     */
    public function tokens(): array
    {
        if (!self::tokens_available()) {
            return [];
        }
        $state = \GenerateBlocks_Pro_Styles_Root::get_state();
        $values = is_array($state['styles'] ?? null) ? $state['styles'] : [];
        $out = [];
        $seen = [];
        foreach ((array) ($state['tokens'] ?? []) as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $seen[$name] = true;
            $out[] = [
                'name'     => $name,
                'value'    => is_scalar($values[$name] ?? null) ? (string) $values[$name] : '',
                'type'     => (string) ($row['type'] ?? 'color'),
                'label'    => (string) ($row['label'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'scope'    => array_values(array_map('strval', (array) ($row['scope'] ?? []))),
            ];
        }
        foreach ($values as $name => $value) {
            if (is_string($name) && str_starts_with($name, '--') && !isset($seen[$name]) && is_scalar($value)) {
                $out[] = ['name' => $name, 'value' => (string) $value, 'type' => 'unregistered', 'label' => '', 'category' => '', 'scope' => []];
            }
        }

        return $out;
    }

    /** Compare token for the whole :root record (GenerateBlocks' own checksum). */
    public function tokens_hash(): string
    {
        if (!self::tokens_available()) {
            return hash('sha256', 'no-tokens');
        }

        return hash('sha256', (string) (\GenerateBlocks_Pro_Styles_Root::get_state()['checksum'] ?? ''));
    }

    /**
     * Published global styles in cascade order, the :root record excluded.
     *
     * @return list<array{id: int, selector: string, category: string, styles: array, css: string, targets: list<string>}>
     */
    public function styles(): array
    {
        if (!self::available()) {
            return [];
        }
        $posts = get_posts([
            'post_type'        => self::POST_TYPE,
            'post_status'      => 'publish',
            'numberposts'      => 500,
            'orderby'          => 'menu_order',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);
        $out = [];
        foreach ($posts as $post) {
            $style = $this->describe($post);
            if ($style['selector'] !== '' && $style['selector'] !== ':root') {
                $out[] = $style;
            }
        }

        return $out;
    }

    /** The style that owns a selector (published or draft), or null. */
    public function find(string $selector): ?array
    {
        if (!self::available()) {
            return null;
        }
        $posts = get_posts([
            'post_type'        => self::POST_TYPE,
            'post_status'      => ['publish', 'draft'],
            'numberposts'      => 1,
            'meta_key'         => 'gb_style_selector',
            'meta_value'       => $selector,
            'suppress_filters' => true,
        ]);

        return $posts ? $this->describe($posts[0]) : null;
    }

    /** Compare token for one selector: what a proposal was made against. */
    public function style_hash(string $selector): string
    {
        $style = $this->find($selector);

        return hash('sha256', $style === null
            ? 'absent:' . $selector
            : (string) wp_json_encode([$style['id'], $style['styles'], $style['css'], $style['category']]));
    }

    /**
     * Posts whose markup carries a class (GenerateBlocks' own usage rule:
     * the class as a whole token inside a class attribute).
     *
     * @return array{count: int, posts: list<array{id: int, title: string, post_type: string}>}
     */
    public function class_usage(string $class_name, int $list = 10): array
    {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($class_name) . '%';
        $statuses = "'" . implode("','", self::USAGE_STATUSES) . "'";
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
             WHERE post_status IN ($statuses) AND post_type NOT IN ('revision','attachment','nav_menu_item')
               AND post_content LIKE %s LIMIT 5000",
            $like,
        ));
        $count = 0;
        $posts = [];
        foreach ((array) $rows as $row) {
            if (!preg_match_all('/class\s*=\s*"([^"]*)"/i', (string) $row->post_content, $m)) {
                continue;
            }
            $hit = false;
            foreach ($m[1] as $classes) {
                if (in_array($class_name, preg_split('/\s+/', trim($classes)) ?: [], true)) {
                    $hit = true;
                    break;
                }
            }
            // Block attributes carry it too, before the markup is rendered.
            $hit = $hit || str_contains((string) $row->post_content, '"' . $class_name . '"');
            if ($hit) {
                $count++;
                if (count($posts) < $list) {
                    $posts[] = ['id' => (int) $row->ID, 'title' => (string) $row->post_title, 'post_type' => (string) $row->post_type];
                }
            }
        }

        return ['count' => $count, 'posts' => $posts];
    }

    /** How many posts and global styles use var(--name). */
    public function token_usage(string $name): int
    {
        global $wpdb;
        $statuses = "'" . implode("','", self::USAGE_STATUSES) . "'";
        $posts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status IN ($statuses) AND post_type NOT IN ('revision','attachment','nav_menu_item')
               AND (post_content LIKE %s OR post_content LIKE %s)",
            '%var(' . $wpdb->esc_like($name) . '%',
            '%var(' . $wpdb->esc_like(str_replace('--', '\\u002d\\u002d', $name)) . '%',
        ));
        $styles = 0;
        foreach ($this->styles() as $style) {
            if (preg_match('/\bvar\(\s*' . preg_quote($name, '/') . '\s*[,)]/', $style['css'])) {
                $styles++;
            }
        }

        return $posts + $styles;
    }

    /**
     * Block patterns (wp_block): synced or not, and the names of the blocks a
     * page can override in a synced one.
     *
     * @return list<array{id: int, title: string, synced: bool, slots: list<array{name: string, block: string}>, used_on: int}>
     */
    public function patterns(): array
    {
        global $wpdb;
        $posts = get_posts([
            'post_type'        => 'wp_block',
            'post_status'      => 'publish',
            'numberposts'      => 200,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);
        $out = [];
        foreach ($posts as $post) {
            $slots = [];
            $this->collect_slots(parse_blocks((string) $post->post_content), $slots);
            $used = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status IN ('publish','private','draft')
                 AND post_type NOT IN ('revision','wp_block') AND post_content LIKE %s",
                '%' . $wpdb->esc_like('"ref":' . $post->ID) . '%',
            ));
            $out[] = [
                'id'      => (int) $post->ID,
                'title'   => (string) $post->post_title,
                'synced'  => get_post_meta($post->ID, 'wp_pattern_sync_status', true) !== 'unsynced',
                'slots'   => $slots,
                'used_on' => $used,
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------------------

    private function describe(\WP_Post $post): array
    {
        $data = get_post_meta($post->ID, 'gb_style_data', true);
        $targets = get_post_meta($post->ID, 'gb_style_targets', true);

        return [
            'id'       => (int) $post->ID,
            'selector' => (string) get_post_meta($post->ID, 'gb_style_selector', true),
            'category' => (string) get_post_meta($post->ID, 'gb_style_category', true),
            'styles'   => is_array($data) ? $data : [],
            'css'      => (string) get_post_meta($post->ID, 'gb_style_css', true),
            'targets'  => is_array($targets) ? array_values(array_map('strval', $targets)) : [],
        ];
    }

    /** @param list<array{name: string, block: string}> $slots */
    private function collect_slots(array $blocks, array &$slots): void
    {
        foreach ($blocks as $block) {
            $meta = $block['attrs']['metadata'] ?? [];
            if (isset($meta['name'], $meta['bindings']) && is_array($meta['bindings'])) {
                foreach ($meta['bindings'] as $binding) {
                    if (($binding['source'] ?? '') === 'core/pattern-overrides') {
                        $slots[] = ['name' => (string) $meta['name'], 'block' => (string) ($block['blockName'] ?? '')];
                        break;
                    }
                }
            }
            if (!empty($block['innerBlocks'])) {
                $this->collect_slots($block['innerBlocks'], $slots);
            }
        }
    }
}
