<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Logging;

use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Modules\EngineLink\EngineLinkModule;
use Valolink\Plugin\Modules\EngineLink\EnginelinkAuth;
use Valolink\Plugin\Settings;

final class LoggingModule implements Module
{
    public const MODULE_ID   = 'logging';
    public const ROTATE_HOOK = 'valolink_log_rotate';
    public const REST_NS     = EngineLinkModule::REST_NAMESPACE;

    private const DEFAULT_RETENTION_DAYS       = 90;
    private const FAILED_LOGIN_RETENTION_DAYS   = 14;   // failed logins are high-volume noise — age out sooner
    private const ROTATE_BATCH                  = 1000;

    public function __construct(private readonly Settings $settings) {}

    public function id(): string
    {
        return self::MODULE_ID;
    }

    public function should_load(Context $ctx): bool
    {
        // Load broadly — capture events from admin, REST, cron, CLI, and login flows.
        // Frontend page-view requests don't need it unless we're triggering events there.
        return true;
    }

    public function register(): void
    {
        LogTable::maybe_install();

        (new Hooks())->register();

        // REST endpoint for EngineLink
        add_action('rest_api_init', [$this, 'register_routes']);

        // wp-admin viewer (the hook only fires in admin context, so cheap elsewhere)
        (new LogViewerPage($this->settings))->register();

        // Daily rotation cron
        add_action(self::ROTATE_HOOK, [$this, 'rotate']);
        if (!wp_next_scheduled(self::ROTATE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::ROTATE_HOOK);
        }
    }

    public function register_routes(): void
    {
        $auth = new EnginelinkAuth($this->settings);

        register_rest_route(self::REST_NS, '/logs', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_logs'],
            'permission_callback' => [$auth, 'check'],
            'args'                => [
                'event'      => ['type' => 'string'],
                'user_login' => ['type' => 'string'],
                'level'      => ['type' => 'string'],
                'before'     => ['type' => 'integer'], // cursor: id < before
                'limit'      => ['type' => 'integer'],
            ],
        ]);

        register_rest_route(self::REST_NS, '/log-events', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_event_facets'],
            'permission_callback' => [$auth, 'check'],
        ]);
    }

    public function get_logs(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;
        $table = LogTable::table_name();

        $where = ['1=1'];
        $args  = [];

        if ($event = $request->get_param('event')) {
            $where[] = 'event = %s';
            $args[]  = sanitize_text_field((string) $event);
        }
        if ($user = $request->get_param('user_login')) {
            $where[] = 'user_login = %s';
            $args[]  = sanitize_user((string) $user);
        }
        if ($level = $request->get_param('level')) {
            $where[] = 'level = %s';
            $args[]  = sanitize_text_field((string) $level);
        }
        if ($before = (int) $request->get_param('before')) {
            $where[] = 'id < %d';
            $args[]  = $before;
        }

        $limit   = max(1, min(500, (int) ($request->get_param('limit') ?: 100)));
        $args[]  = $limit;

        $sql = "SELECT id, created_at, event, level, user_id, user_login, ip, message, context
                FROM $table
                WHERE " . implode(' AND ', $where) . "
                ORDER BY id DESC
                LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        $logs = array_map(static function (array $row): array {
            $row['id']      = (int) $row['id'];
            $row['user_id'] = $row['user_id'] !== null ? (int) $row['user_id'] : null;
            $row['context'] = $row['context'] ? json_decode((string) $row['context'], true) : null;
            return $row;
        }, $rows);

        $next_cursor = (count($logs) === $limit) ? end($logs)['id'] : null;

        return new \WP_REST_Response([
            'logs'        => $logs,
            'next_cursor' => $next_cursor,
        ], 200);
    }

    /** Returns distinct event names + counts so EngineLink can build a filter. */
    public function get_event_facets(): \WP_REST_Response
    {
        global $wpdb;
        $table = LogTable::table_name();

        $rows = $wpdb->get_results(
            "SELECT event, COUNT(*) AS count
             FROM $table
             GROUP BY event
             ORDER BY count DESC
             LIMIT 100",
            ARRAY_A,
        );

        return new \WP_REST_Response(array_map(static fn(array $r) => [
            'event' => $r['event'],
            'count' => (int) $r['count'],
        ], $rows), 200);
    }

    public function rotate(): void
    {
        global $wpdb;
        $table     = LogTable::table_name();
        $retention = (int) $this->settings->get_module_setting(self::MODULE_ID, 'retention_days', self::DEFAULT_RETENTION_DAYS);
        if ($retention <= 0) {
            return;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS);

        // Batch delete to avoid lock spikes on large tables.
        $deleted_total = 0;
        for ($i = 0; $i < 50; $i++) { // cap at 50k rows/run
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE created_at < %s ORDER BY id LIMIT %d",
                $cutoff,
                self::ROTATE_BATCH,
            ));
            if (!$deleted) {
                break;
            }
            $deleted_total += (int) $deleted;
        }

        // Second pass: failed-login rows are high-volume / low-value (login
        // hammering) — age them out faster than the general audit trail.
        // Tunable via the same settings bag; only meaningful when shorter than
        // the general retention (otherwise the pass above already covered it).
        $failed_days = (int) $this->settings->get_module_setting(
            self::MODULE_ID,
            'failed_login_retention_days',
            self::FAILED_LOGIN_RETENTION_DAYS,
        );
        if ($failed_days > 0 && $failed_days < $retention) {
            $failed_cutoff = gmdate('Y-m-d H:i:s', time() - $failed_days * DAY_IN_SECONDS);
            for ($i = 0; $i < 50; $i++) {
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM $table WHERE event = %s AND created_at < %s ORDER BY id LIMIT %d",
                    'auth.login_failed',
                    $failed_cutoff,
                    self::ROTATE_BATCH,
                ));
                if (!$deleted) {
                    break;
                }
                $deleted_total += (int) $deleted;
            }
        }

        if ($deleted_total > 0) {
            error_log("[valolink-plugin] rotation: pruned {$deleted_total} log rows");
        }
    }

    public function uninstall(): void
    {
        wp_unschedule_hook(self::ROTATE_HOOK);
        LogTable::drop();
        $this->settings->forget_module(self::MODULE_ID);
    }
}
