<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Owns the `{prefix}valolink_changes` table — the proposal queue. Same lazy
 * install-by-schema-version shape as the Logging module's LogTable.
 */
final class ChangeTable
{
    public const SCHEMA_VERSION = 3;
    public const VERSION_OPTION = 'valolink_changes_schema_version';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'valolink_changes';
    }

    /** Cheap when up-to-date — just an option read. */
    public static function maybe_install(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) >= self::SCHEMA_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        // Widened from varchar(20) at schema 3: `sync_translation_meta` is 21
        // characters, and the insert failed with no error surfaced anywhere —
        // the API answered with a row of nulls. Action names are identifiers an
        // agent types, so they will keep getting longer.
        //
        // dbDelta is picky — single CREATE TABLE, two spaces after PRIMARY KEY.
        //
        // base_hash is the staleness guard: a digest of the target's watched
        // fields taken when the change was proposed. If the post moved on
        // before a human got round to approving, the hashes disagree and the
        // change is parked as `stale` instead of silently clobbering whatever
        // someone else wrote in the meantime.
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            entity_type varchar(32) NOT NULL DEFAULT 'post',
            action varchar(32) NOT NULL,
            target_id bigint(20) unsigned DEFAULT NULL,
            post_type varchar(32) NOT NULL DEFAULT 'post',
            base_hash char(64) DEFAULT NULL,
            payload longtext NOT NULL,
            summary varchar(255) DEFAULT NULL,
            note text DEFAULT NULL,
            requested_by varchar(100) DEFAULT NULL,
            idempotency_key varchar(100) DEFAULT NULL,
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            review_note text DEFAULT NULL,
            error text DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY status (status),
            KEY created_at (created_at),
            KEY target_id (target_id)
        ) $charset;";

        dbDelta($sql);
        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);
    }

    /**
     * Cheap existence probe, cached per request.
     *
     * dbDelta can fail quietly — no CREATE privilege, a full disk, a filtered
     * prefix — and every endpoint would then surface raw SQL errors. Checking
     * once lets the module answer "temporarily unavailable" instead, which is
     * the difference between a degraded feature and a broken site.
     */
    public static function exists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        global $wpdb;
        $table = self::table_name();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return $exists = ($found === $table);
    }

    public static function drop(): void
    {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("DROP TABLE IF EXISTS $table");
        delete_option(self::VERSION_OPTION);
    }
}
