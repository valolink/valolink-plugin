<?php

declare(strict_types=1);

namespace Valolink\Plugin\Admin;

use Valolink\Plugin\Settings;

/**
 * Who sees the plugin's own wp-admin notices.
 *
 * The notices are for us — a pending Accesslink change, staging mode, an
 * undeclared production host — but on a client site the client's own admins
 * hold `manage_options` too, and a notice they cannot act on is noise at best
 * and alarming at worst. So the audience is an explicit list of users, chosen
 * on the Valolink settings page.
 *
 * Until a list is chosen, the audience is everyone with a @valolink.fi
 * address. A saved selection that equals that default is not stored, so the
 * default stays live: a colleague added to the site later is included without
 * anyone revisiting the setting.
 */
final class NoticeAudience
{
    public const SETTING_KEY    = 'notice_users';
    public const DEFAULT_DOMAIN = 'valolink.fi';

    public function __construct(private readonly Settings $settings) {}

    public function includes_current_user(): bool
    {
        $user = wp_get_current_user();
        return $user->exists() && $this->includes($user);
    }

    public function includes(\WP_User $user): bool
    {
        $chosen = $this->chosen();
        return $chosen === null ? self::is_default($user) : in_array($user->ID, $chosen, true);
    }

    /** @return array<int, int>|null  User ids, or null while the default applies. */
    public function chosen(): ?array
    {
        $raw = $this->settings->get(self::SETTING_KEY);
        return is_array($raw) ? array_values(array_map('intval', $raw)) : null;
    }

    /** @param array<int, int> $user_ids  The ticked users out of candidates(). */
    public function save(array $user_ids): void
    {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        sort($user_ids);

        $defaults = array_map(
            static fn (\WP_User $u): int => $u->ID,
            array_filter($this->candidates(), [self::class, 'is_default']),
        );
        sort($defaults);

        $this->settings->set(self::SETTING_KEY, $user_ids === array_values($defaults) ? null : $user_ids);
    }

    public static function is_default(\WP_User $user): bool
    {
        return str_ends_with(strtolower((string) $user->user_email), '@' . self::DEFAULT_DOMAIN);
    }

    /**
     * Users offered on the settings page: administrators, plus anyone with a
     * default-domain address or already chosen, so no one in the audience is
     * missing from the list that controls it.
     *
     * @return array<int, \WP_User>
     */
    public function candidates(): array
    {
        $users = [];
        foreach (get_users(['capability' => 'manage_options']) as $user) {
            $users[$user->ID] = $user;
        }
        foreach (get_users(['search' => '*@' . self::DEFAULT_DOMAIN, 'search_columns' => ['user_email']]) as $user) {
            $users[$user->ID] = $user;
        }
        $chosen = $this->chosen() ?? [];
        if ($chosen !== []) {
            foreach (get_users(['include' => $chosen]) as $user) {
                $users[$user->ID] = $user;
            }
        }

        uasort($users, static fn (\WP_User $a, \WP_User $b): int => strcasecmp($a->display_name, $b->display_name));
        return array_values($users);
    }
}
