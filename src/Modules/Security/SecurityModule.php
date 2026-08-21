<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Security;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

/**
 * Hardening toggles: small, well-understood things every site should arguably do.
 * Each feature is independently switchable so a site can opt in piecemeal.
 */
final class SecurityModule implements Module
{
    public const MODULE_ID    = 'security';
    public const SUBPAGE_SLUG = 'valolink-security';
    public const NONCE_ACTION = 'valolink_save_security';
    public const SAVE_ACTION  = 'valolink_save_security';

    /** All toggle keys (drives both rendering and save). */
    private const TOGGLES = [
        'disable_xmlrpc',
        'block_author_enum',
        'block_rest_user_listing',
        'hide_wp_version',
        'remove_wlw_rsd',
        'generic_login_errors',
        'login_timing_guard',
        'disable_file_editing',
        'header_hsts',
        'header_xframe',
        'header_nosniff',
        'header_referrer_policy',
        'header_permissions_policy',
    ];

    /** Sub-keys that need a `send_security_headers` hook. */
    private const HEADER_TOGGLES = [
        'header_hsts',
        'header_xframe',
        'header_nosniff',
        'header_referrer_policy',
        'header_permissions_policy',
    ];

    // --- Login timing guard ---
    private const LOGIN_TOKEN_FIELD   = 'vl_lt';
    private const LOGIN_MIN_SECONDS   = 1;      // human floor: page load → submit. Lower if autofill users get blocked.
    private const LOGIN_TOKEN_MAX_AGE = 43200;  // 12h — rejects stale/cached forms and bounds the single-use store TTL.

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        // Settings page must be reachable; individual features gate themselves below.
        return true;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);

        if ($this->is_enabled('disable_xmlrpc')) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods',  '__return_empty_array');
            // Belt-and-braces: kill any /xmlrpc.php request before it does work.
            add_action('init', [$this, 'block_xmlrpc_request'], 0);
        }

        if ($this->is_enabled('block_author_enum')) {
            add_action('template_redirect', [$this, 'block_author_enum'], 1);
        }

        if ($this->is_enabled('block_rest_user_listing')) {
            add_filter('rest_endpoints', [$this, 'restrict_rest_user_endpoints']);
        }

        if ($this->is_enabled('hide_wp_version')) {
            remove_action('wp_head',             'wp_generator');
            add_filter('the_generator',          '__return_empty_string');
            add_filter('style_loader_src',       [$this, 'strip_version_query'], 9999);
            add_filter('script_loader_src',      [$this, 'strip_version_query'], 9999);
        }

        if ($this->is_enabled('remove_wlw_rsd')) {
            remove_action('wp_head', 'wlwmanifest_link');
            remove_action('wp_head', 'rsd_link');
        }

        if ($this->is_enabled('generic_login_errors')) {
            add_filter('login_errors', [$this, 'generic_login_error']);
        }

        if ($this->is_enabled('login_timing_guard')) {
            // Invisible wp-login.php bot filter. These are ordinary plugin
            // hooks — NOT a mu-plugin: wp-login.php loads all plugins before
            // firing login_init, so this runs early enough without the
            // mu-plugin bootstrap-ordering hazards that bit the staging module.
            add_action('login_form', [$this, 'render_login_token']);
            add_action('login_init', [$this, 'enforce_login_timing'], 0);
        }

        if ($this->is_enabled('disable_file_editing')) {
            add_filter('map_meta_cap', [$this, 'deny_file_editing'], 10, 2);
        }

        if ($this->any_header_enabled()) {
            // init priority 0 runs before output for frontend, admin, REST, and AJAX requests alike.
            add_action('init', [$this, 'send_security_headers'], 0);
        }
    }

    public function uninstall(): void
    {
        $this->settings->forget_module(self::MODULE_ID);
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function add_settings_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Security', 'valolink-plugin'),
            __('Security', 'valolink-plugin'),
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
        $updated = isset($_GET['updated']) && $_GET['updated'] === '1';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Security', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e('Security settings saved.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <h2><?php esc_html_e('Block legacy endpoints', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row(
                        'disable_xmlrpc',
                        __('Disable XML-RPC', 'valolink-plugin'),
                        __('Blocks /xmlrpc.php and the legacy XML-RPC API. Almost no modern site needs it; common brute-force target.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'remove_wlw_rsd',
                        __('Remove WLW + RSD link tags', 'valolink-plugin'),
                        __('Removes the Windows Live Writer manifest and Really Simple Discovery <link> tags from <head>. Both are unused dead protocols.', 'valolink-plugin'),
                    ); ?>
                </tbody></table>

                <h2><?php esc_html_e('Reduce disclosure', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row(
                        'hide_wp_version',
                        __('Hide WordPress version', 'valolink-plugin'),
                        __('Removes the generator meta tag, RSS feed generator, and the ?ver=… query string from front-end script/style URLs.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'block_author_enum',
                        __('Block ?author=N user enumeration', 'valolink-plugin'),
                        __('Redirects frontend requests with an ?author=<id> query so usernames are not exposed via the URL.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'block_rest_user_listing',
                        __('Block REST API user listing for guests', 'valolink-plugin'),
                        __('Removes /wp/v2/users endpoints for unauthenticated requests. Logged-in users still see them (block editor needs this).', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'generic_login_errors',
                        __('Use a generic login error message', 'valolink-plugin'),
                        __('Replaces "Unknown username" and "Incorrect password" with a single non-specific message to avoid leaking which usernames exist.', 'valolink-plugin'),
                    ); ?>
                </tbody></table>

                <h2><?php esc_html_e('Login protection', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row(
                        'login_timing_guard',
                        __('Invisible login bot filter', 'valolink-plugin'),
                        __('Plants a signed, single-use token in the login form and rejects credential submissions with no token, a forged token, or an implausibly fast submit — the fingerprint of bots that POST straight to wp-login.php. Invisible to real users; blocked attempts are dropped before password checking, so they never reach the database or the event log. Enable per site.', 'valolink-plugin'),
                    ); ?>
                </tbody></table>

                <h2><?php esc_html_e('Restrict admin powers', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row(
                        'disable_file_editing',
                        __('Disable theme/plugin file editing', 'valolink-plugin'),
                        __('Hides Appearance → Theme File Editor and Plugins → Plugin File Editor. Prevents code execution via a compromised admin account.', 'valolink-plugin'),
                    ); ?>
                </tbody></table>

                <h2><?php esc_html_e('HTTP security headers', 'valolink-plugin'); ?></h2>
                <p class="description" style="max-width:780px;">
                    <?php esc_html_e('Sent on every response. Defaults are conservative. If the same header is also set by your web server, the server value usually wins — duplicates are harmless.', 'valolink-plugin'); ?>
                </p>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row(
                        'header_hsts',
                        __('Strict-Transport-Security (HSTS)', 'valolink-plugin'),
                        __('Tells browsers to use HTTPS only. Sent as max-age=31536000; includeSubDomains. Only enable once HTTPS is rock-solid — it cannot be undone for a year per visitor.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'header_xframe',
                        __('X-Frame-Options', 'valolink-plugin'),
                        __('Sends SAMEORIGIN to prevent the site from being embedded in iframes by other origins (clickjacking).', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'header_nosniff',
                        __('X-Content-Type-Options: nosniff', 'valolink-plugin'),
                        __('Stops browsers from guessing (and sometimes mis-guessing) the MIME type of a response. Always safe to enable.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'header_referrer_policy',
                        __('Referrer-Policy', 'valolink-plugin'),
                        __('Sends strict-origin-when-cross-origin so the Referer header never leaks query strings to third-party sites.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'header_permissions_policy',
                        __('Permissions-Policy', 'valolink-plugin'),
                        __('Disables camera, microphone, geolocation, payment, and USB APIs by default. Re-enable per page if a feature needs them.', 'valolink-plugin'),
                    ); ?>
                </tbody></table>

                <?php submit_button(__('Save Security Settings', 'valolink-plugin')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION);

        $bool = static fn(string $name): bool => !empty($_POST[$name]);

        $new = [];
        foreach (self::TOGGLES as $key) {
            $new[$key] = $bool($key);
        }
        $this->settings->set_module_settings(self::MODULE_ID, $new);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // Feature implementations
    // -------------------------------------------------------------------------

    public function block_xmlrpc_request(): void
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($uri !== '' && str_contains($uri, '/xmlrpc.php')) {
            status_header(403);
            nocache_headers();
            exit('XML-RPC is disabled on this site.');
        }
    }

    public function block_author_enum(): void
    {
        if (is_admin() || is_user_logged_in()) {
            return;
        }
        if (!isset($_GET['author'])) {
            return;
        }
        $raw = wp_unslash((string) $_GET['author']);
        if (!preg_match('/^\d+$/', $raw)) {
            return; // Non-numeric author param (e.g. author archive by slug) — leave alone.
        }
        wp_safe_redirect(home_url('/'), 302, 'valolink-security');
        exit;
    }

    /**
     * @param  array<string, mixed> $endpoints
     * @return array<string, mixed>
     */
    public function restrict_rest_user_endpoints(array $endpoints): array
    {
        if (is_user_logged_in()) {
            return $endpoints;
        }
        foreach (array_keys($endpoints) as $route) {
            if (is_string($route) && str_starts_with($route, '/wp/v2/users')) {
                unset($endpoints[$route]);
            }
        }
        return $endpoints;
    }

    /** Strip `?ver=…` (and only that param) from asset URLs to reduce version disclosure. */
    public function strip_version_query(string $src): string
    {
        return $src !== '' ? remove_query_arg('ver', $src) : $src;
    }

    public function generic_login_error(string $error): string
    {
        // Only override messages that leak which half of the credentials was wrong.
        return __('Login failed. Check your username and password and try again.', 'valolink-plugin');
    }

    // -------------------------------------------------------------------------
    // Login timing guard
    // -------------------------------------------------------------------------
    //
    // Invisible bot filter for wp-login.php. A signed, single-use, timestamped
    // token is planted in the login form; a credential POST with no token, a
    // forged token, an implausibly fast submit, or a replayed token is rejected
    // at `login_init` — BEFORE wp_signon(), so it skips the user lookup and
    // password hashing and never fires `wp_login_failed` (no event-log row for
    // the flood). Depends on nothing but WordPress core — no coupling to the
    // Logging module; its only telemetry is a flat error_log line for fail2ban.
    //
    // GRACEFUL FAILURE: these run inside hook callbacks, OUTSIDE the Loader's
    // load-time try/catch. Every path MUST fail OPEN (let the login proceed) —
    // a guard that fataled on wp-login.php would lock everyone out. Worst case
    // on an internal error is a bot slipping through, never a locked-out admin.

    public function render_login_token(): void
    {
        try {
            printf(
                '<input type="hidden" name="%s" value="%s" autocomplete="off">',
                esc_attr(self::LOGIN_TOKEN_FIELD),
                esc_attr($this->make_login_token()),
            );
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] login guard: token render failed (open): ' . $e->getMessage());
        }
    }

    public function enforce_login_timing(): void
    {
        try {
            $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
            $reason = $this->evaluate_login((array) wp_unslash($_POST), $method);
            if ($reason !== null) {
                $this->reject_login($reason);
            }
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] login guard: check failed (open): ' . $e->getMessage());
        }
    }

    /**
     * Decide whether to block. Returns a short block reason, or null to allow.
     * Consumes the token (single-use) as a side effect on an otherwise-valid pass.
     *
     * @param array<string, mixed> $post
     */
    private function evaluate_login(array $post, string $method): ?string
    {
        if ($method !== 'POST') {
            return null;
        }
        // Only genuine credential submits carry both fields; register /
        // lost-password POSTs do not, and must pass through untouched.
        if (!isset($post['log'], $post['pwd'])) {
            return null;
        }

        $token = isset($post[self::LOGIN_TOKEN_FIELD]) ? (string) $post[self::LOGIN_TOKEN_FIELD] : '';
        $age   = $this->login_token_age($token);
        if ($age === null) {
            return 'no_token';          // missing / forged / tampered / stale
        }
        if ($age < self::LOGIN_MIN_SECONDS) {
            return 'too_fast';
        }
        if (!$this->consume_login_token($token)) {
            return 'replay';            // a scraped token reused across a flood
        }
        return null;
    }

    private function reject_login(string $reason): void
    {
        error_log(sprintf(
            '[valolink-plugin] login guard blocked (%s) from %s',
            $reason,
            $this->client_ip(),
        ));
        status_header(403);
        nocache_headers();
        // Stop here — before wp_signon(): no user lookup, no password hashing,
        // no wp_login_failed. Keep the rejection body minimal.
        exit;
    }

    private function make_login_token(): string
    {
        // timestamp + random nonce (the nonce makes the token single-use-able).
        $payload = time() . '.' . bin2hex(random_bytes(8));
        return $payload . '.' . hash_hmac('sha256', $payload, $this->login_secret());
    }

    /** Age in seconds if the token is authentic and within the window; null otherwise. */
    private function login_token_age(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$ts, $nonce, $sig] = $parts;
        if (!ctype_digit($ts) || $nonce === '' || !ctype_xdigit($nonce)) {
            return null;
        }
        $expected = hash_hmac('sha256', $ts . '.' . $nonce, $this->login_secret());
        if (!hash_equals($expected, $sig)) {
            return null;                                   // forged / tampered
        }
        $age = time() - (int) $ts;
        if ($age < 0 || $age > self::LOGIN_TOKEN_MAX_AGE) {
            return null;                                   // clock skew / stale form
        }
        return $age;
    }

    private function login_secret(): string
    {
        // Per-site server secret; rotates with the auth salt (rotation only
        // invalidates outstanding tokens — the login page re-issues on load).
        return wp_salt('auth');
    }

    /**
     * Single-use gate. True the first time a token is seen, false on reuse.
     * Backed by a transient (the Redis object cache on our stack → no DB
     * write). Fails OPEN (true) if the store is unavailable — a down cache
     * must never lock out real logins.
     */
    private function consume_login_token(string $token): bool
    {
        try {
            $key = 'vl_lg_seen_' . hash('sha256', $token);
            if (get_transient($key)) {
                return false;
            }
            set_transient($key, 1, self::LOGIN_TOKEN_MAX_AGE);
            return true;
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] login guard: token store failed (open): ' . $e->getMessage());
            return true;
        }
    }

    private function client_ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $ip = trim(explode(',', (string) wp_unslash($_SERVER[$key]))[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '?';
    }

    /**
     * Deny the file-editing meta-caps. Hides the menus and 403s direct hits.
     *
     * @param  array<int, string> $caps
     * @param  string             $cap
     * @return array<int, string>
     */
    public function deny_file_editing(array $caps, string $cap): array
    {
        if (in_array($cap, ['edit_themes', 'edit_plugins', 'edit_files'], true)) {
            return ['do_not_allow'];
        }
        return $caps;
    }

    public function send_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }

        if ($this->is_enabled('header_hsts')) {
            // 1 year + apply to subdomains. No `preload` — that requires manual submission to the preload list.
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        if ($this->is_enabled('header_xframe')) {
            header('X-Frame-Options: SAMEORIGIN');
        }
        if ($this->is_enabled('header_nosniff')) {
            header('X-Content-Type-Options: nosniff');
        }
        if ($this->is_enabled('header_referrer_policy')) {
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
        if ($this->is_enabled('header_permissions_policy')) {
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function checkbox_row(string $key, string $label, string $description): void
    {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1"
                           <?php checked($this->is_enabled($key)); ?>>
                    <?php echo esc_html($description); ?>
                </label>
            </td>
        </tr>
        <?php
    }

    private function is_enabled(string $key): bool
    {
        return (bool) $this->settings->get_module_setting(self::MODULE_ID, $key, false);
    }

    private function any_header_enabled(): bool
    {
        foreach (self::HEADER_TOGGLES as $key) {
            if ($this->is_enabled($key)) {
                return true;
            }
        }
        return false;
    }
}
