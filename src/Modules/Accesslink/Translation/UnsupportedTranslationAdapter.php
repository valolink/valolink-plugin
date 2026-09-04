<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink\Translation;

/**
 * No multilingual plugin, or one Accesslink cannot drive.
 *
 * Reports itself by name so `GET /guide` can say "WPML is installed but
 * Accesslink cannot write through it" rather than "translation unavailable",
 * which would read as a bug on a site that plainly is multilingual.
 */
final class UnsupportedTranslationAdapter implements TranslationAdapter
{
    public function __construct(private readonly string $detected = 'none') {}

    public function available(): bool
    {
        return false;
    }

    public function plugin(): string
    {
        return $this->detected;
    }

    public function languages(): array
    {
        return [];
    }

    public function default_language(): string
    {
        return '';
    }

    public function language_of(int $post_id): string
    {
        return '';
    }

    public function language_of_term(int $term_id): string
    {
        return '';
    }

    public function translations(int $post_id): array
    {
        return [];
    }

    public function is_translated_type(string $post_type): bool
    {
        return false;
    }

    public function set_language(int $post_id, string $lang): bool|\WP_Error
    {
        return new \WP_Error(
            'translation_unavailable',
            sprintf('Languages are not writable on this site (detected: %s).', $this->detected),
        );
    }

    public function unlink(int $post_id): void
    {
    }

    public function insert(array $postarr, string $lang, array $translations): int|\WP_Error
    {
        return new \WP_Error(
            'translation_unavailable',
            sprintf('Translations are not writable on this site (detected: %s).', $this->detected),
        );
    }
}
