<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\EngineLink;

/**
 * WooCommerce orders per hour, for EngineLink's after-update watch
 * (GET enginelink/v1/order-counts): does the shop keep taking orders, and
 * do they get paid, after an update? Counts only — no order, customer or
 * amount ever leaves the site.
 *
 * Per hour (UTC, by the order's creation time): created (every real order),
 * paid (processing + completed), pending (pending payment: the customer got
 * to the payment step and did not come back) and failed (failed +
 * cancelled). Reads the HPOS table when WooCommerce uses it, else the posts
 * table. At most 60 days back.
 */
final class OrderCounts
{
    private const MAX_DAYS = 60;

    /** @return array{woocommerce: bool, since: string, hours: list<array<string, int|string>>} */
    public static function report(int $since): array
    {
        $since = max($since, time() - self::MAX_DAYS * DAY_IN_SECONDS);
        $out   = ['woocommerce' => false, 'since' => gmdate('c', $since), 'hours' => []];
        if (!class_exists('WooCommerce')) {
            return $out;
        }
        $out['woocommerce'] = true;

        global $wpdb;
        $since_sql = gmdate('Y-m-d H:00:00', $since);
        $hpos      = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($hpos) {
            $table = $wpdb->prefix . 'wc_orders';
            $rows  = $wpdb->get_results($wpdb->prepare( // phpcs:ignore
                "SELECT DATE_FORMAT(date_created_gmt, '%%Y-%%m-%%d %%H:00:00') AS hour, status, COUNT(*) AS n
                 FROM $table WHERE type = 'shop_order' AND date_created_gmt >= %s
                 GROUP BY hour, status",
                $since_sql,
            ), ARRAY_A) ?: [];
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE_FORMAT(post_date_gmt, '%%Y-%%m-%%d %%H:00:00') AS hour, post_status AS status, COUNT(*) AS n
                 FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_date_gmt >= %s
                 GROUP BY hour, status",
                $since_sql,
            ), ARRAY_A) ?: [];
        }

        $hours = [];
        foreach ($rows as $r) {
            $status = preg_replace('/^wc-/', '', (string) $r['status']);
            if (in_array($status, ['checkout-draft', 'auto-draft', 'trash'], true)) {
                continue;
            }
            $h = (string) $r['hour'];
            $hours[$h] ??= ['hour' => gmdate('c', strtotime($h . ' UTC')), 'created' => 0, 'paid' => 0, 'pending' => 0, 'failed' => 0];
            $n = (int) $r['n'];
            $hours[$h]['created'] += $n;
            if (in_array($status, ['processing', 'completed'], true)) {
                $hours[$h]['paid'] += $n;
            } elseif ($status === 'pending') {
                $hours[$h]['pending'] += $n;
            } elseif (in_array($status, ['failed', 'cancelled'], true)) {
                $hours[$h]['failed'] += $n;
            }
        }
        ksort($hours);
        $out['hours'] = array_values($hours);
        return $out;
    }
}
