<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Modules\Accesslink\Translation\TranslationAdapterFactory;

/**
 * Reads navigation menus as trees.
 *
 * A menu is a `nav_menu` term whose items are `nav_menu_item` posts, each
 * carrying its target and its parent in postmeta and its position in
 * `menu_order`. Flat, in other words, with the hierarchy implied — which is why
 * neither a post list nor a text diff says anything useful about one.
 *
 * Everything here reshapes that into the nested form a person actually pictures
 * when they say "the menu", because that is the form an agent has to send back
 * and the reviewer has to check.
 */
final class MenuReader
{
    /** Deep enough for any real navigation; a guard against a runaway payload. */
    public const MAX_DEPTH = 5;
    public const MAX_ITEMS = 200;

    /** Item types WordPress understands. */
    public const TYPES = ['post_type', 'taxonomy', 'custom', 'post_type_archive'];

    /**
     * Every menu on the site, with where it is used and what language it is in.
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        $tr        = TranslationAdapterFactory::detect();
        $locations = $this->locations_by_menu();

        $menus = [];
        foreach (wp_get_nav_menus() as $menu) {
            $row = [
                'id'        => (int) $menu->term_id,
                'name'      => $menu->name,
                'slug'      => $menu->slug,
                'items'     => (int) $menu->count,
                'locations' => $locations[(int) $menu->term_id] ?? [],
            ];
            if ($tr->available()) {
                $row['language'] = $tr->language_of_term((int) $menu->term_id) ?: null;
            }
            $menus[] = $row;
        }

        return [
            'menus'                => $menus,
            'registered_locations' => get_registered_nav_menus(),
        ];
    }

    public function get(int $menu_id): array|\WP_Error
    {
        $menu = wp_get_nav_menu_object($menu_id);
        if (!$menu) {
            return new \WP_Error('not_found', 'No such menu.', ['status' => 404]);
        }

        $tr = TranslationAdapterFactory::detect();
        $out = [
            'id'        => (int) $menu->term_id,
            'name'      => $menu->name,
            'slug'      => $menu->slug,
            'locations' => $this->locations_by_menu()[(int) $menu->term_id] ?? [],
            'items'     => $this->tree($menu_id),
        ];
        if ($tr->available()) {
            $out['language'] = $tr->language_of_term((int) $menu->term_id) ?: null;
        }

        return $out;
    }

    /**
     * The menu as a nested tree.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(int $menu_id): array
    {
        $items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache' => false]);
        if (!is_array($items)) {
            return [];
        }

        $by_parent = [];
        foreach ($items as $item) {
            $by_parent[(int) $item->menu_item_parent][] = $item;
        }

        return $this->branch($by_parent, 0, 1);
    }

    /**
     * A stable digest of the menu's shape and targets.
     *
     * Ids are deliberately included: an item replaced by an identical-looking
     * one is still a different item, and a proposal that assumed the old one
     * should not apply silently.
     */
    public function hash(int $menu_id): string
    {
        return hash('sha256', (string) wp_json_encode($this->tree($menu_id)));
    }

    /**
     * The tree rendered as indented text, for the review diff.
     *
     * A menu diffed as JSON is unreadable and diffed as prose is meaningless;
     * what a reviewer needs to see is the shape and where each entry points.
     */
    public function outline(int $menu_id): string
    {
        return implode("\n", $this->outline_lines($this->tree($menu_id), 0));
    }

    /** @return array<int, string> */
    private function outline_lines(array $items, int $depth): array
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = str_repeat('    ', $depth)
                . $item['label']
                . '  →  ' . $this->describe_target($item);
            if (!empty($item['children'])) {
                $lines = array_merge($lines, $this->outline_lines($item['children'], $depth + 1));
            }
        }

        return $lines;
    }

    private function describe_target(array $item): string
    {
        return match ($item['type']) {
            'post_type'         => sprintf('%s #%d (%s)', $item['object'], $item['object_id'], $item['url']),
            'taxonomy'          => sprintf('%s #%d', $item['object'], $item['object_id']),
            'post_type_archive' => sprintf('archive: %s', $item['object']),
            default             => (string) $item['url'],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function branch(array $by_parent, int $parent, int $depth): array
    {
        if (!isset($by_parent[$parent]) || $depth > self::MAX_DEPTH) {
            return [];
        }

        $out = [];
        foreach ($by_parent[$parent] as $item) {
            $out[] = [
                'id'          => (int) $item->ID,
                'label'       => (string) $item->title,
                'type'        => (string) $item->type,
                'object'      => (string) $item->object,
                'object_id'   => (int) $item->object_id,
                'url'         => (string) $item->url,
                'target'      => (string) $item->target,
                'classes'     => array_values(array_filter((array) $item->classes)),
                'description' => (string) $item->description,
                'children'    => $this->branch($by_parent, (int) $item->ID, $depth + 1),
            ];
        }

        return $out;
    }

    /**
     * Which theme locations each menu occupies.
     *
     * With Polylang the same location exists once per language, and the mapping
     * lives in the plugin's own option rather than in the theme mod — so a menu
     * can be "in the primary location" for English only. Reading the filtered
     * theme mod would report just the current language and quietly mislead.
     *
     * @return array<int, array<int, string>>
     */
    private function locations_by_menu(): array
    {
        $out = [];

        foreach ((array) get_theme_mod('nav_menu_locations', []) as $location => $menu_id) {
            if ((int) $menu_id > 0) {
                $out[(int) $menu_id][] = (string) $location;
            }
        }

        $polylang = get_option('polylang');
        $theme    = get_stylesheet();
        foreach ((array) ($polylang['nav_menus'][$theme] ?? []) as $location => $per_language) {
            foreach ((array) $per_language as $lang => $menu_id) {
                if ((int) $menu_id > 0) {
                    $label = sprintf('%s [%s]', $location, $lang);
                    if (!in_array($label, $out[(int) $menu_id] ?? [], true)) {
                        $out[(int) $menu_id][] = $label;
                    }
                }
            }
        }

        return $out;
    }
}
