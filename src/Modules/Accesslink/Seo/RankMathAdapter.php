<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink\Seo;

final class RankMathAdapter implements SeoAdapter
{
    private const MAP = [
        'seo_title'       => 'rank_math_title',
        'seo_description' => 'rank_math_description',
        'focus_keyword'   => 'rank_math_focus_keyword',
    ];

    public function id(): string
    {
        return 'rank_math';
    }

    public function label(): string
    {
        return 'Rank Math SEO';
    }

    public function is_active(): bool
    {
        return defined('RANK_MATH_VERSION') || class_exists('RankMath');
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
            // Deleting rather than storing '' matters: Rank Math treats a
            // missing key as "fall back to the global template", while an empty
            // string is a deliberate empty override.
            if ($value === '') {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, $value);
            }
        }
    }

    public function variables(): array
    {
        return ['title' => '%title%', 'sep' => '%sep%', 'sitename' => '%sitename%'];
    }

    public function title_template(string $post_type): string
    {
        if (!class_exists('\RankMath\Helper') || !method_exists('\RankMath\Helper', 'get_settings')) {
            return '';
        }
        try {
            $template = \RankMath\Helper::get_settings('titles.pt_' . $post_type . '_title', '');
        } catch (\Throwable $e) {
            return '';
        }

        return is_string($template) ? $template : '';
    }

    public function render(int $post_id, string $value): string
    {
        if ($value === '' || !class_exists('\RankMath\Helper') || !method_exists('\RankMath\Helper', 'replace_vars')) {
            return '';
        }
        $post = get_post($post_id);
        try {
            return (string) \RankMath\Helper::replace_vars($value, $post instanceof \WP_Post ? $post : []);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
