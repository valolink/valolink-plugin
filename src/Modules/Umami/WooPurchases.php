<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Umami;

use Valolink\Plugin\Modules\Staging\StagingDetector;

/**
 * WooCommerce purchases sent to Umami from the server, once the order is paid.
 *
 * Why not from the thank-you page: an order paid in the bank's or Paytrail's
 * window is paid in a server-to-server callback, and the customer may never
 * come back to the shop. And a reload of the thank-you page would count again.
 *
 * The customer's visit is kept: Umami's session ID is a hash of the website,
 * IP address and user agent (umami src/app/api/send/route.ts), and the send
 * API takes `ip` and `userAgent` in the payload. WooCommerce stores both on
 * the order at checkout, so the purchase lands in the session the visitor came
 * in with, and attribution (referrer, UTM) works.
 *
 * Whether an order counts is decided at checkout, while the customer's own
 * request is at hand: not an editor, not staging, statistics consent given if
 * the site waits for it. That decision is stored on the order; payment then
 * sends it, through Action Scheduler with retries, at most once.
 *
 * Ported from WebDig.DEV Analytics' class-webdig-dev-analytics-woocommerce.php
 * (GPL-2.0-or-later).
 */
final class WooPurchases
{
    /** Order meta: 'track' | 'skip' | 'queued' | 'sent'. */
    public const META_STATE    = '_valolink_umami';
    public const META_LANGUAGE = '_valolink_umami_language';

    private const ACTION       = 'valolink_umami_send_purchase';
    private const GROUP        = 'valolink-umami';
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly UmamiModule $module) {}

    public function register(): void
    {
        add_action('woocommerce_checkout_order_created', [$this, 'capture']);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'capture']);
        add_action('woocommerce_payment_complete', [$this, 'queue']);
        add_action('woocommerce_order_status_changed', [$this, 'status_changed'], 10, 3);
        add_action(self::ACTION, [$this, 'send'], 10, 2);
    }

    /** @param mixed $order */
    public function capture($order): void
    {
        try {
            if (!$order instanceof \WC_Order) {
                return;
            }
            $track = $this->module->counts_visitor() && $this->has_consent();
            $order->update_meta_data(self::META_STATE, $track ? 'track' : 'skip');
            $language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_ACCEPT_LANGUAGE'])) : '';
            if (preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*/', $language, $match) === 1) {
                $order->update_meta_data(self::META_LANGUAGE, $match[0]);
            }
            $order->save_meta_data();
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] umami purchase capture: ' . $e->getMessage());
        }
    }

    /** @param mixed $order_id */
    public function status_changed($order_id, $from, $to): void
    {
        if (function_exists('wc_get_is_paid_statuses') && in_array($to, wc_get_is_paid_statuses(), true)) {
            $this->queue($order_id);
        }
    }

    /** @param mixed $order_id */
    public function queue($order_id): void
    {
        try {
            $order = wc_get_order((int) $order_id);
            if (!$order instanceof \WC_Order || $order->get_meta(self::META_STATE) !== 'track' || StagingDetector::is_staging()) {
                return;
            }
            $order->update_meta_data(self::META_STATE, 'queued');
            $order->save_meta_data();
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::ACTION, [(int) $order->get_id(), 1], self::GROUP);
            } else {
                $this->send((int) $order->get_id(), 1);
            }
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] umami purchase queue: ' . $e->getMessage());
        }
    }

    /** @param mixed $order_id @param mixed $attempt */
    public function send($order_id, $attempt = 1): void
    {
        $order = wc_get_order((int) $order_id);
        if (!$order instanceof \WC_Order || $order->get_meta(self::META_STATE) !== 'queued') {
            return;
        }
        $payload    = $this->payload($order);
        $user_agent = $payload['userAgent'] ?? 'Mozilla/5.0 (compatible; valolink-plugin)';
        $response   = wp_remote_post(UmamiModule::API_URL, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json', 'User-Agent' => $user_agent],
            'body'    => (string) wp_json_encode(['type' => 'event', 'payload' => $payload]),
        ]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            $order->update_meta_data(self::META_STATE, 'sent');
            $order->save_meta_data();
            return;
        }

        $attempt = (int) $attempt;
        $error   = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . $code . ' ' . wp_remote_retrieve_body($response);
        if ($attempt < self::MAX_ATTEMPTS && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 300 * $attempt, self::ACTION, [(int) $order->get_id(), $attempt + 1], self::GROUP);
            return;
        }
        $order->update_meta_data(self::META_STATE, 'track');
        $order->save_meta_data();
        error_log(sprintf('[valolink-plugin] umami: purchase of order %d not sent: %s', $order->get_id(), mb_substr($error, 0, 300)));
    }

    /** @return array<string, mixed> */
    public function payload(\WC_Order $order): array
    {
        $items = 0;
        foreach ($order->get_items() as $item) {
            $items += $item instanceof \WC_Order_Item_Product ? (int) $item->get_quantity() : 0;
        }
        $path = (string) wp_parse_url(wc_get_endpoint_url('order-received', '', wc_get_checkout_url()), PHP_URL_PATH);

        $payload = [
            'website'  => $this->module->website_id(),
            'hostname' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            'url'      => $path !== '' ? $path : '/',
            'name'     => 'purchase',
            'data'     => [
                'revenue'  => round((float) $order->get_total(), 2),
                'currency' => $order->get_currency(),
                'items'    => $items,
            ],
        ];
        $language = (string) $order->get_meta(self::META_LANGUAGE);
        if ($language !== '') {
            $payload['language'] = $language;
        }
        $ip = (string) $order->get_customer_ip_address();
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $payload['ip'] = $ip;
        }
        $user_agent = (string) $order->get_customer_user_agent();
        if ($user_agent !== '') {
            $payload['userAgent'] = $user_agent;
        }

        return $payload;
    }

    /** Statistics consent, when the site waits for it: the WP Consent API, or the cookie the event script mirrors consent into. */
    private function has_consent(): bool
    {
        if ($this->module->consent_mode() !== UmamiModule::CONSENT_AUTO) {
            return true;
        }
        if (function_exists('wp_has_consent') && wp_has_consent('statistics')) {
            return true;
        }

        return ($_COOKIE[UmamiModule::CONSENT_COOKIE] ?? '') === '1';
    }

    /** Uninstall: the state this module left on orders, in both order storages. */
    public static function delete_order_meta(): void
    {
        global $wpdb;
        foreach ([$wpdb->postmeta, $wpdb->prefix . 'wc_orders_meta'] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                continue;
            }
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE meta_key IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::META_STATE,
                self::META_LANGUAGE,
            ));
        }
    }
}
