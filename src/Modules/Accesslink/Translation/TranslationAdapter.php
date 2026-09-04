<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink\Translation;

/**
 * What Accesslink needs from a multilingual plugin.
 *
 * Same shape as the SEO adapters: sites differ, and the absence of a plugin
 * narrows what Accesslink offers rather than breaking it. A site with no
 * multilingual plugin reports the translation endpoints unavailable instead of
 * erroring, and `create_translation` is refused by name.
 */
interface TranslationAdapter
{
    /** False when nothing usable is installed — the endpoints then 503. */
    public function available(): bool;

    /** Machine name of the resolved plugin, reported in GET /guide. */
    public function plugin(): string;

    /**
     * Configured languages, default first.
     *
     * @return array<int, array{slug: string, name: string, locale: string, is_default: bool}>
     */
    public function languages(): array;

    /** Slug of the default language, '' when unavailable. */
    public function default_language(): string;

    /** Language slug of a post, '' when it has none assigned. */
    public function language_of(int $post_id): string;

    /**
     * The post's translation group.
     *
     * @return array<string, int> language slug => post id, including itself
     */
    public function translations(int $post_id): array;

    /** Whether this post type participates in translation at all. */
    public function is_translated_type(string $post_type): bool;

    /**
     * Create a post in $lang, joined to $translations' group.
     *
     * @param array<string, mixed> $postarr      wp_insert_post arguments
     * @param array<string, int>   $translations existing group members
     */
    public function insert(array $postarr, string $lang, array $translations): int|\WP_Error;

    /** Assign a language to a post that has none. */
    public function set_language(int $post_id, string $lang): bool|\WP_Error;
}
