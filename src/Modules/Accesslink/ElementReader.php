<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Modules\Accesslink\Translation\TranslationAdapterFactory;

/**
 * Describes GeneratePress Elements.
 *
 * Elements are ordinary posts of the `gp_elements` type, so everything else in
 * Accesslink already reads and edits them once the operator allows that post
 * type. What is *not* ordinary is what they mean: an Element is site furniture
 * — a hero, a footer, a script injected into wp_head — and its behaviour lives
 * entirely in `_generate_*` postmeta rather than in its content.
 *
 * Without that meta surfaced, an agent reading a site sees a pile of untitled
 * pages and cannot tell that the footer CTA is an Element rather than part of
 * each page, which is exactly the mistake that leads to editing twenty pages
 * instead of one.
 */
final class ElementReader
{
    public const POST_TYPE = 'gp_elements';

    /** GeneratePress stores these per Element; all are optional. */
    private const META = [
        'element_type'  => '_generate_element_type',
        'block_type'    => '_generate_block_type',
        'hook'          => '_generate_hook',
        'custom_hook'   => '_generate_custom_hook',
        'hook_priority' => '_generate_hook_priority',
    ];

    private const CONDITION_META = [
        'display' => '_generate_element_display_conditions',
        'exclude' => '_generate_element_exclude_conditions',
        'user'    => '_generate_element_user_conditions',
    ];

    public static function available(): bool
    {
        return post_type_exists(self::POST_TYPE);
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        if (!self::available()) {
            return ['available' => false, 'elements' => []];
        }

        $tr = TranslationAdapterFactory::detect();
        $posts = get_posts([
            'post_type'   => self::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => 200,
            'orderby'     => 'title',
            'order'       => 'ASC',
            // Elements are per-language when a multilingual plugin manages them,
            // and the default query would silently show only the current one.
            'lang'        => '',
        ]);

        $out = [];
        foreach ($posts as $post) {
            $out[] = $this->describe($post, $tr->available());
        }

        return [
            'available'   => true,
            'total'       => count($out),
            'multilingual' => $tr->available() && $tr->is_translated_type(self::POST_TYPE),
            'elements'    => $out,
        ];
    }

    public function get(int $id): array|\WP_Error
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== self::POST_TYPE) {
            return new \WP_Error('not_found', 'No such element.', ['status' => 404]);
        }

        $tr = TranslationAdapterFactory::detect();
        $described = $this->describe($post, $tr->available());
        $described['content_chars'] = strlen((string) $post->post_content);

        return $described;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(\WP_Post $post, bool $multilingual): array
    {
        $row = [
            'id'     => $post->ID,
            'title'  => $post->post_title,
            'status' => $post->post_status,
        ];

        foreach (self::META as $key => $meta_key) {
            $value = get_post_meta($post->ID, $meta_key, true);
            if ($value !== '' && $value !== null) {
                $row[$key] = is_scalar($value) ? $value : $value;
            }
        }

        foreach (self::CONDITION_META as $key => $meta_key) {
            $value = get_post_meta($post->ID, $meta_key, true);
            if (!empty($value)) {
                $row[$key . '_conditions'] = $value;
            }
        }

        if ($multilingual) {
            $tr = TranslationAdapterFactory::detect();
            $row['language']     = $tr->language_of($post->ID) ?: null;
            $row['translations'] = $tr->translations($post->ID);
        }

        return $row;
    }
}
