<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Settings;

/**
 * Tells a human that something is waiting.
 *
 * The whole design rests on somebody reviewing the queue, and until this
 * existed the only signal was a count bubble on a screen you had to already be
 * visiting — so a change filed overnight sat unseen until someone happened to
 * look. That is not a missing feature, it is the loop failing to close.
 *
 * Deliberately built on `wp_mail()` and nothing else. If the Email module is
 * on, that routes through Resend; if it is off, WordPress falls back to PHP
 * mail; if mail is broken entirely, the admin notice still catches the
 * operator next time they are in wp-admin. No hard dependency on any of it.
 *
 * Nothing here may ever break a proposal. Every path is wrapped, and a failure
 * to notify is logged and swallowed — an agent's change is already safely
 * queued by the time we get here, and losing the write because the mail server
 * is down would be a far worse outcome than a missed email.
 */
final class ChangeNotifier
{
    /** At most one mail per this many seconds, however many changes arrive. */
    private const THROTTLE = 900;

    private const LAST_SENT_OPTION = 'valolink_accesslink_last_notified';

    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get_module_setting(
            AccesslinkModule::MODULE_ID,
            'notify_enabled',
            true,
        );
    }

    /** @return array<int, string> */
    public function recipients(): array
    {
        $raw = (string) $this->settings->get_module_setting(
            AccesslinkModule::MODULE_ID,
            'notify_emails',
            '',
        );

        $list = array_filter(array_map('trim', explode(',', $raw)));
        if ($list === []) {
            $admin = get_option('admin_email');
            $list = is_string($admin) && $admin !== '' ? [$admin] : [];
        }

        return array_values(array_filter($list, static fn (string $e): bool => is_email($e) !== false));
    }

    /**
     * Called after a change is queued. Never throws, never returns an error
     * that a caller has to handle.
     */
    public function notify_queued(array $change): void
    {
        try {
            if (!$this->enabled()) {
                return;
            }

            $last = (int) get_option(self::LAST_SENT_OPTION, 0);
            if (time() - $last < self::THROTTLE) {
                return; // An earlier mail already said "go and look".
            }

            $to = $this->recipients();
            if ($to === []) {
                return;
            }

            $pending = ChangeTable::exists()
                ? (new ChangeRepository())->count(ChangeRepository::STATUS_PENDING)
                : 1;

            $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
            $subject = $pending > 1
                ? sprintf('[%s] %d Accesslink changes waiting for review', $site, $pending)
                : sprintf('[%s] An Accesslink change is waiting for review', $site);

            $url = admin_url('admin.php?page=' . AccesslinkModule::SUBPAGE_SLUG);
            $body = implode("\n", array_filter([
                sprintf('%s proposed a change on %s.', $change['requested_by'] ?: 'An agent', $site),
                '',
                sprintf('  %s: %s', $change['action'] ?? 'change', $change['summary'] ?? ''),
                !empty($change['note']) ? sprintf('  Reason given: %s', $change['note']) : null,
                '',
                $pending > 1 ? sprintf('%d changes are pending in total.', $pending) : null,
                '',
                'Review and approve or reject:',
                $url,
                '',
                'Nothing has been applied to the site. Changes only take effect when approved.',
            ], static fn ($line): bool => $line !== null));

            $sent = wp_mail($to, $subject, $body);
            if ($sent) {
                update_option(self::LAST_SENT_OPTION, time(), false);
            } else {
                error_log('[valolink-plugin] accesslink: notification mail was not accepted for sending');
            }
        } catch (\Throwable $e) {
            // A queued change must survive a broken mailer.
            error_log('[valolink-plugin] accesslink notify failed: ' . $e->getMessage());
        }
    }

    /**
     * Backstop for when mail is unreliable: an admin notice wherever the
     * operator already is. Costs one COUNT on admin screens, and only for
     * users who could act on it.
     */
    public function render_admin_notice(): void
    {
        try {
            if (!ChangeTable::exists() || !current_user_can(ChangeService::APPROVE_CAP)) {
                return;
            }

            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && str_contains((string) $screen->id, AccesslinkModule::SUBPAGE_SLUG)) {
                return; // Already looking at the queue.
            }

            $pending = (new ChangeRepository())->count(ChangeRepository::STATUS_PENDING);
            if ($pending < 1) {
                return;
            }

            printf(
                '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
                esc_html(sprintf(
                    /* translators: %d: number of pending changes */
                    _n(
                        '%d Accesslink change is waiting for review.',
                        '%d Accesslink changes are waiting for review.',
                        $pending,
                        'valolink-plugin',
                    ),
                    $pending,
                )),
                esc_url(admin_url('admin.php?page=' . AccesslinkModule::SUBPAGE_SLUG)),
                esc_html__('Review them', 'valolink-plugin'),
            );
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] accesslink notice failed: ' . $e->getMessage());
        }
    }
}
