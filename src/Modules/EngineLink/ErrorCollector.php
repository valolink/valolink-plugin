<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\EngineLink;

/**
 * PHP warnings and fatal errors, counted per hour for EngineLink's
 * after-update watch (GET enginelink/v1/php-errors).
 *
 * Why the plugin collects them rather than EngineLink reading a log file:
 * where PHP errors go differs per site (soutuveneet.fi writes WP_DEBUG_LOG to
 * private/wp-debug.log, 20 MB and never rotated; ykiveneet.fi has no log at
 * all and its errors end in PHP-FPM's shared log), and a raw log line can
 * carry visitors' data. Here every host works the same, and only a scrubbed,
 * grouped message ever leaves the site.
 *
 * - Warnings and fatals only (E_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR,
 *   E_USER_ERROR, and fatals seen at shutdown). Notices and deprecations are
 *   the noise of every site and say nothing about an update.
 * - Chained: the handler passes every error on to the handler that was there
 *   before (or to PHP's own by returning false), so logging and other
 *   collectors keep working as they did. Respects @-suppression.
 * - Per request the groups are counted in memory; at shutdown one multi-row
 *   INSERT … ON DUPLICATE KEY UPDATE adds them to the hour's rows. Requests
 *   without errors write nothing.
 * - A group is level + scrubbed message + the file relative to ABSPATH (no
 *   line number, so the same warning after an update to the plugin it comes
 *   from is still the same group). The message loses emails, IP addresses,
 *   query strings and long numbers, and is capped at 300 characters.
 * - Rows older than 14 days go in the daily cleanup.
 *
 * Never throws: a failure here must not become the site's error.
 */
final class ErrorCollector
{
    public const SCHEMA_VERSION = 1;
    public const VERSION_OPTION = 'valolink_php_errors_schema_version';
    public const CLEANUP_HOOK   = 'valolink_php_errors_cleanup';
    public const SINCE_OPTION   = 'valolink_php_errors_since';
    public const KEEP_DAYS      = 14;

    private const MAX_GROUPS  = 25;
    private const WARNINGS    = E_WARNING | E_USER_WARNING | E_RECOVERABLE_ERROR | E_CORE_WARNING | E_COMPILE_WARNING;
    private const FATALS      = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    /** @var array<string, array{level:string, message:string, file:string, line:int, count:int}> */
    private array $groups = [];

    /** @var callable|null */
    private $previous = null;

