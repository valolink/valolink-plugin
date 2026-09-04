<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Validates and applies a whole menu tree.
 *
 * Whole-tree rather than per-item on purpose. Menu edits in practice are
 * "relabel these three, repoint that one at the translated page, and move the
 * last two under a parent" — expressed as a series of item operations that is
 * several proposals a reviewer has to hold in their head at once, and expressed
 * as one tree it is a single before-and-after they can read. It also makes the
 * staleness question honest: the thing being replaced is the whole menu, so the
 * thing hashed is the whole menu.
 *
 * The agent sends back what it read, keeping `id` on items that already exist.
 * An item with an id is updated in place, one without is created, and one
 * present in the menu but absent from the payload is deleted.
 */
final class MenuApplier
{
    /**
     * Check a proposed tree without writing anything.
     *
     * @param array<int, mixed> $items
     */
    public function validate(int $menu_id, array $items, array $allowed_post_types): true|\WP_Error
    {
        if (!wp_get_nav_menu_object($menu_id)) {
            return new \WP_Error('not_found', 'No such menu.', ['status' => 404]);
        }

        $existing = $this->existing_ids($menu_id);
        $seen     = [];
        $count    = 0;

        return $this->walk($items, 1, function (array $item, int $depth) use (
            $existing,
            &$seen,
            &$count,
            $allowed_post_types
        ): true|\WP_Error {
            if (++$count > MenuReader::MAX_ITEMS) {
                return new \WP_Error(
                    'too_many_items',
                    sprintf('A menu may have at most %d items.', MenuReader::MAX_ITEMS),
                    ['status' => 400],
                );
            }

            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                return new \WP_Error('item_no_label', 'Every menu item needs a label.', ['status' => 400]);
            }

            $type = (string) ($item['type'] ?? 'custom');
            if (!in_array($type, MenuReader::TYPES, true)) {
                return new \WP_Error(
                    'item_bad_type',
                    sprintf('Item "%s": type must be one of %s.', $label, implode(', ', MenuReader::TYPES)),
                    ['status' => 400],
                );
            }

            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) {
                if (!in_array($id, $existing, true)) {
                    return new \WP_Error(
                        'item_not_in_menu',
                        sprintf('Item "%s" has id %d, which is not in this menu. Omit id to add a new item.', $label, $id),
                        ['status' => 400],
                    );
                }
                if (isset($seen[$id])) {
                    return new \WP_Error(
                        'item_duplicated',
                        sprintf('Item id %d appears more than once.', $id),
                        ['status' => 400],
                    );
                }
                $seen[$id] = true;
            }

            if ($type === 'post_type') {
                $object_id = (int) ($item['object_id'] ?? 0);
                $target    = $object_id > 0 ? get_post($object_id) : null;
                if (!$target instanceof \WP_Post) {
                    return new \WP_Error(
                        'item_target_missing',
                        sprintf('Item "%s" points at post %d, which does not exist.', $label, $object_id),
                        ['status' => 400],
                    );
                }
                // The same allowlist that governs editing governs linking: a
                // menu is a way to surface a post type, and pointing at one the
                // operator did not open up would route around that.
                if (!in_array($target->post_type, $allowed_post_types, true)) {
                    return new \WP_Error(
                        'item_target_not_permitted',
                        sprintf('Item "%s" points at a %s, which is not a permitted post type.', $label, $target->post_type),
                        ['status' => 400],
                    );
                }
                if ($target->post_status === 'trash') {
                    return new \WP_Error(
                        'item_target_trashed',
                        sprintf('Item "%s" points at a trashed post.', $label),
                        ['status' => 400],
                    );
                }
            }

            if ($type === 'taxonomy' && (int) ($item['object_id'] ?? 0) <= 0) {
                return new \WP_Error('item_no_term', sprintf('Item "%s" needs object_id.', $label), ['status' => 400]);
            }

            if ($type === 'custom' && trim((string) ($item['url'] ?? '')) === '') {
                return new \WP_Error('item_no_url', sprintf('Item "%s" needs a url.', $label), ['status' => 400]);
            }

            return true;
        });
    }

    /**
     * Write the tree. Assumes validate() has already passed.
     *
     * @param array<int, mixed> $items
     */
    public function apply(int $menu_id, array $items): true|\WP_Error
    {
        $before = $this->existing_ids($menu_id);
        $kept   = [];

        $error = $this->write_level($menu_id, $items, 0, $kept);
        if ($error instanceof \WP_Error) {
            return $error;
        }

        // Anything the payload did not mention is gone. Deleted last so a
        // failure part-way through leaves the menu longer than intended rather
        // than shorter — a duplicate is visible and fixable, a silently
        // removed entry is neither.
        foreach (array_diff($before, $kept) as $orphan) {
            wp_delete_post($orphan, true);
        }

        return true;
    }

    /**
     * @param array<int, mixed> $items
     * @param array<int, int>   $kept
     */
    private function write_level(int $menu_id, array $items, int $parent_id, array &$kept): true|\WP_Error
    {
        $position = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $position++;

            $type = (string) ($item['type'] ?? 'custom');
            $args = [
                'menu-item-title'       => (string) $item['label'],
                'menu-item-type'        => $type,
                'menu-item-status'      => 'publish',
                'menu-item-parent-id'   => $parent_id,
                'menu-item-position'    => $position,
                'menu-item-target'      => (string) ($item['target'] ?? ''),
                'menu-item-description' => (string) ($item['description'] ?? ''),
                'menu-item-classes'     => implode(' ', array_map('sanitize_html_class', (array) ($item['classes'] ?? []))),
            ];

            if ($type === 'custom') {
                $args['menu-item-url'] = esc_url_raw((string) ($item['url'] ?? ''));
            } else {
                $args['menu-item-object']    = (string) ($item['object'] ?? '');
                $args['menu-item-object-id'] = (int) ($item['object_id'] ?? 0);
            }

            $id = wp_update_nav_menu_item($menu_id, (int) ($item['id'] ?? 0), $args);
            if (is_wp_error($id)) {
                return $id;
            }

            $kept[] = (int) $id;

            if (!empty($item['children']) && is_array($item['children'])) {
                $nested = $this->write_level($menu_id, $item['children'], (int) $id, $kept);
                if ($nested instanceof \WP_Error) {
                    return $nested;
                }
            }
        }

        return true;
    }

    /**
     * Depth-first walk applying $check to every item.
     *
     * @param array<int, mixed> $items
     */
    private function walk(array $items, int $depth, callable $check): true|\WP_Error
    {
        if ($depth > MenuReader::MAX_DEPTH) {
            return new \WP_Error(
                'menu_too_deep',
                sprintf('Menus may nest at most %d levels.', MenuReader::MAX_DEPTH),
                ['status' => 400],
            );
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                return new \WP_Error('item_not_object', 'Each menu item must be an object.', ['status' => 400]);
            }

            $result = $check($item, $depth);
            if ($result instanceof \WP_Error) {
                return $result;
            }

            if (!empty($item['children'])) {
                if (!is_array($item['children'])) {
                    return new \WP_Error('children_not_array', 'children must be an array.', ['status' => 400]);
                }
                $nested = $this->walk($item['children'], $depth + 1, $check);
                if ($nested instanceof \WP_Error) {
                    return $nested;
                }
            }
        }

        return true;
    }

    /** @return array<int, int> */
    private function existing_ids(int $menu_id): array
    {
        $items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache' => false]);

        return is_array($items) ? array_map(static fn ($i): int => (int) $i->ID, $items) : [];
    }
}
