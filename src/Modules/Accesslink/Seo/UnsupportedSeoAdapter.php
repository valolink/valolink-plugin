<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink\Seo;

/**
 * Stand-in for "no SEO plugin we can drive".
 *
 * Covers both the no-plugin case and plugins we recognise but cannot write
 * safely — All in One SEO keeps its data in a custom table rather than
 * postmeta, so writing meta keys would silently do nothing. Reporting the
 * fields as unavailable is honest; pretending to store them is not.
 */
final class UnsupportedSeoAdapter implements SeoAdapter
{
    public function __construct(private readonly string $detected = 'none') {}

    public function id(): string
    {
        return $this->detected;
    }

    public function label(): string
    {
        return $this->detected === 'none'
            ? 'No SEO plugin detected'
            : sprintf('%s (not writable through Accesslink)', $this->detected);
    }

    public function is_active(): bool
    {
        return true;
    }

    public function can_write(): bool
    {
        return false;
    }

    public function read(int $post_id): array
    {
        return [];
    }

    public function write(int $post_id, array $fields): void
    {
        // Intentionally empty — can_write() is false, callers must check.
    }
}
