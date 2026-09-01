<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Settings;

/**
 * Bearer auth for the propose endpoints.
 *
 * Deliberately a *separate* key from the EngineLink module's. That key rides
 * along on a six-hourly inventory poll and only ever exposes read-only site
 * facts; this one can put content on a client's site. They should not be the
 * same secret, and revoking one must not break the other.
 *
 * This class only ever authorises *proposing*. Approving is gated on a WP
 * capability instead (see ChangeService) — if the agent's key could approve
 * its own proposals the human-in-the-loop would be decorative.
 */
final class AccesslinkAuth
{
    public function __construct(private readonly Settings $settings) {}

    public function api_key(): string
    {
        return (string) $this->settings->get_module_setting(AccesslinkModule::MODULE_ID, 'api_key', '');
    }

    public function writes_enabled(): bool
    {
        // Defaults to false: enabling the module must not, on its own, open a
        // site up to writes. The operator flips this deliberately, and it
        // doubles as the kill switch — turning it off stops the agent without
        // tearing down the queue or the admin screen.
        return (bool) $this->settings->get_module_setting(AccesslinkModule::MODULE_ID, 'writes_enabled', false);
    }

    public function check(\WP_REST_Request $request): bool|\WP_Error
    {
        $expected = $this->api_key();
        if ($expected === '') {
            return new \WP_Error('no_key', 'Accesslink API key not configured.', ['status' => 401]);
        }

        $header = (string) $request->get_header('authorization');
        if ($header === '' || !str_starts_with($header, 'Bearer ')) {
            return new \WP_Error('no_bearer', 'Bearer token required.', ['status' => 401]);
        }

        if (!hash_equals($expected, substr($header, 7))) {
            return new \WP_Error('invalid_key', 'Invalid API key.', ['status' => 401]);
        }

        if (!$this->writes_enabled()) {
            return new \WP_Error(
                'writes_disabled',
                'Accesslink writes are switched off for this site.',
                ['status' => 503],
            );
        }

        return true;
    }

    /**
     * Gate for reading the queue. Same key, but allowed even when writes are
     * off so an agent can still see what happened to what it already filed.
     */
    public function check_read(\WP_REST_Request $request): bool|\WP_Error
    {
        $expected = $this->api_key();
        if ($expected === '') {
            return new \WP_Error('no_key', 'Accesslink API key not configured.', ['status' => 401]);
        }

        $header = (string) $request->get_header('authorization');
        if ($header === '' || !str_starts_with($header, 'Bearer ')) {
            return new \WP_Error('no_bearer', 'Bearer token required.', ['status' => 401]);
        }

        if (!hash_equals($expected, substr($header, 7))) {
            return new \WP_Error('invalid_key', 'Invalid API key.', ['status' => 401]);
        }

        return true;
    }
}
