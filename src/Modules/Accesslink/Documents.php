<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Which reader and which checks a post body gets.
 *
 * Decided from the content, not from the site: an Avada site still has plain
 * posts, a site that left Avada still has Fusion pages, and a post converted
 * to blocks is blocks whatever theme is active.
 */
final class Documents
{
    public const FORMAT_BLOCKS  = 'blocks';
    public const FORMAT_FUSION  = 'fusion';
    public const FORMAT_CLASSIC = 'classic';

    public static function format(string $content): string
    {
        if (has_blocks($content)) {
            return self::FORMAT_BLOCKS;
        }

        return FusionReader::is_fusion($content) ? self::FORMAT_FUSION : self::FORMAT_CLASSIC;
    }

    public static function reader(string $content): DocumentReader
    {
        return self::format($content) === self::FORMAT_FUSION ? new FusionReader() : new BlockReader();
    }

    /**
     * Problems a change introduces, by the checks that fit the result. A body
     * with neither blocks nor Fusion shortcodes has nothing structural to
     * check.
     *
     * @return array<int, string>
     */
    public static function check_diff(string $before, string $after): array
    {
        return match (self::format($after)) {
            self::FORMAT_FUSION => (new FusionValidator())->check_diff($before, $after),
            self::FORMAT_BLOCKS => (new BlockValidator())->check_diff($before, $after),
            default             => [],
        };
    }

    /** @return array<int, string> */
    public static function check(string $content): array
    {
        return match (self::format($content)) {
            self::FORMAT_FUSION => (new FusionValidator())->check($content),
            self::FORMAT_BLOCKS => (new BlockValidator())->check($content),
            default             => [],
        };
    }
}
