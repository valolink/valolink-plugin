<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Logging;

/**
 * Maps WP actions → EventLogger calls. Filter hot-path events early so
 * we never insert spam (revisions, autosaves, no-op transitions).
 */
final class Hooks
{
    /** One failed-login row per IP per this window — throttle against hammering. */
    private const LOGIN_FAIL_THROTTLE_SECONDS = 300;

    public function register(): void
    {
        // Auth
        add_action('wp_login',          [$this, 'on_login'], 10, 2);
        add_action('wp_login_failed',   [$this, 'on_login_failed'], 10, 1);
        add_action('wp_logout',         [$this, 'on_logout'], 10, 1);

        // Plugins
        add_action('activated_plugin',   [$this, 'on_plugin_activated'], 10, 2);
        add_action('deactivated_plugin', [$this, 'on_plugin_deactivated'], 10, 2);
        add_action('deleted_plugin',     [$this, 'on_plugin_deleted'], 10, 2);

        // Themes
        add_action('switch_theme', [$this, 'on_theme_switched'], 10, 3);

        // Upgrades — core/plugin/theme installs and updates
        add_action('upgrader_process_complete', [$this, 'on_upgrader_complete'], 10, 2);

        // Users
        add_action('user_register', [$this, 'on_user_register'], 10, 1);
        add_action('delete_user',   [$this, 'on_user_delete'], 10, 2);

        // Posts — published / unpublished only
        add_action('transition_post_status', [$this, 'on_post_transition'], 10, 3);

        // Email — covers both native wp_mail and our Resend-routed sends.
        add_action('wp_mail_succeeded', [$this, 'on_mail_sent'],   10, 1);
        add_action('wp_mail_failed',    [$this, 'on_mail_failed'], 10, 1);
    }

    public function on_login(string $user_login, \WP_User $user): void
    {
        EventLogger::log('auth.login', [
            '__user_id'    => $user->ID,
            '__user_login' => $user_login,
        ]);
    }

    public function on_login_failed(string $user_login): void
    {
        // Throttle: collapse a burst of failed logins from one IP into a single
        // row per window so login-hammering can't flood the table. The gate is
        // a transient — on our stack that's the Redis object cache, so it costs
        // no DB write. Independent of the Security module's login guard (which,
        // when enabled, stops most of this upstream before wp_login_failed even
        // fires). Fails OPEN (logs) on any error — this runs inside a hook,
        // outside the loader's try/catch, so it must never throw into the login
        // path; a duplicate row beats a fatal.
        try {
            $ip = EventLogger::client_ip();
            if ($ip !== null) {
                $key = 'vl_llf_' . md5($ip);
                if (get_transient($key)) {
                    return;
                }
                set_transient($key, 1, self::LOGIN_FAIL_THROTTLE_SECONDS);
            }
        } catch (\Throwable $e) {
            // fall through to logging
        }

        EventLogger::log('auth.login_failed', [
            '__user_login' => $user_login,
            '__message'    => "Failed login for: {$user_login}",
        ], 'warning');
    }

    public function on_logout($user_id): void
    {
        $user_login = null;
        if ($user_id && ($u = get_user_by('id', (int) $user_id))) {
            $user_login = $u->user_login;
        }
        EventLogger::log('auth.logout', [
            '__user_id'    => $user_id ? (int) $user_id : null,
            '__user_login' => $user_login,
        ]);
    }

    public function on_plugin_activated(string $plugin, bool $network_wide): void
    {
        EventLogger::log('plugin.activated', [
            'plugin'       => $plugin,
            'network_wide' => $network_wide,
            '__message'    => "Activated: {$plugin}",
        ]);
    }

    public function on_plugin_deactivated(string $plugin, bool $network_wide): void
    {
        EventLogger::log('plugin.deactivated', [
            'plugin'       => $plugin,
            'network_wide' => $network_wide,
            '__message'    => "Deactivated: {$plugin}",
        ]);
    }

    public function on_plugin_deleted(string $plugin, bool $deleted): void
    {
        if (!$deleted) {
            return;
        }
        EventLogger::log('plugin.deleted', [
            'plugin'    => $plugin,
            '__message' => "Deleted: {$plugin}",
        ], 'warning');
    }

