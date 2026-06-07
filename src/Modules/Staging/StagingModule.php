<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Staging;

use Valolink\Plugin\Context;
use Valolink\Plugin\Module;

final class StagingModule implements Module
{
    public const MODULE_ID = 'staging';

    /** WooCommerce gateway IDs that are safe to keep active on staging (no real money). */
    private const SAFE_GATEWAYS = ['bacs', 'cheque', 'cod'];

    public function should_load(Context $context): bool
    {
        return StagingDetector::is_staging();
    }

    public function register(): void
    {
        add_filter('wp_robots', [$this, 'filter_robots'], 1);
        add_action('send_headers', [$this, 'add_robots_header'], 1);
        add_filter('wp_mail', [$this, 'intercept_mail'], 1);
        add_action('admin_notices', [$this, 'render_admin_notice']);

        if (class_exists('WooCommerce')) {
            add_filter('woocommerce_available_payment_gateways', [$this, 'disable_live_gateways']);
        }
    }

    public function uninstall(): void
    {
        // No persistent data; nothing to uninstall.
    }

    /** @param array<string, bool|string> $robots */
    public function filter_robots(array $robots): array
    {
        $robots['noindex']  = true;
        $robots['nofollow'] = true;
        unset($robots['max-image-preview']);
        return $robots;
    }

    public function add_robots_header(): void
    {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow');
        }
    }

    /** @param array<string, mixed> $atts */
    public function intercept_mail(array $atts): array
    {
        $admin_email = (string) get_option('admin_email', '');
        if ($admin_email === '') {
            return $atts;
        }

        $original_to = is_array($atts['to'] ?? null)
            ? implode(', ', $atts['to'])
            : (string) ($atts['to'] ?? '');

        $atts['to']      = $admin_email;
        $atts['subject'] = '[STAGING] ' . ($atts['subject'] ?? '');

        // Strip CC/BCC so mail does not reach real users.
        $headers = $atts['headers'] ?? [];
        if (is_string($headers)) {
            $headers = array_filter(array_map('trim', explode("\n", $headers)));
        }
        $atts['headers'] = array_values(array_filter(
            (array) $headers,
            static fn(mixed $h): bool => is_string($h) && !preg_match('/^(CC|BCC)\s*:/i', $h),
        ));

        $atts['message'] = sprintf(
            "--- STAGING INTERCEPT ---\nOriginal recipient(s): %s\n\n",
            $original_to,
        ) . ($atts['message'] ?? '');

        return $atts;
    }

    public function render_admin_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $woo_active = class_exists('WooCommerce');
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php esc_html_e('Staging mode active', 'valolink-plugin'); ?></strong>
                &mdash;
                <?php esc_html_e('Search indexing is blocked and outgoing email is redirected to the site admin.', 'valolink-plugin'); ?>
                <?php if ($woo_active) : ?>
                    <?php esc_html_e('Live WooCommerce payment gateways are disabled.', 'valolink-plugin'); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /**
     * @param  array<string, \WC_Payment_Gateway> $gateways
     * @return array<string, \WC_Payment_Gateway>
     */
    public function disable_live_gateways(array $gateways): array
    {
        return array_filter(
            $gateways,
            static fn(string $id): bool => in_array($id, self::SAFE_GATEWAYS, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
