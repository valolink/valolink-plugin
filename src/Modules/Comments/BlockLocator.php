<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Comments;

use Valolink\Plugin\Modules\Accesslink\BlockReader;

/**
 * Which block of a post a quoted text lives in, for the agent: the deepest
 * editable block whose text contains the quote, by Accesslink's own reader
 * and paths. Nothing in the rendered page needs marking for this, and text
 * a script rendered simply has no block. Only when Accesslink is present.
 */
final class BlockLocator
{
    public static function available(): bool
    {
        return class_exists(BlockReader::class);
    }

    /** @return array{path: string, name: string}|null */
    public static function locate(int $post_id, string $quote): ?array
    {
        if (!self::available() || $post_id <= 0) {
            return null;
        }
        $needle = self::norm($quote);
        if (mb_strlen($needle) < 8) {
            return null;
        }
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || !has_blocks($post->post_content)) {
            return null;
        }
        $best = null;
        foreach ((new BlockReader())->flatten((string) $post->post_content)['blocks'] as $block) {
            if (empty($block['editable'])) {
                continue;
            }
            $text = self::norm(wp_strip_all_tags((string) ($block['html'] ?? '')));
            if ($text === '' || !str_contains($text, $needle)) {
                continue;
            }
            // The deepest match is the most specific; the first among equals.
            if ($best === null || (int) $block['depth'] > $best['depth']) {
                $best = ['path' => (string) $block['path'], 'name' => (string) $block['name'], 'depth' => (int) $block['depth']];
            }
        }

        return $best === null ? null : ['path' => $best['path'], 'name' => $best['name']];
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}
