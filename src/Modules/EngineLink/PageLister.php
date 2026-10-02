<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\EngineLink;

/**
 * The site's published content, for EngineLink's content workflow: which
 * pages (and, when asked, posts and other public post types) there are, so
 * EngineLink can read each one as a visitor sees it. Titles, permalinks, the
 * page tree, dates, categories and a word count only — the text itself is
 * never sent, since post_content misses what the theme and page builders
 * render; the word count is there so an operator can tell a real article
 * from a stub when choosing what to read.
 *
 * Read-only, like everything on this module. A security plugin that hides
 * the public REST API cannot hide this one, which is why EngineLink asks
 * here first and falls back to wp/v2, the sitemap and the front page's
 * links only for sites on an older plugin.
 */
final class PageLister
{
    /** Hard cap per post type; a type with more than this is not read whole anyway. */
    private const MAX = 500;

    /**
     * @param list<string> $types post types to list; unknown or non-public ones are ignored, none means pages
     * @return array{plugin_version: string, total: int, truncated: bool, truncated_types: list<string>, types: list<array{name: string, label: string, count: int}>, pages: list<array<string, mixed>>}
     */
    public function collect(array $types = ['page']): array
    {
        $public = $this->public_types();
        $wanted = array_values(array_filter(array_unique($types), static fn ($t) => isset($public[$t])));
        if ($wanted === []) {
            $wanted = ['page'];
        }
        $front = (int) get_option('page_on_front');

        $items     = [];
        $truncated = [];
        foreach ($wanted as $type) {
            $posts = get_posts([
                'post_type'              => $type,
                'post_status'            => 'publish',
                'posts_per_page'         => self::MAX + 1,
                // Pages in their menu order; everything else newest first.
                'orderby'                => $type === 'page' ? ['menu_order' => 'ASC', 'title' => 'ASC'] : ['date' => 'DESC'],
                'has_password'           => false,
                'suppress_filters'       => false,
                'update_post_term_cache' => true,
                'update_post_meta_cache' => false,
                // Every language, not only the current request's (Polylang).
                'lang'                   => '',
            ]);
            $posts = is_array($posts) ? $posts : [];
            if (count($posts) > self::MAX) {
                $truncated[] = $type;
                $posts       = array_slice($posts, 0, self::MAX);
            }
            $taxonomies = $this->category_taxonomies($type);
            foreach ($posts as $post) {
                if (!$post instanceof \WP_Post) {
                    continue;
                }
                $url = get_permalink($post);
                if (!is_string($url) || $url === '') {
                    continue;
                }
                $items[] = [
                    'id'         => (int) $post->ID,
                    'type'       => $type,
                    'url'        => $url,
                    'title'      => html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'parent'     => (int) $post->post_parent,
                    'menu_order' => (int) $post->menu_order,
                    'date'       => mysql_to_rfc3339((string) $post->post_date_gmt) ?: '',
                    'modified'   => mysql_to_rfc3339((string) $post->post_modified_gmt) ?: '',
                    'lang'       => $this->language((int) $post->ID),
                    'front'      => (int) $post->ID === $front,
                    'categories' => $this->categories($post, $taxonomies),
                    'words'      => $this->words((string) $post->post_content),
                ];
            }
        }

        return [
            'plugin_version'  => VALOLINK_PLUGIN_VERSION,
            'total'           => count($items),
            'truncated'       => $truncated !== [],
            'truncated_types' => $truncated,
            'types'           => array_values(array_map(
                static fn ($t) => ['name' => $t->name, 'label' => (string) $t->labels->name, 'count' => (int) (wp_count_posts($t->name)->publish ?? 0)],
                $public,
            )),
            'pages'           => $items,
        ];
    }

    /** Public post types a visitor can open, attachments aside. @return array<string, \WP_Post_Type> */
    private function public_types(): array
    {
        $types = get_post_types(['public' => true], 'objects');
        unset($types['attachment']);

        return is_array($types) ? $types : [];
    }

    /** The type's public hierarchical taxonomies: categories, product categories. @return list<string> */
    private function category_taxonomies(string $type): array
    {
        $out = [];
        foreach (get_object_taxonomies($type, 'objects') as $tax) {
            if ($tax->public && $tax->hierarchical) {
                $out[] = $tax->name;
            }
        }

        return $out;
    }

    /** @param list<string> $taxonomies @return list<string> */
    private function categories(\WP_Post $post, array $taxonomies): array
    {
        $names = [];
        foreach ($taxonomies as $tax) {
            $terms = get_the_terms($post, $tax);
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    $names[] = html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** Words in the stored content, shortcodes and tags removed: a size hint, not the text. */
    private function words(string $content): int
    {
        $text  = preg_replace('/\[\/?[A-Za-z_][^\]]*\]/', ' ', $content) ?? $content;
        $text  = wp_strip_all_tags($text);
        $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }

    /** The post's language slug when Polylang knows it, else null. */
    private function language(int $id): ?string
    {
        if (!function_exists('pll_get_post_language')) {
            return null;
        }
        $slug = pll_get_post_language($id, 'slug');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
