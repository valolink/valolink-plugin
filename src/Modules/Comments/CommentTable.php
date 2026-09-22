<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Comments;

/**
 * Front-end comments: a reviewer's note pinned to a part of a page, with the
 * anchors that let it be found again, its thread, and the change that
 * addressed it. Its own table rather than WordPress comments, which themes
 * list, count and notify about; these are work notes, not the public
 * conversation under a post.
 */
final class CommentTable
{
    public const SCHEMA_VERSION = 1;
    public const VERSION_OPTION = 'valolink_comments_schema_version';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'valolink_comments';
    }

    public static function exists(): bool
    {
        global $wpdb;
        $table = self::table_name();

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function maybe_install(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) >= self::SCHEMA_VERSION && self::exists()) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        // anchor: the JSON the front end stores to find the spot again (the
        // quote with its surroundings, the element chain, the block path).
        // quote: the anchored text, kept as a column so a list can show it and
        // an agent can search it. resolution: how the front end last found
        // it — exact, moved, near or detached.
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) unsigned DEFAULT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            url varchar(500) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'open',
            anchor longtext DEFAULT NULL,
            block_path varchar(60) DEFAULT NULL,
            block_name varchar(100) DEFAULT NULL,
            quote text DEFAULT NULL,
            resolution varchar(20) DEFAULT NULL,
            text text NOT NULL,
            author_id bigint(20) unsigned NOT NULL DEFAULT 0,
            author_name varchar(100) NOT NULL DEFAULT '',
            change_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            resolved_at datetime DEFAULT NULL,
            resolved_by varchar(100) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY post_status (post_id, status),
            KEY parent_id (parent_id),
            KEY change_id (change_id)
        ) $charset;";

        dbDelta($sql);
        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);
    }

    public static function drop(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::table_name()); // phpcs:ignore WordPress.DB.PreparedSQL
        delete_option(self::VERSION_OPTION);
    }
}
