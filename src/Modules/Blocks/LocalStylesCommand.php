<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Blocks;

/**
 * Finds blocks that carry their own styling instead of the site's global
 * classes. A site built from a style library (tokens + global classes, e.g.
 * kuumalahde's) stays one look only while content holds classes, not values;
 * hand edits in the editor add local values sooner or later, and this lists
 * them so they can be turned back into classes.
 *
 * Reported: GenerateBlocks blocks with local `styles` or `css`; core blocks
 * with colour, size, font, border or spacing set on the block (`style`,
 * `textColor`, `backgroundColor`, `gradient`, `fontSize`, `fontFamily`,
 * `borderColor`); any block with an inline `style` HTML attribute. Raw HTML
 * blocks are content and not inspected.
 */
final class LocalStylesCommand
{
    private const CORE_KEYS = ['style', 'textColor', 'backgroundColor', 'gradient', 'fontSize', 'fontFamily', 'borderColor'];

    /**
     * Lists blocks with local styling.
     *
     * ## OPTIONS
     *
     * [--post_type=<types>]
     * : Comma-separated post types. Default: every public type, patterns (wp_block) and GeneratePress Elements.
     *
     * [--format=<format>]
     * : table, csv or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp valolink blocks local-styles
     *     wp valolink blocks local-styles --post_type=page --format=csv
     *
     * @subcommand local-styles
     */
    public function local_styles(array $args, array $assoc): void
    {
        $types = isset($assoc['post_type'])
            ? array_filter(array_map('trim', explode(',', (string) $assoc['post_type'])))
            : array_values(array_unique(array_merge(get_post_types(['public' => true]), ['wp_block', 'gp_elements'])));
        $rows = [];
        $checked = 0;
        foreach (array_chunk(get_posts(['post_type' => $types, 'post_status' => ['publish', 'private', 'draft'], 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true]), 200) as $ids) {
            foreach ($ids as $id) {
                $content = (string) get_post_field('post_content', $id);
                if (!has_blocks($content)) {
                    continue;
                }
                $checked++;
                $this->walk(parse_blocks($content), (int) $id, $rows);
            }
        }
        if ($rows === []) {
            \WP_CLI::success(sprintf('No local styling in %d block-built posts.', $checked));
            return;
        }
        \WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, ['post', 'type', 'title', 'block', 'local']);
        \WP_CLI::warning(sprintf('%d blocks with local styling in %d posts (of %d block-built).', count($rows), count(array_unique(array_column($rows, 'post'))), $checked));
    }

    /** @param list<array<string, mixed>> $blocks @param list<array<string, mixed>> $rows */
    private function walk(array $blocks, int $post_id, array &$rows): void
    {
        foreach ($blocks as $block) {
            $name = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $found = [];
            if (str_starts_with($name, 'generateblocks/')) {
                if (!empty($attrs['styles']) && is_array($attrs['styles'])) {
                    $found[] = 'styles: ' . implode(', ', array_slice(array_keys($attrs['styles']), 0, 6));
                }
                if (!empty($attrs['css']) && is_string($attrs['css'])) {
                    $found[] = 'css';
                }
            } elseif (str_starts_with($name, 'core/') && $name !== 'core/html') {
                foreach (self::CORE_KEYS as $key) {
                    if (!empty($attrs[$key])) {
                        $found[] = $key;
                    }
                }
            }
            if (!empty($attrs['htmlAttributes']['style'])) {
                $found[] = 'inline style';
            }
            if ($found !== []) {
                $rows[] = [
                    'post'  => $post_id,
                    'type'  => get_post_type($post_id),
                    'title' => mb_substr((string) get_the_title($post_id), 0, 40),
                    'block' => $name,
                    'local' => implode('; ', $found),
                ];
            }
            if (!empty($block['innerBlocks'])) {
                $this->walk($block['innerBlocks'], $post_id, $rows);
            }
        }
    }
}
