<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Email;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

/**
 * Module: Email — routes wp_mail() through Resend's HTTPS API.
 * Sidesteps blocked SMTP ports, supports forced sender, fallback, and
 * admin alerts on failure. Per-send logging is delegated to the Logging module.
 */
final class EmailModule implements Module
{
    public const MODULE_ID     = 'email';
    public const SUBPAGE_SLUG  = 'valolink-email';
    public const NONCE_SAVE    = 'valolink_save_email';
    public const NONCE_TEST    = 'valolink_test_email';
    public const SAVE_ACTION   = 'valolink_save_email';
    public const TEST_ACTION   = 'valolink_test_email';

    private const API_URL = 'https://api.resend.com/emails';

    /** Guard so the failure-alert send doesn't re-enter our filter. */
    private static bool $is_sending_alert = false;

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        return true; // Settings page must always be reachable.
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);
        add_action('admin_post_' . self::TEST_ACTION, [$this, 'handle_test_send']);

        if ($this->is_configured()) {
            // pre_wp_mail filter (WP 5.7+): return non-null to short-circuit wp_mail().
            add_filter('pre_wp_mail', [$this, 'intercept_wp_mail'], 10, 2);
        }
    }

    public function uninstall(): void
    {
        $this->settings->forget_module(self::MODULE_ID);
    }

    // -------------------------------------------------------------------------
    // wp_mail interception
    // -------------------------------------------------------------------------

    /**
     * @param  null|bool       $short_circuit  null = don't interfere; bool = return that to wp_mail
     * @param  array<string,mixed> $atts       wp_mail args (to, subject, message, headers, attachments)
     */
    public function intercept_wp_mail(?bool $short_circuit, array $atts): ?bool
    {
        // Another filter already short-circuited — respect that.
        if ($short_circuit !== null) {
            return $short_circuit;
        }
        // Our own alert send must not loop through Resend.
        if (self::$is_sending_alert) {
            return null;
        }

        try {
            $this->send_via_resend($atts);
            // Mirror what wp_mail does on success so listeners (Logging module, etc.) see it.
            do_action('wp_mail_succeeded', $atts);
            return true;
        } catch (\Throwable $e) {
            $error = new \WP_Error('valolink_resend_failed', $e->getMessage(), $atts);
            do_action('wp_mail_failed', $error);

            if ($this->setting('alert_on_failure')) {
                $this->send_failure_alert($e->getMessage(), $atts);
            }

            if ($this->setting('fallback_on_failure')) {
                return null; // let wp_mail continue with PHPMailer
            }
            return false;
        }
    }

    /**
     * @param array<string,mixed> $atts
     * @throws \RuntimeException on failure
     */
    private function send_via_resend(array $atts): void
    {
        $api_key = (string) $this->setting('api_key');
        if ($api_key === '') {
            throw new \RuntimeException('No Resend API key configured.');
        }

        $parsed = self::parse_headers($atts['headers'] ?? []);

        // From — forced setting wins; otherwise fall back to header, otherwise wp default.
        $force_email = (string) $this->setting('force_from_email');
        $force_name  = (string) $this->setting('force_from_name');
        $from_email  = $force_email !== '' ? $force_email : ($parsed['from_email'] ?? (string) get_option('admin_email'));
        $from_name   = $force_name  !== '' ? $force_name  : ($parsed['from_name']  ?? (string) get_bloginfo('name'));
        if (!is_email($from_email)) {
            throw new \RuntimeException('Invalid From email: ' . $from_email);
        }
        $from = $from_name !== '' ? sprintf('%s <%s>', $from_name, $from_email) : $from_email;

        // To
        $to = $atts['to'] ?? [];
        if (is_string($to)) {
            $to = preg_split('/\s*,\s*/', $to) ?: [];
        }
        $to = array_values(array_filter(array_map('trim', (array) $to), 'is_email'));
        if (empty($to)) {
            throw new \RuntimeException('No valid To address.');
        }

        // Reply-To
        $reply_to = $parsed['reply_to'];
        $default_reply_to = (string) $this->setting('default_reply_to');
        if (empty($reply_to) && $default_reply_to !== '' && is_email($default_reply_to)) {
            $reply_to = [$default_reply_to];
        }

        // BCC
        $bcc = $parsed['bcc'];
        $bcc_email = (string) $this->setting('bcc_email');
        if ($bcc_email !== '' && is_email($bcc_email) && !in_array($bcc_email, $bcc, true)) {
            $bcc[] = $bcc_email;
        }

        // Body — html or text by content-type
        $message = (string) ($atts['message'] ?? '');
        $payload = [
            'from'    => $from,
            'to'      => $to,
            'subject' => (string) ($atts['subject'] ?? ''),
        ];
        if (!empty($reply_to))      $payload['reply_to'] = $reply_to;
        if (!empty($parsed['cc']))  $payload['cc']       = $parsed['cc'];
        if (!empty($bcc))           $payload['bcc']      = $bcc;

        if ($parsed['content_type'] === 'text/html') {
            $payload['html'] = $message;
        } else {
            $payload['text'] = $message;
        }

        // Attachments — base64 encode contents
        $attachments = (array) ($atts['attachments'] ?? []);
        if (!empty($attachments)) {
            $encoded = [];
            foreach ($attachments as $path) {
                if (!is_string($path) || !is_readable($path)) continue;
                $bytes = file_get_contents($path);
                if ($bytes === false) continue;
                $encoded[] = [
                    'filename' => basename($path),
                    'content'  => base64_encode($bytes),
                ];
            }
            if (!empty($encoded)) {
                $payload['attachments'] = $encoded;
            }
        }

        $response = wp_remote_post(self::API_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('HTTP error: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $body    = (string) wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            $msg     = is_array($decoded) ? ($decoded['message'] ?? $body) : $body;
            throw new \RuntimeException(sprintf('Resend API error (%d): %s', $code, $msg));
        }
    }

    private function send_failure_alert(string $error, array $original_atts): void
    {
        $to = (string) $this->setting('alert_email');
        if ($to === '') {
            $to = (string) get_option('admin_email');
        }
        if (!is_email($to)) {
            return;
        }

        $original_to = $original_atts['to'] ?? '';
        if (is_array($original_to)) $original_to = implode(', ', $original_to);

        $subject = '[Valolink] Resend send failed on ' . wp_parse_url(home_url(), PHP_URL_HOST);
        $body    = "An email failed to send via Resend.\n\n"
                 . "Error:   {$error}\n"
                 . "Subject: " . ($original_atts['subject'] ?? '(no subject)') . "\n"
                 . "To:      {$original_to}\n"
                 . "Time:    " . current_time('mysql') . "\n";

        // Bypass our own filter for this send (otherwise it loops).
        self::$is_sending_alert = true;
        try {
            wp_mail($to, $subject, $body);
        } finally {
            self::$is_sending_alert = false;
        }
    }

    // -------------------------------------------------------------------------
    // Header parsing
    // -------------------------------------------------------------------------

    /** @param string|array<int,string>|mixed $headers */
    private static function parse_headers(mixed $headers): array
    {
        $result = [
            'from_email'   => null,
            'from_name'    => null,
            'reply_to'     => [],
            'cc'           => [],
            'bcc'          => [],
            'content_type' => 'text/plain',
        ];

        if (empty($headers)) {
            return $result;
        }
        if (is_string($headers)) {
            $headers = preg_split("/\r\n|\r|\n/", $headers) ?: [];
        }
        if (!is_array($headers)) {
            return $result;
        }

        foreach ($headers as $raw) {
            $raw = is_string($raw) ? trim($raw) : '';
            if ($raw === '' || strpos($raw, ':') === false) continue;
            [$name, $value] = explode(':', $raw, 2);
            $name  = strtolower(trim($name));
            $value = trim($value);

            switch ($name) {
                case 'from':
                    if (preg_match('/^(.*)<(.+?)>\s*$/', $value, $m)) {
                        $result['from_name']  = trim(trim($m[1]), '"');
                        $result['from_email'] = trim($m[2]);
                    } else {
                        $result['from_email'] = $value;
                    }
                    break;
                case 'reply-to':
                    $result['reply_to'][] = $value;
                    break;
                case 'cc':
                    foreach (preg_split('/\s*,\s*/', $value) as $addr) {
                        $addr = trim($addr);
                        if ($addr !== '') $result['cc'][] = $addr;
                    }
                    break;
                case 'bcc':
                    foreach (preg_split('/\s*,\s*/', $value) as $addr) {
                        $addr = trim($addr);
                        if ($addr !== '') $result['bcc'][] = $addr;
                    }
                    break;
                case 'content-type':
                    $result['content_type'] = strtolower(trim(explode(';', $value)[0]));
                    break;
            }
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function add_settings_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Email', 'valolink-plugin'),
            __('Email', 'valolink-plugin'),
            'manage_options',
            self::SUBPAGE_SLUG,
            [$this, 'render_settings_page'],
        );
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $updated   = isset($_GET['updated']) && $_GET['updated'] === '1';
        $test_ok   = isset($_GET['test']) && $_GET['test'] === 'ok';
        $test_fail = isset($_GET['test']) && $_GET['test'] === 'fail';
        $test_msg  = isset($_GET['msg']) ? sanitize_text_field(wp_unslash($_GET['msg'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Email (Resend)', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'valolink-plugin'); ?></p></div>
            <?php endif; ?>
            <?php if ($test_ok) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Test email sent successfully.', 'valolink-plugin'); ?></p></div>
            <?php endif; ?>
            <?php if ($test_fail) : ?>
                <div class="notice notice-error is-dismissible"><p>
                    <strong><?php esc_html_e('Test email failed.', 'valolink-plugin'); ?></strong>
                    <?php if ($test_msg !== '') : ?> — <code><?php echo esc_html($test_msg); ?></code><?php endif; ?>
                </p></div>
            <?php endif; ?>

            <?php if (!$this->is_configured()) : ?>
                <div class="notice notice-warning inline" style="margin-top:12px;"><p>
                    <?php esc_html_e('Not active yet — set an API key and forced From email below. Until then, wp_mail() uses the default PHP mailer.', 'valolink-plugin'); ?>
                </p></div>
            <?php else : ?>
                <div class="notice notice-success inline" style="margin-top:12px;"><p>
                    <?php esc_html_e('Active — all wp_mail() calls are routed through Resend.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_SAVE); ?>

                <h2><?php esc_html_e('Resend account', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="vl-api-key"><?php esc_html_e('API key', 'valolink-plugin'); ?></label></th>
                        <td>
                            <input id="vl-api-key" type="password" name="api_key" autocomplete="off"
                                   value="<?php echo esc_attr((string) $this->setting('api_key')); ?>"
                                   class="regular-text" placeholder="re_xxxxxxxxxxxxxxxxxxxxxxxx">
                            <p class="description">
                                <?php esc_html_e('From your Resend dashboard. The key is stored as a WordPress option (non-autoloaded).', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <h2><?php esc_html_e('Sender (forced)', 'valolink-plugin'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Resend will only deliver from a verified domain. These overrides apply to every wp_mail() call regardless of what headers are set elsewhere.', 'valolink-plugin'); ?>
                </p>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="vl-from-email"><?php esc_html_e('From email', 'valolink-plugin'); ?></label></th>
                        <td>
                            <input id="vl-from-email" type="email" name="force_from_email" class="regular-text"
                                   value="<?php echo esc_attr((string) $this->setting('force_from_email')); ?>"
                                   placeholder="hello@yourdomain.fi">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vl-from-name"><?php esc_html_e('From name', 'valolink-plugin'); ?></label></th>
                        <td>
                            <input id="vl-from-name" type="text" name="force_from_name" class="regular-text"
                                   value="<?php echo esc_attr((string) $this->setting('force_from_name')); ?>"
                                   placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vl-reply-to"><?php esc_html_e('Default Reply-To', 'valolink-plugin'); ?></label></th>
                        <td>
                            <input id="vl-reply-to" type="email" name="default_reply_to" class="regular-text"
                                   value="<?php echo esc_attr((string) $this->setting('default_reply_to')); ?>"
                                   placeholder="support@yourdomain.fi">
                            <p class="description"><?php esc_html_e('Used only when the email itself does not set Reply-To.', 'valolink-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vl-bcc"><?php esc_html_e('Always BCC', 'valolink-plugin'); ?></label></th>
                        <td>
                            <input id="vl-bcc" type="email" name="bcc_email" class="regular-text"
                                   value="<?php echo esc_attr((string) $this->setting('bcc_email')); ?>"
                                   placeholder="ops@yourdomain.fi">
                            <p class="description"><?php esc_html_e('Optional. Useful for keeping an audit copy of every outgoing email.', 'valolink-plugin'); ?></p>
                        </td>
                    </tr>
                </tbody></table>

                <h2><?php esc_html_e('Error handling', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('On Resend failure', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="fallback_on_failure" value="1"
                                       <?php checked($this->setting('fallback_on_failure')); ?>>
                                <?php esc_html_e('Fall back to the default WordPress mailer', 'valolink-plugin'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('If Resend returns an error, the email is re-attempted via the host\'s PHPMailer/sendmail. May fail too if SMTP is blocked.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Alert on failure', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="alert_on_failure" value="1"
                                       <?php checked($this->setting('alert_on_failure')); ?>>
                                <?php esc_html_e('Email an admin when Resend fails', 'valolink-plugin'); ?>
                            </label>
                            <br><br>
                            <label>
                                <?php esc_html_e('Alert email', 'valolink-plugin'); ?>:
                                <input type="email" name="alert_email" class="regular-text"
                                       value="<?php echo esc_attr((string) $this->setting('alert_email')); ?>"
                                       placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                            </label>
                            <p class="description">
                                <?php esc_html_e('Leave empty to use the site admin email. The alert itself is sent via the default mailer to avoid a loop.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <?php submit_button(__('Save Email Settings', 'valolink-plugin')); ?>
            </form>

            <h2><?php esc_html_e('Send test email', 'valolink-plugin'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:flex-end;max-width:640px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::TEST_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_TEST); ?>
                <div>
                    <label for="vl-test-to" style="display:block;margin-bottom:4px;"><?php esc_html_e('Recipient', 'valolink-plugin'); ?></label>
                    <input id="vl-test-to" type="email" name="test_to" required
                           class="regular-text"
                           value="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                </div>
                <button type="submit" class="button button-primary"><?php esc_html_e('Send test', 'valolink-plugin'); ?></button>
            </form>
            <p class="description" style="max-width:640px;">
                <?php esc_html_e('Bypasses wp_mail and calls Resend directly so you see the real result without any other plugin filters in the way.', 'valolink-plugin'); ?>
            </p>
        </div>
        <?php
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_SAVE);

        $sanitise_email = static fn(string $key): string => isset($_POST[$key])
            ? sanitize_email((string) wp_unslash($_POST[$key]))
            : '';

        $this->settings->set_module_settings(self::MODULE_ID, [
            'api_key'             => isset($_POST['api_key'])     ? sanitize_text_field((string) wp_unslash($_POST['api_key']))     : '',
            'force_from_email'    => $sanitise_email('force_from_email'),
            'force_from_name'     => isset($_POST['force_from_name']) ? sanitize_text_field((string) wp_unslash($_POST['force_from_name'])) : '',
            'default_reply_to'    => $sanitise_email('default_reply_to'),
            'bcc_email'           => $sanitise_email('bcc_email'),
            'fallback_on_failure' => !empty($_POST['fallback_on_failure']),
            'alert_on_failure'    => !empty($_POST['alert_on_failure']),
            'alert_email'         => $sanitise_email('alert_email'),
        ]);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    public function handle_test_send(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_TEST);

        $to     = isset($_POST['test_to']) ? sanitize_email((string) wp_unslash($_POST['test_to'])) : '';
        $result = 'ok';
        $msg    = '';

        if (!is_email($to)) {
            $result = 'fail';
            $msg    = 'Invalid recipient';
        } else {
            try {
                $this->send_via_resend([
                    'to'      => [$to],
                    'subject' => sprintf('Valolink email test — %s', wp_parse_url(home_url(), PHP_URL_HOST)),
                    'message' => "This is a test email from the valolink-plugin email module, sent via Resend.\n\nIf you got this, configuration is working.",
                    'headers' => ['Content-Type: text/plain'],
                ]);
            } catch (\Throwable $e) {
                $result = 'fail';
                $msg    = $e->getMessage();
            }
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'test' => $result, 'msg' => rawurlencode($msg)],
            admin_url('admin.php'),
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function is_configured(): bool
    {
        return (string) $this->setting('api_key') !== ''
            && (string) $this->setting('force_from_email') !== '';
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings->get_module_setting(self::MODULE_ID, $key, null);
        return $value === null ? $default : $value;
    }
}
