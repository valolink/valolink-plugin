<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink\Seo;

final class YoastAdapter implements SeoAdapter
{
    private const MAP = [
        'seo_title'       => '_yoast_wpseo_title',
        'seo_description' => '_yoast_wpseo_metadesc',
        'focus_keyword'   => '_yoast_wpseo_focuskw',
    ];

    public function id(): string
    {
        return 'yoast';
    }

    public function label(): string
    {
        return 'Yoast SEO';
    }

    public function is_active(): bool
    {
        return defined('WPSEO_VERSION');
    }

    public function can_write(): bool
    {
        return true;
    }

    public function read(int $post_id): array
    {
        $out = [];
        foreach (self::MAP as $field => $key) {
            $out[$field] = (string) get_post_meta($post_id, $key, true);
        }

        return $out;
    }

    public function write(int $post_id, array $fields): void
    {
        foreach (self::MAP as $field => $key) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = (string) $fields[$field];
            if ($value === '') {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, $value);
            }
        }
    }
}
