<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\EngineLink;

use Valolink\Plugin\Settings;

/**
 * Shared Bearer-token auth check for any module that exposes a
 * REST endpoint under the `enginelink/v1` namespace.
 */
final class EnginelinkAuth
{
    public function __construct(private readonly Settings $settings) {}

    public function api_key(): string
    {
        return (string) $this->settings->get_module_setting(EngineLinkModule::MODULE_ID, 'api_key', '');
    }

    public function check(\WP_REST_Request $request): bool|\WP_Error
    {
        $expected = $this->api_key();
        if ($expected === '') {
            return new \WP_Error('no_key', 'API key not configured.', ['status' => 503]);
        }

        $header = $request->get_header('authorization');
        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return new \WP_Error('unauthorized', 'Missing or invalid Authorization header.', ['status' => 401]);
        }

        $provided = substr($header, 7);
        if (!hash_equals($expected, $provided)) {
            return new \WP_Error('unauthorized', 'Invalid API key.', ['status' => 401]);
        }

        return true;
    }
}
