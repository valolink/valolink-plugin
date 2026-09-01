<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink\Seo;

final class SeoAdapterFactory
{
    /** Normalised field names, in the order they should be presented. */
    public const FIELDS = ['seo_title', 'seo_description', 'focus_keyword'];

    /**
     * Advisory lengths, not enforced — these are what search engines actually
     * display, and an agent should be told so it stops writing 90-character
     * titles that get cut off.
     */
    public const RECOMMENDED = ['seo_title' => 60, 'seo_description' => 155];

    /** Hard caps, enforced, generous enough never to fight a legitimate value. */
    public const MAX = ['seo_title' => 200, 'seo_description' => 500, 'focus_keyword' => 120];

    private static ?SeoAdapter $resolved = null;

    public static function detect(): SeoAdapter
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        foreach ([new RankMathAdapter(), new YoastAdapter()] as $adapter) {
            if ($adapter->is_active()) {
                return self::$resolved = $adapter;
            }
        }

        // Recognised but unwritable: AIOSEO stores in wp_aioseo_posts.
        if (defined('AIOSEO_VERSION')) {
            return self::$resolved = new UnsupportedSeoAdapter('all_in_one_seo');
        }
        if (defined('SEOPRESS_VERSION')) {
            return self::$resolved = new UnsupportedSeoAdapter('seopress');
        }

        return self::$resolved = new UnsupportedSeoAdapter();
    }
}
