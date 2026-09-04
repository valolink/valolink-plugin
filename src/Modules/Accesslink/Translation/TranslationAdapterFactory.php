<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink\Translation;

/**
 * Picks the translation adapter for this site.
 *
 * Detection is by function, not by plugin file: a site can have Polylang
 * installed but not active, and what matters is whether the API answers.
 */
final class TranslationAdapterFactory
{
    private static ?TranslationAdapter $resolved = null;

    public static function detect(): TranslationAdapter
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $polylang = new PolylangAdapter();
        if ($polylang->available()) {
            return self::$resolved = $polylang;
        }

        // WPML keeps translation state behind its own tables and an action-based
        // write API that behaves differently enough to need its own adapter.
        // Naming it is more useful than a bare "unavailable".
        if (defined('ICL_SITEPRESS_VERSION') || function_exists('icl_object_id')) {
            return self::$resolved = new UnsupportedTranslationAdapter('wpml');
        }

        // Polylang present but too old for pll_insert_post (< 3.7).
        if (function_exists('pll_languages_list')) {
            return self::$resolved = new UnsupportedTranslationAdapter('polylang-too-old');
        }

        return self::$resolved = new UnsupportedTranslationAdapter('none');
    }

    /** Test seam — the detection cache is per-request otherwise. */
    public static function reset(): void
    {
        self::$resolved = null;
    }
}