    private bool $inside = false;

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'valolink_php_errors';
    }

    public function register(): void
    {
        $previous       = set_error_handler([$this, 'handle']);
        $this->previous = is_callable($previous) ? $previous : null;
        register_shutdown_function([$this, 'shutdown']);

        add_action(self::CLEANUP_HOOK, [self::class, 'cleanup']);
        // wp_next_scheduled reads the autoloaded cron option, so this costs
        // nothing per request; the first time through it also records since
        // when errors are collected (so "no errors" is not "just installed").
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK);
            add_option(self::SINCE_OPTION, gmdate('c'), '', false);
        }
    }

    /** set_error_handler callback; passes everything on unchanged. */
    public function handle(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        if (!$this->inside && ($errno & (self::WARNINGS | E_USER_ERROR)) && (error_reporting() & $errno)) {
            $this->inside = true;
            try {
                $this->add($errno & E_USER_ERROR ? 'fatal' : 'warning', $errstr, $errfile, $errline);
            } catch (\Throwable) {
                // never let the collector itself fail the request
            }
            $this->inside = false;
        }

        if ($this->previous) {
            return (bool) call_user_func($this->previous, $errno, $errstr, $errfile, $errline);
        }

        return false; // PHP's own handling (logging) carries on as before
    }

    public function shutdown(): void
    {
        try {
            $last = error_get_last();
            if (is_array($last) && ($last['type'] & self::FATALS)) {
                $this->add('fatal', (string) $last['message'], (string) $last['file'], (int) $last['line']);
            }
            $this->flush();
        } catch (\Throwable) {
            // nothing to do: the next request tries again
        }
    }

    private function add(string $level, string $message, string $file, int $line): void
    {
        $message = self::scrub($message);
        $file    = self::relative($file);
        $key     = md5($level . '|' . $message . '|' . $file);

        if (isset($this->groups[$key])) {
            $this->groups[$key]['count']++;
            return;
        }
        if (count($this->groups) >= self::MAX_GROUPS) {
            return;
        }
        $this->groups[$key] = ['level' => $level, 'message' => $message, 'file' => $file, 'line' => $line, 'count' => 1];
    }

    private function flush(): void
    {
        if (!$this->groups) {
            return;
        }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || empty($wpdb->dbh)) {
            return;
        }
        self::maybe_install();

        $hour   = gmdate('Y-m-d H:00:00');
        $now    = gmdate('Y-m-d H:i:s');
        $rows   = [];
        $values = [];
        foreach ($this->groups as $hash => $g) {
            $rows[] = '(%s, %s, %s, %s, %s, %d, %d, %s, %s)';
            array_push($values, $hour, $hash, $g['level'], $g['file'], $g['message'], $g['line'], $g['count'], $now, $now);
        }
        $this->groups = [];

        $table = self::table_name();
        $sql   = "INSERT INTO $table (hour, hash, level, file, message, line, count, first_seen, last_seen) VALUES "
            . implode(', ', $rows)
            . ' ON DUPLICATE KEY UPDATE count = count + VALUES(count), last_seen = VALUES(last_seen), line = VALUES(line)';

        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare($sql, $values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->suppress_errors($suppress);
    }

    /** Emails, IPs, query strings and long numbers out; one line, 300 characters. */
    public static function scrub(string $message): string
    {
        $m = preg_replace('/\s+/', ' ', $message) ?? '';
        $m = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $m) ?? $m;
        $m = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '[ip]', $m) ?? $m;
        $m = preg_replace('/\b(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}\b/i', '[ip]', $m) ?? $m;
        $m = preg_replace('/(https?:\/\/[^\s?#"\']*)\?[^\s"\']*/i', '$1?…', $m) ?? $m;
        $m = preg_replace('/\b\d{6,}\b/', 'N', $m) ?? $m;
        $root = defined('ABSPATH') ? rtrim(ABSPATH, '/') . '/' : '';
        if ($root !== '') {
            $m = str_replace($root, '', $m);
        }
        $m = trim($m);
        return function_exists('mb_substr') ? mb_substr($m, 0, 300) : substr($m, 0, 300);
    }

    private static function relative(string $file): string
    {
        $root = defined('ABSPATH') ? rtrim(ABSPATH, '/') . '/' : '';
        if ($root !== '' && str_starts_with($file, $root)) {
            $file = substr($file, strlen($root));
        }
        return substr($file, 0, 255);
    }

    /** Cheap when up to date: one option read. */
    public static function maybe_install(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) >= self::SCHEMA_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE $table (
            hour datetime NOT NULL,
            hash char(32) NOT NULL,
            level varchar(16) NOT NULL,
            file varchar(255) NOT NULL DEFAULT '',
            message varchar(500) NOT NULL DEFAULT '',
            line int(11) NOT NULL DEFAULT 0,
            count int(11) unsigned NOT NULL DEFAULT 0,
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            PRIMARY KEY  (hour,hash),
            KEY hash (hash)
        ) $charset;";
        dbDelta($sql);
        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);
    }

    public static function cleanup(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) < 1) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hour < %s", gmdate('Y-m-d H:00:00', time() - self::KEEP_DAYS * DAY_IN_SECONDS))); // phpcs:ignore
    }

    public static function drop(): void
    {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("DROP TABLE IF EXISTS $table"); // phpcs:ignore
        delete_option(self::VERSION_OPTION);
        delete_option(self::SINCE_OPTION);
        wp_unschedule_hook(self::CLEANUP_HOOK);
    }

    /**
     * The groups since a moment, for GET /php-errors: one row per group with
     * its total, first and last sighting; plus totals per hour and level.
     *
     * collecting_since: when this site started collecting (null if unknown).
     *
     * @return array{collecting_since: ?string, since: string, groups: list<array<string, mixed>>, hours: list<array<string, mixed>>}
     */
    public static function report(int $since): array
    {
        $since_sql = gmdate('Y-m-d H:00:00', $since);
        $started   = get_option(self::SINCE_OPTION, null);
        $out       = ['collecting_since' => is_string($started) ? $started : null, 'since' => gmdate('c', $since), 'groups' => [], 'hours' => []];
        if ((int) get_option(self::VERSION_OPTION, 0) < 1) {
            return $out; // nothing has been written yet: no errors since the collector started
        }
        global $wpdb;
        $table  = self::table_name();
        $groups = $wpdb->get_results($wpdb->prepare( // phpcs:ignore
            "SELECT hash, level, file, MAX(message) AS message, MAX(line) AS line, SUM(count) AS count,
                    MIN(first_seen) AS first_seen, MAX(last_seen) AS last_seen
             FROM $table WHERE hour >= %s GROUP BY hash, level, file
             ORDER BY (level = 'fatal') DESC, count DESC LIMIT 200",
            $since_sql,
        ), ARRAY_A) ?: [];
        foreach ($groups as $g) {
            $out['groups'][] = [
                'hash'       => $g['hash'],
                'level'      => $g['level'],
                'file'       => $g['file'],
                'line'       => (int) $g['line'],
                'message'    => $g['message'],
                'count'      => (int) $g['count'],
                'first_seen' => gmdate('c', strtotime($g['first_seen'] . ' UTC')),
                'last_seen'  => gmdate('c', strtotime($g['last_seen'] . ' UTC')),
            ];
        }
        $hours = $wpdb->get_results($wpdb->prepare( // phpcs:ignore
            "SELECT hour, level, SUM(count) AS count FROM $table WHERE hour >= %s GROUP BY hour, level ORDER BY hour",
            $since_sql,
        ), ARRAY_A) ?: [];
        foreach ($hours as $h) {
            $out['hours'][] = ['hour' => gmdate('c', strtotime($h['hour'] . ' UTC')), 'level' => $h['level'], 'count' => (int) $h['count']];
        }
        return $out;
    }
}
