<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Logging;

/**
 * Owns the `{prefix}valolink_log` table — lazy install via schema version,
 * clean drop on uninstall.
 */
final class LogTable
{
    public const SCHEMA_VERSION  = 1;
    public const VERSION_OPTION  = 'valolink_log_schema_version';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'valolink_log';
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

        // dbDelta is picky — must be a single CREATE TABLE, two spaces after PRIMARY KEY, etc.
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            event varchar(64) NOT NULL,
            level varchar(16) NOT NULL DEFAULT 'info',
            user_id bigint(20) unsigned DEFAULT NULL,
            user_login varchar(60) DEFAULT NULL,
            ip varchar(45) DEFAULT NULL,
            message text DEFAULT NULL,
            context longtext DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY event (event),
            KEY user_id (user_id)
        ) $charset;";

        dbDelta($sql);
        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);
    }

    public static function drop(): void
    {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("DROP TABLE IF EXISTS $table");
        delete_option(self::VERSION_OPTION);
    }
}
