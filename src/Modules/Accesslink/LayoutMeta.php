<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * GeneratePress per-page layout, as three proposable fields.
 *
 * A page created through Accesslink used to open with the theme's default
 * layout, because the sidebar, content-container and title choices live in
 * `_generate-*` postmeta that only the editor wrote. A page built from
 * full-width front-page sections then rendered inside a sidebar layout until
 * someone fixed it by hand — the draft looked wrong for a reason that had
 * nothing to do with its content.
 *
 * Values are the words the editor shows, not the strings the theme stores:
 * `full-width` rather than the literal `true` GeneratePress keeps for it, and
 * `default` for "inherit the site setting" rather than an empty string.
 */
final class LayoutMeta
{
    public const FIELDS = ['sidebar_layout', 'content_container', 'hide_title'];

    private const META = [
        'sidebar_layout'    => '_generate-sidebar-layout-meta',
        'content_container' => '_generate-full-width-content',
        'hide_title'        => '_generate-disable-headline',
    ];

    public const SIDEBAR_VALUES = [
        'default', 'no-sidebar', 'right-sidebar', 'left-sidebar', 'both-sidebars', 'both-left', 'both-right',
    ];

    public const CONTAINER_VALUES = ['default', 'full-width', 'contained'];

    /** Only GeneratePress reads these keys; on any other theme they are dead weight. */
    public static function available(): bool
    {
        return function_exists('get_template') && get_template() === 'generatepress';
    }

    /** Elements are the furniture, not a page with a sidebar of its own. */
    public static function applies_to(string $post_type): bool
    {
        return self::available() && $post_type !== ElementReader::POST_TYPE;
    }

    /**
     * Normalised current values, always all three keys.
     *
     * @return array{sidebar_layout: string, content_container: string, hide_title: string}
     */
    public function read(int $post_id): array
    {
        $sidebar   = (string) get_post_meta($post_id, self::META['sidebar_layout'], true);
        $container = (string) get_post_meta($post_id, self::META['content_container'], true);
        $title     = (string) get_post_meta($post_id, self::META['hide_title'], true);

        return [
            'sidebar_layout'    => $sidebar !== '' && in_array($sidebar, self::SIDEBAR_VALUES, true) ? $sidebar : 'default',
            'content_container' => $container === 'true' ? 'full-width' : ($container === 'contained' ? 'contained' : 'default'),
            'hide_title'        => $title === 'true' ? 'true' : 'false',
        ];
    }

    public function read_field(int $post_id, string $field): string
    {
        return $this->read($post_id)[$field] ?? '';
    }

    /**
     * Coerce what an agent sent into the canonical strings, or explain why not.
     *
     * @param array<string, mixed> $fields
     * @return array<string, string>|\WP_Error
     */
    public function normalise(array $fields): array|\WP_Error
    {
        $out = [];

        if (array_key_exists('sidebar_layout', $fields)) {
            $value = sanitize_key((string) $fields['sidebar_layout']);
            if (!in_array($value, self::SIDEBAR_VALUES, true)) {
                return new \WP_Error(
                    'bad_sidebar_layout',
                    'sidebar_layout must be one of: ' . implode(', ', self::SIDEBAR_VALUES) . '.',
                    ['status' => 400],
                );
            }
            $out['sidebar_layout'] = $value;
        }

        if (array_key_exists('content_container', $fields)) {
            $value = sanitize_key((string) $fields['content_container']);
            if (!in_array($value, self::CONTAINER_VALUES, true)) {
                return new \WP_Error(
                    'bad_content_container',
                    'content_container must be one of: ' . implode(', ', self::CONTAINER_VALUES) . '.',
                    ['status' => 400],
                );
            }
            $out['content_container'] = $value;
        }

        if (array_key_exists('hide_title', $fields)) {
            $out['hide_title'] = self::truthy($fields['hide_title']) ? 'true' : 'false';
        }

        return $out;
    }

    /** @param array<string, string> $fields already normalised */
    public function write(int $post_id, array $fields): void
    {
        if (array_key_exists('sidebar_layout', $fields)) {
            $this->store($post_id, self::META['sidebar_layout'], $fields['sidebar_layout'] === 'default' ? '' : $fields['sidebar_layout']);
        }
        if (array_key_exists('content_container', $fields)) {
            $map = ['default' => '', 'full-width' => 'true', 'contained' => 'contained'];
            $this->store($post_id, self::META['content_container'], $map[$fields['content_container']] ?? '');
        }
        if (array_key_exists('hide_title', $fields)) {
            $this->store($post_id, self::META['hide_title'], $fields['hide_title'] === 'true' ? 'true' : '');
        }
    }

    /** An empty value means "inherit", which GeneratePress reads as the key being absent. */
    private function store(int $post_id, string $key, string $value): void
    {
        if ($value === '') {
            delete_post_meta($post_id, $key);
        } else {
            update_post_meta($post_id, $key, $value);
        }
    }

    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
