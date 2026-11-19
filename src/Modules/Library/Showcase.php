<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Library;

use Valolink\Plugin\Modules\Accesslink\BlockCompiler;
use Valolink\Plugin\Modules\Accesslink\StyleReader;

/**
 * The library's showcase pages from showcase.json: a style guide with every
 * token and class named, and example pages built from the library alone.
 * Pages by slug, recreated on each run, marked with META and noindex; a page's
 * "meta" sets post meta, e.g. the theme's layout switches.
 *
 * Besides compiler nodes, a showcase tree may hold nodes that show the
 * library itself, written as raw HTML (they exist to show values, so inline
 * styles are fine there):
 *   {"html": "<…>"}           raw HTML
 *   {"tokens": "<category>"}  the site's tokens in that category: colour swatches, other values as rows
 *   {"styles": "<category>"}  the global classes in that category, named
 *   {"patterns": true}        the library's patterns, by title, synced or not
 */
final class Showcase
{
    public const META = '_valolink_library_showcase';

    public function __construct(private readonly Library $library)
    {
    }

    /** @return list<string> the pages' URLs */
    public function build(string $status): array
    {
        $urls = [];
        $compiler = new BlockCompiler();
        foreach ($this->library->pages() as $page) {
            $markup = '';
            foreach ($page['blocks'] as $i => $block) {
                $raw = [];
                $tree = $this->swap($block, $raw);
                $r = $compiler->compile($tree, $page['slug'] . '-' . $i);
                if (is_wp_error($r)) {
                    throw new \RuntimeException(sprintf('%s block %d: %s', $page['slug'], $i, $r->get_error_message()));
                }
                // Raw HTML went through the compiler as placeholder paragraphs; back as core/html.
                $markup .= preg_replace_callback(
                    '#<!-- wp:paragraph -->\s*<p>VLRAW(\d+)</p>\s*<!-- /wp:paragraph -->#',
                    static fn (array $m): string => "<!-- wp:html -->\n" . $raw[(int) $m[1]] . "\n<!-- /wp:html -->",
                    $r['markup'],
                ) . "\n\n";
            }
            $existing = get_page_by_path($page['slug'], OBJECT, 'page');
            $id = PatternWriter::write($existing?->ID, [
                'post_type' => 'page', 'post_status' => $status, 'post_name' => $page['slug'],
                'post_title' => $page['title'], 'post_content' => $markup,
            ]);
            foreach ($page['meta'] as $key => $value) {
                update_post_meta($id, $key, $value);
            }
            update_post_meta($id, self::META, 1);
            update_post_meta($id, 'rank_math_robots', ['noindex', 'nofollow']);
            update_post_meta($id, '_yoast_wpseo_meta-robots-noindex', '1');
            $urls[] = get_permalink($id);
        }

        return $urls;
    }

    /** @return list<string> slugs deleted */
    public static function remove(): array
    {
        $out = [];
        foreach (get_posts(['post_type' => 'page', 'meta_key' => self::META, 'post_status' => 'any', 'numberposts' => -1]) as $p) {
            wp_delete_post($p->ID, true);
            $out[] = $p->post_name;
        }

        return $out;
    }

    // -------------------------------------------------------------------------

    /** Showcase-only nodes → placeholder paragraphs, their HTML kept in $raw. */
    private function swap(array $node, array &$raw): array
    {
        // Loops, not array_map: an arrow function would capture $raw by value.
        if (array_is_list($node)) {
            foreach ($node as $i => $n) {
                $node[$i] = $this->swap((array) $n, $raw);
            }

            return $node;
        }
        $html = match (true) {
            isset($node['html'])     => (string) $node['html'],
            isset($node['tokens'])   => $this->tokens((string) $node['tokens']),
            isset($node['styles'])   => $this->styles((string) $node['styles']),
            isset($node['patterns']) => $this->patterns(),
            default                  => null,
        };
        if ($html !== null) {
            $raw[] = $html;

            return ['paragraph' => 'VLRAW' . (count($raw) - 1)];
        }
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $i => $c) {
                $node['children'][$i] = $this->swap((array) $c, $raw);
            }
        }

        return $node;
    }

    private function tokens(string $category): string
    {
        $rows = array_values(array_filter((new StyleReader())->tokens(), static fn (array $t): bool => $t['category'] === $category));
        if ($rows === []) {
            return '<p><em>No tokens in category ' . esc_html($category) . '.</em></p>';
        }
        $out = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px">';
        foreach ($rows as $t) {
            $name = esc_html($t['name']);
            $label = '<p style="margin:6px 0 0;font-size:14px;line-height:1.4"><strong>' . esc_html($t['label'] ?: $t['name']) . '</strong><br><code>' . $name . '</code><br><span style="opacity:.7">' . esc_html($t['value']) . '</span></p>';
            if ($t['type'] === 'color') {
                $out .= '<div><div style="height:64px;border-radius:8px;border:1px solid currentColor;background:var(' . esc_attr($t['name']) . ')"></div>' . $label . '</div>';
            } elseif (str_starts_with($t['name'], '--space') || str_starts_with($t['name'], '--radius')) {
                $is_radius = str_starts_with($t['name'], '--radius');
                $shape = $is_radius
                    ? 'width:64px;height:64px;border:2px solid currentColor;border-radius:var(' . esc_attr($t['name']) . ')'
                    : 'width:var(' . esc_attr($t['name']) . ');height:16px;background:currentColor;opacity:.6';
                $out .= '<div><div style="' . $shape . '"></div>' . $label . '</div>';
            } else {
                $out .= '<div>' . $label . '</div>';
            }
        }

        return $out . '</div>';
    }

    private function styles(string $category): string
    {
        $names = [];
        foreach ((new StyleReader())->styles() as $s) {
            if ($s['category'] === $category) {
                $names[] = '<code>' . esc_html($s['selector']) . '</code>';
            }
        }

        return '<p style="font-size:14px">' . ($names !== [] ? implode(' · ', $names) : '<em>No classes in category ' . esc_html($category) . '.</em>') . '</p>';
    }

    private function patterns(): string
    {
        $items = '';
        foreach ($this->library->patterns() as $p) {
            $items .= '<li>' . esc_html($p['title']) . ' <span style="opacity:.7">(' . ($p['synced'] ? 'synkronoitu' : 'pohja') . ', <code>' . esc_html($p['slug']) . '</code>)</span></li>';
        }

        return '<ul>' . $items . '</ul>';
    }
}
