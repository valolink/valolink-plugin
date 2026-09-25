<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\EngineLink;

/**
 * The site's published pages, for EngineLink's content workflow: which pages
 * there are, so EngineLink can read each one as a visitor sees it. Titles,
 * permalinks, the page tree and modified times only — never post_content,
 * which misses what the theme and page builders render.
 *
 * Read-only, like everything on this module. A security plugin that hides
 * the public REST API cannot hide this one, which is why EngineLink asks
 * here first and falls back to wp/v2/pages, the sitemap and the front
 * page's links only for sites on an older plugin.
 */
final class PageLister
{
    /** Hard cap; a site with more pages than this is not read whole anyway. */
    private const MAX = 500;

    /**
     * @return array{plugin_version: string, total: int, truncated: bool, pages: list<array{id: int, url: string, title: string, parent: int, menu_order: int, modified: string, lang: string|null, front: bool}>}
     */
    public function collect(): array
    {
        $ids = get_posts([
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'posts_per_page'   => self::MAX + 1,
            'orderby'          => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'fields'           => 'ids',
            'has_password'     => false,
            'suppress_filters' => false,
            // Every language, not only the current request's (Polylang).
            'lang'             => '',
        ]);
        $ids       = array_map('intval', is_array($ids) ? $ids : []);
        $truncated = count($ids) > self::MAX;
        $ids       = array_slice($ids, 0, self::MAX);
        $front     = (int) get_option('page_on_front');

        $pages = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $url = get_permalink($post);
            if (!is_string($url) || $url === '') {
                continue;
            }
            $pages[] = [
                'id'         => $id,
                'url'        => $url,
                'title'      => html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'parent'     => (int) $post->post_parent,
                'menu_order' => (int) $post->menu_order,
                'modified'   => mysql_to_rfc3339((string) $post->post_modified_gmt) ?: '',
                'lang'       => $this->language($id),
                'front'      => $id === $front,
            ];
        }

        return [
            'plugin_version' => VALOLINK_PLUGIN_VERSION,
            'total'          => count($pages),
            'truncated'      => $truncated,
            'pages'          => $pages,
        ];
    }

    /** The page's language slug when Polylang knows it, else null. */
    private function language(int $id): ?string
    {
        if (!function_exists('pll_get_post_language')) {
            return null;
        }
        $slug = pll_get_post_language($id, 'slug');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
