<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Logging;

/**
 * The one place anything ever inserts into the log. Keep this lean —
 * one INSERT, no extra queries, no expensive lookups.
 */
final class EventLogger
{
    public static function log(string $event, array $context = [], string $level = 'info'): void
    {
        global $wpdb;

        $user_id    = get_current_user_id() ?: null;
        $user_login = null;
        if ($user_id) {
            // wp_get_current_user() is cached; no extra DB hit.
            $user = wp_get_current_user();
            $user_login = $user && $user->exists() ? $user->user_login : null;
        }

        // Allow callers to override the user (e.g. on user_register/login_failed).
        if (isset($context['__user_id'])) {
            $user_id = (int) $context['__user_id'] ?: null;
            unset($context['__user_id']);
        }
        if (isset($context['__user_login'])) {
            $user_login = (string) $context['__user_login'] ?: null;
            unset($context['__user_login']);
        }

        $message = null;
        if (isset($context['__message'])) {
            $message = (string) $context['__message'];
            unset($context['__message']);
        }

        $wpdb->insert(
            LogTable::table_name(),
            [
                'created_at' => current_time('mysql', true),
                'event'      => $event,
                'level'      => $level,
                'user_id'    => $user_id,
                'user_login' => $user_login,
                'ip'         => self::client_ip(),
                'message'    => $message,
                'context'    => $context ? wp_json_encode($context) : null,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'],
        );
    }

    public static function client_ip(): ?string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $candidate = trim(explode(',', wp_unslash((string) $_SERVER[$key]))[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
        return null;
    }
}