    public function on_theme_switched(string $new_name, \WP_Theme $new_theme, \WP_Theme $old_theme): void
    {
        EventLogger::log('theme.switched', [
            'from'      => $old_theme->get_stylesheet(),
            'to'        => $new_theme->get_stylesheet(),
            '__message' => "Theme: {$old_theme->get_stylesheet()} → {$new_theme->get_stylesheet()}",
        ]);
    }

    /**
     * Fires after a successful upgrade run. $hook_extra describes what was upgraded.
     */
    public function on_upgrader_complete(\WP_Upgrader $upgrader, array $hook_extra): void
    {
        $type   = $hook_extra['type']   ?? null;   // plugin | theme | core
        $action = $hook_extra['action'] ?? null;   // install | update

        if ($type === 'core') {
            EventLogger::log('core.updated', [
                'new_version' => get_bloginfo('version'),
                '__message'   => 'WordPress core updated to ' . get_bloginfo('version'),
            ]);
            return;
        }

        $items = [];
        if ($type === 'plugin') {
            $items = $hook_extra['plugins'] ?? ($hook_extra['plugin'] ? [$hook_extra['plugin']] : []);
        } elseif ($type === 'theme') {
            $items = $hook_extra['themes'] ?? ($hook_extra['theme'] ? [$hook_extra['theme']] : []);
        }

        if (!$items) {
            return;
        }

        $event = sprintf('%s.%s', $type, $action === 'install' ? 'installed' : 'updated');
        foreach ((array) $items as $item) {
            EventLogger::log($event, [
                $type       => $item,
                '__message' => ucfirst($type) . ' ' . ($action === 'install' ? 'installed' : 'updated') . ': ' . $item,
            ]);
        }
    }

    public function on_user_register(int $user_id): void
    {
        $u = get_user_by('id', $user_id);
        EventLogger::log('user.created', [
            'target_user_id'    => $user_id,
            'target_user_login' => $u ? $u->user_login : null,
            'roles'             => $u ? $u->roles : [],
            '__message'         => $u ? "Created user: {$u->user_login}" : "Created user #{$user_id}",
        ]);
    }

    public function on_user_delete(int $user_id, ?int $reassign): void
    {
        $u = get_user_by('id', $user_id);
        EventLogger::log('user.deleted', [
            'target_user_id'    => $user_id,
            'target_user_login' => $u ? $u->user_login : null,
            'reassigned_to'     => $reassign,
            '__message'         => $u ? "Deleted user: {$u->user_login}" : "Deleted user #{$user_id}",
        ], 'warning');
    }

    public function on_mail_sent(array $mail_data): void
    {
        $to = $mail_data['to'] ?? null;
        if (is_array($to)) $to = implode(', ', $to);
        EventLogger::log('mail.sent', [
            'to'        => (string) ($to ?? ''),
            'subject'   => (string) ($mail_data['subject'] ?? ''),
            '__message' => 'Email sent: ' . (string) ($mail_data['subject'] ?? '(no subject)'),
        ]);
    }

    public function on_mail_failed(\WP_Error $error): void
    {
        $data = $error->get_error_data();
        $to   = is_array($data) ? ($data['to'] ?? null) : null;
        if (is_array($to)) $to = implode(', ', $to);
        EventLogger::log('mail.failed', [
            'to'           => (string) ($to ?? ''),
            'subject'      => is_array($data) ? (string) ($data['subject'] ?? '') : '',
            'error_code'   => $error->get_error_code(),
            'error_message'=> $error->get_error_message(),
            '__message'    => 'Email failed: ' . $error->get_error_message(),
        ], 'warning');
    }

    public function on_post_transition(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ($new_status === $old_status) {
            return;
        }
        if (wp_is_post_autosave($post->ID) || wp_is_post_revision($post->ID)) {
            return;
        }
        // Only care about visibility transitions.
        $event = null;
        if ($new_status === 'publish' && $old_status !== 'publish') {
            $event = 'post.published';
        } elseif ($old_status === 'publish' && $new_status !== 'publish') {
            $event = 'post.unpublished';
        }
        if (!$event) {
            return;
        }

        EventLogger::log($event, [
            'post_id'    => $post->ID,
            'post_type'  => $post->post_type,
            'post_title' => $post->post_title,
            'old_status' => $old_status,
            'new_status' => $new_status,
            '__message'  => sprintf('%s "%s" %s', ucfirst($post->post_type), $post->post_title, $event === 'post.published' ? 'published' : 'unpublished'),
        ]);
    }
}
