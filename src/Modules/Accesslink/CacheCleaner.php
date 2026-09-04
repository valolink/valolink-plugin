<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Purges page caches after a change that is not a post save.
 *
 * Post edits look after themselves: caching plugins hook `save_post` and clear
 * exactly the pages affected, which was confirmed by probe — an approved
 * `update_text` was visible to an anonymous visitor on a warmed cache with no
 * purge at all.
 *
 * Menus are the exception, and the reason this exists. A menu is not a post, so
 * nothing fires, and the change is real but invisible until something else
 * happens to clear the cache — the worst kind of bug to chase, because the
 * database is right and the page is wrong.
 *
 * Every call is guarded: this runs on client sites with whatever caching stack
 * they happen to have, and a missing plugin must be a no-op rather than a fatal.
 */
final class CacheCleaner
{
    /**
     * Clear whole-site page caches.
     *
     * Whole-site rather than per-URL on purpose: the changes that need this
     * appear on every page, so there is no smaller correct answer.
     */
    public static function purge_site(): void
    {
        // WP Rocket — the agency default.
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // LiteSpeed Cache.
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        }

        // W3 Total Cache.
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        // WP Super Cache.
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // Cache Enabler, and anything else following the convention.
        do_action('valolink_accesslink_purged_cache');
    }
}
