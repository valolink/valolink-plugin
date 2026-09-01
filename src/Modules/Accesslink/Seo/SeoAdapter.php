<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink\Seo;

/**
 * Maps Accesslink's normalised SEO fields onto whatever plugin a given site
 * happens to run. An agent works in `seo_title` / `seo_description` /
 * `focus_keyword` and never learns whether it is talking to Rank Math, Yoast,
 * or nothing at all.
 *
 * Scope is deliberately three fields. `noindex` in particular is left out: an
 * agent proposing to deindex a page is a decision with delayed, silent and
 * severe consequences, and a reviewer skimming a diff would not weigh it
 * correctly. Canonical is likewise rarely an agent's job.
 */
interface SeoAdapter
{
    /** Stable id reported in the guide, e.g. "rank_math". */
    public function id(): string;

    /** Human label for the admin screen. */
    public function label(): string;

    /** False when this plugin isn't the one running the site. */
    public function is_active(): bool;

    /**
     * Current values, keyed by normalised field name. Missing values are ''.
     *
     * @return array<string, string>
     */
    public function read(int $post_id): array;

    /** @param array<string, string> $fields subset of self::FIELDS */
    public function write(int $post_id, array $fields): void;

    /** True when this adapter can actually store the fields. */
    public function can_write(): bool;
}
