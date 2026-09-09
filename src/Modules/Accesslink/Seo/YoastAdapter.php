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

    public function variables(): array
    {
        return ['title' => '%%title%%', 'sep' => '%%sep%%', 'sitename' => '%%sitename%%'];
    }

    public function title_template(string $post_type): string
    {
        if (!class_exists('\WPSEO_Options')) {
            return '';
        }
        try {
            $template = \WPSEO_Options::get('title-' . $post_type, '');
        } catch (\Throwable $e) {
            return '';
        }

        return is_string($template) ? $template : '';
    }

    public function render(int $post_id, string $value): string
    {
        if ($value === '' || !function_exists('wpseo_replace_vars')) {
            return '';
        }
        $post = get_post($post_id);
        try {
            return (string) wpseo_replace_vars($value, $post instanceof \WP_Post ? $post : []);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
