<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Toolbox;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

/**
 * Collection of small per-feature toggles that don't justify a module of their own
 * (allow SVG uploads, disable emoji scripts, etc.). Each feature is gated by its own
 * checkbox so a site can opt in piecemeal.
 */
final class ToolboxModule implements Module
{
    public const MODULE_ID    = 'toolbox';
    public const SUBPAGE_SLUG = 'valolink-toolbox';
    public const NONCE_ACTION = 'valolink_save_toolbox';
    public const SAVE_ACTION  = 'valolink_save_toolbox';

    /** All boolean toggle keys (drives both render and save). */
    private const TOGGLES = [
        // Media
        'allow_svg_uploads',
        'allow_json_uploads',
        'allow_webp_uploads',
        // Frontend cleanup
        'disable_emojis',
        'disable_embeds',
        'disable_jquery_migrate',
        'disable_xml_sitemap',
        // Content
        'revisions_limit_enabled',
        'disable_comments',
        // Admin
        'admin_bar_url_toggle',
        // Site
        'disable_update_emails',
        'disable_app_passwords',
    ];

    private const DEFAULT_REVISIONS_LIMIT = 10;
    private const ADMIN_BAR_COOKIE       = 'valolink_hide_bar';
    private const ADMIN_BAR_PARAM        = 'valolink_hide_bar';

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

        if ($this->is_enabled('allow_svg_uploads')) {
            add_filter('upload_mimes',                [$this, 'add_svg_mime']);
            add_filter('wp_check_filetype_and_ext',   [$this, 'permit_svg_check'], 10, 4);
            add_filter('wp_handle_upload_prefilter',  [$this, 'sanitize_uploaded_svg']);
        }
        if ($this->is_enabled('allow_json_uploads')) {
            add_filter('upload_mimes',              [$this, 'add_json_mime']);
            add_filter('wp_check_filetype_and_ext', [$this, 'permit_json_check'], 10, 4);
        }
        if ($this->is_enabled('allow_webp_uploads')) {
            add_filter('upload_mimes',              [$this, 'add_webp_mime']);
            add_filter('wp_check_filetype_and_ext', [$this, 'permit_webp_check'], 10, 4);
        }

        if ($this->is_enabled('disable_emojis')) {
            $this->disable_emojis();
        }
        if ($this->is_enabled('disable_embeds')) {
            $this->disable_embeds();
        }
        if ($this->is_enabled('disable_jquery_migrate')) {
            add_action('wp_default_scripts', [$this, 'drop_jquery_migrate']);
        }
        if ($this->is_enabled('disable_xml_sitemap')) {
            add_filter('wp_sitemaps_enabled', '__return_false');
        }

        if ($this->is_enabled('revisions_limit_enabled')) {
            add_filter('wp_revisions_to_keep', [$this, 'cap_revisions'], 10, 2);
        }
        if ($this->is_enabled('disable_comments')) {
            $this->disable_comments();
        }

        if ($this->is_enabled('admin_bar_url_toggle')) {
            add_action('init', [$this, 'process_admin_bar_param'], 1);
            add_filter('show_admin_bar', [$this, 'maybe_hide_admin_bar']);
        }

        if ($this->is_enabled('disable_update_emails')) {
            add_filter('auto_core_update_send_email',        '__return_false');
            add_filter('automatic_updates_send_debug_email', '__return_false');
            add_filter('auto_plugin_theme_update_email',     [$this, 'drop_plugin_theme_email']);
        }
        if ($this->is_enabled('disable_app_passwords')) {
            add_filter('wp_is_application_passwords_available', '__return_false');
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
            __('Toolbox', 'valolink-plugin'),
            __('Toolbox', 'valolink-plugin'),
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
            <h1><?php esc_html_e('Toolbox', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e('Toolbox settings saved.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <h2><?php esc_html_e('Media', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row(
                        'allow_svg_uploads',
                        __('Allow SVG uploads', 'valolink-plugin'),
                        __('Permits .svg files in the media library. Uploads are sanitized (script tags and event attributes are stripped) before being saved.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'allow_json_uploads',
                        __('Allow JSON uploads', 'valolink-plugin'),
                        __('Permits .json files in the media library. Useful for theme/page-builder demo imports.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'allow_webp_uploads',
                        __('Allow WebP uploads', 'valolink-plugin'),
                        __('Forces .webp uploads through on hosts whose libmagic returns a non-image MIME for them. Core supports WebP natively; this is only needed if your server rejects it.', 'valolink-plugin'),
                    ); ?>
                </tbody></table>

                <h2><?php esc_html_e('Frontend cleanup', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row(
                        'disable_emojis',
                        __('Disable WordPress emojis', 'valolink-plugin'),
                        __('Removes the emoji detection script and the emoji stylesheet from front-end and admin output. Browsers render Unicode emojis natively.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'disable_embeds',
                        __('Disable embeds', 'valolink-plugin'),
                        __('Removes the oEmbed discovery <link> tags from <head> and dequeues wp-embed.min.js. Use if you do not embed posts cross-site.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'disable_jquery_migrate',
                        __('Disable jQuery Migrate', 'valolink-plugin'),
                        __('Drops jquery-migrate from the front-end jQuery dependency chain. Saves a request and a few KB on sites that do not rely on legacy jQuery plugins.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'disable_xml_sitemap',
                        __('Disable WordPress XML sitemap', 'valolink-plugin'),
                        __('Turns off the core /wp-sitemap.xml feed. Enable if your SEO plugin (Yoast, Rank Math, etc.) is providing its own.', 'valolink-plugin'),
                    ); ?>
                </tbody></table>

                <h2><?php esc_html_e('Content', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Limit post revisions', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="revisions_limit_enabled" value="1"
                                       <?php checked($this->is_enabled('revisions_limit_enabled')); ?>>
                                <?php esc_html_e('Keep at most', 'valolink-plugin'); ?>
                            </label>
                            <input type="number" name="revisions_limit" min="0" step="1" class="small-text"
                                   value="<?php echo esc_attr((string) $this->revisions_limit()); ?>">
                            <?php esc_html_e('revisions per post.', 'valolink-plugin'); ?>
                            <p class="description">
                                <?php esc_html_e('Older revisions are pruned automatically. Use 0 to disable revisions entirely.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                    <?php $this->checkbox_row(
                        'disable_comments',
                        __('Disable comments globally', 'valolink-plugin'),
                        __('Closes comments/pings on new and existing posts, removes the Comments menu and admin-bar item, drops the discussion meta boxes, and dequeues comment-reply.js.', 'valolink-plugin'),
                    ); ?>
                </tbody></table>

                <h2><?php esc_html_e('Admin', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Admin-bar URL toggle', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="admin_bar_url_toggle" value="1"
                                       <?php checked($this->is_enabled('admin_bar_url_toggle')); ?>>
                                <?php esc_html_e('Hide the admin bar by appending ?valolink_hide_bar=1 to any URL.', 'valolink-plugin'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Sets a session cookie so subsequent page loads stay bar-less; close the browser or visit ?valolink_hide_bar=0 to reset.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <h2><?php esc_html_e('Site-level', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->checkbox_row(
                        'disable_update_emails',
                        __('Disable auto-update emails', 'valolink-plugin'),
                        __('Suppresses the "your site has updated" emails sent for core, plugin, and theme background updates, plus debug emails on failure.', 'valolink-plugin'),
                    ); ?>
                    <?php $this->checkbox_row(
                        'disable_app_passwords',
                        __('Disable application passwords', 'valolink-plugin'),
                        __('Turns off the application passwords feature site-wide. Use if no integration relies on them — fewer credentials to manage and rotate.', 'valolink-plugin'),
                    ); ?>
                </tbody></table>

                <?php submit_button(__('Save Toolbox Settings', 'valolink-plugin')); ?>
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
        $new['revisions_limit'] = max(0, (int) ($_POST['revisions_limit'] ?? self::DEFAULT_REVISIONS_LIMIT));
        $this->settings->set_module_settings(self::MODULE_ID, $new);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // SVG upload feature
    // -------------------------------------------------------------------------

    /** @param array<string, string> $mimes */
    public function add_svg_mime(array $mimes): array
    {
        $mimes['svg']  = 'image/svg+xml';
        $mimes['svgz'] = 'image/svg+xml';
        return $mimes;
    }

    /**
     * WP's filetype-vs-real-mime check rejects SVGs by default because libmagic returns
     * `text/plain` or `text/html`. Force-accept the SVG result when the extension says so.
     *
     * @param array<string, mixed> $check
     */
    public function permit_svg_check(array $check, string $file, string $filename, $mimes): array
    {
        if (preg_match('/\.svgz?$/i', $filename)) {
            $check['ext']             = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $check['type']            = 'image/svg+xml';
            $check['proper_filename'] = $filename;
        }
        return $check;
    }

    /**
     * Sanitize SVGs at upload time: parse the file, strip <script> elements, on* attributes,
     * and javascript: URLs. Anything we can't parse is rejected outright.
     *
     * @param array<string, mixed> $file  Upload entry from $_FILES.
     * @return array<string, mixed>
     */
    public function sanitize_uploaded_svg(array $file): array
    {
        $name = (string) ($file['name'] ?? '');
        if (!preg_match('/\.svg$/i', $name)) {
            return $file; // svgz (gzipped) we leave alone — sanitization would need decompression.
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            return $file;
        }

        $contents = (string) file_get_contents($tmp);
        if ($contents === '') {
            $file['error'] = __('Uploaded SVG is empty.', 'valolink-plugin');
            return $file;
        }

        $sanitized = $this->sanitize_svg_string($contents);
        if ($sanitized === null) {
            $file['error'] = __('Could not parse the SVG file. Refusing to upload.', 'valolink-plugin');
            return $file;
        }

        if ($sanitized !== $contents) {
            file_put_contents($tmp, $sanitized);
        }

        return $file;
    }

    /** Returns sanitized SVG, or null if parsing failed. */
    private function sanitize_svg_string(string $svg): ?string
    {
        // DOMDocument with libxml — load without external entities (XXE guard).
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput       = false;

        $opts = LIBXML_NONET;
        if (defined('LIBXML_NOENT')) {
            // Don't expand entities — keep them as-is so we don't accidentally process malicious ones.
        }

        $loaded = $doc->loadXML($svg, $opts);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }

        $this->scrub_node($doc->documentElement);

        $output = $doc->saveXML();
        return $output === false ? null : $output;
    }

    private function scrub_node(?\DOMNode $node): void
    {
        if ($node === null) {
            return;
        }

        // Walk a copy of childNodes since we may remove during iteration.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->nodeName);
                if ($tag === 'script' || $tag === 'foreignobject' || $tag === 'handler') {
                    $node->removeChild($child);
                    continue;
                }
                // Strip on* event attrs and javascript: URLs.
                $attrs_to_remove = [];
                foreach ($child->attributes as $attr) {
                    $aname = strtolower($attr->nodeName);
                    $aval  = (string) $attr->nodeValue;
                    if (str_starts_with($aname, 'on')) {
                        $attrs_to_remove[] = $attr->nodeName;
                        continue;
                    }
                    if (($aname === 'href' || $aname === 'xlink:href') && preg_match('/^\s*javascript:/i', $aval)) {
                        $attrs_to_remove[] = $attr->nodeName;
                    }
                }
                foreach ($attrs_to_remove as $aname) {
                    $child->removeAttribute($aname);
                }
                $this->scrub_node($child);
            } elseif ($child instanceof \DOMProcessingInstruction) {
                // Drop processing instructions (e.g. xml-stylesheet).
                $node->removeChild($child);
            }
        }
    }

    // -------------------------------------------------------------------------
    // JSON upload feature
    // -------------------------------------------------------------------------

    /** @param array<string, string> $mimes */
    public function add_json_mime(array $mimes): array
    {
        $mimes['json'] = 'application/json';
        return $mimes;
    }

    /** @param array<string, mixed> $check */
    public function permit_json_check(array $check, string $file, string $filename, $mimes): array
    {
        if (preg_match('/\.json$/i', $filename)) {
            $check['ext']             = 'json';
            $check['type']            = 'application/json';
            $check['proper_filename'] = $filename;
        }
        return $check;
    }

    // -------------------------------------------------------------------------
    // WebP upload feature
    // -------------------------------------------------------------------------

    /** @param array<string, string> $mimes */
    public function add_webp_mime(array $mimes): array
    {
        $mimes['webp'] = 'image/webp';
        return $mimes;
    }

    /** @param array<string, mixed> $check */
    public function permit_webp_check(array $check, string $file, string $filename, $mimes): array
    {
        if (preg_match('/\.webp$/i', $filename)) {
            $check['ext']             = 'webp';
            $check['type']            = 'image/webp';
            $check['proper_filename'] = $filename;
        }
        return $check;
    }

    // -------------------------------------------------------------------------
    // Frontend cleanup
    // -------------------------------------------------------------------------

    private function disable_emojis(): void
    {
        remove_action('wp_head',             'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles',     'print_emoji_styles');
        remove_action('admin_print_styles',  'print_emoji_styles');
        remove_filter('the_content_feed',    'wp_staticize_emoji');
        remove_filter('comment_text_rss',    'wp_staticize_emoji');
        remove_filter('wp_mail',             'wp_staticize_emoji_for_email');
        add_filter('emoji_svg_url',          '__return_false');
        add_filter('tiny_mce_plugins', static function ($plugins) {
            return is_array($plugins) ? array_values(array_diff($plugins, ['wpemoji'])) : $plugins;
        });
    }

    private function disable_embeds(): void
    {
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
        add_filter('embed_oembed_discover', '__return_false');
        add_action('wp_footer', static function () {
            wp_dequeue_script('wp-embed');
        }, 100);
    }

    /** @param \WP_Scripts $scripts */
    public function drop_jquery_migrate($scripts): void
    {
        if (is_admin() || empty($scripts->registered['jquery'])) {
            return;
        }
        $jquery = $scripts->registered['jquery'];
        if (!empty($jquery->deps)) {
            $jquery->deps = array_values(array_diff($jquery->deps, ['jquery-migrate']));
        }
    }

    // -------------------------------------------------------------------------
    // Content
    // -------------------------------------------------------------------------

    public function cap_revisions(int $num, \WP_Post $post): int
    {
        return $this->revisions_limit();
    }

    private function disable_comments(): void
    {
        // Force closed status on new + existing posts.
        add_filter('option_default_comment_status', static fn() => 'closed');
        add_filter('option_default_ping_status',    static fn() => 'closed');
        add_filter('comments_open', '__return_false', 20);
        add_filter('pings_open',    '__return_false', 20);

        // Remove the Comments admin menu.
        add_action('admin_menu', static function () {
            remove_menu_page('edit-comments.php');
        });

        // Drop the discussion meta boxes from every post-type edit screen.
        add_action('admin_init', static function () {
            foreach (get_post_types() as $pt) {
                remove_meta_box('commentstatusdiv', $pt, 'normal');
                remove_meta_box('commentsdiv',      $pt, 'normal');
                remove_meta_box('trackbacksdiv',    $pt, 'normal');
            }
        });

        // Remove the admin-bar "Comments" bubble.
        add_action('wp_before_admin_bar_render', static function () {
            global $wp_admin_bar;
            if (isset($wp_admin_bar) && method_exists($wp_admin_bar, 'remove_node')) {
                $wp_admin_bar->remove_node('comments');
            }
        });

        // Dequeue the front-end comment-reply script.
        add_action('wp_enqueue_scripts', static function () {
            wp_dequeue_script('comment-reply');
        }, 100);
    }

    private function revisions_limit(): int
    {
        $value = $this->settings->get_module_setting(self::MODULE_ID, 'revisions_limit', self::DEFAULT_REVISIONS_LIMIT);
        return max(0, (int) $value);
    }

    // -------------------------------------------------------------------------
    // Admin-bar URL toggle
    // -------------------------------------------------------------------------

    public function process_admin_bar_param(): void
    {
        if (!isset($_GET[self::ADMIN_BAR_PARAM])) {
            return;
        }
        $value = (string) wp_unslash($_GET[self::ADMIN_BAR_PARAM]);

        if ($value === '1') {
            // Session cookie (no `expires`) — cleared when the browser closes.
            setcookie(self::ADMIN_BAR_COOKIE, '1', [
                'expires'  => 0,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE[self::ADMIN_BAR_COOKIE] = '1';
        } else {
            setcookie(self::ADMIN_BAR_COOKIE, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE[self::ADMIN_BAR_COOKIE]);
        }

        // Strip the param from the address bar so the URL stays clean.
        wp_safe_redirect(remove_query_arg(self::ADMIN_BAR_PARAM));
        exit;
    }

    public function maybe_hide_admin_bar(bool $show): bool
    {
        return !empty($_COOKIE[self::ADMIN_BAR_COOKIE]) ? false : $show;
    }

    // -------------------------------------------------------------------------
    // Site-level
    // -------------------------------------------------------------------------

    /**
     * Best-effort suppression of plugin/theme auto-update emails. WP <5.7 has no boolean
     * gate for these, so we drop the recipient list — wp_mail then silently no-ops.
     *
     * @param  array<string, mixed> $email
     * @return array<string, mixed>
     */
    public function drop_plugin_theme_email(array $email): array
    {
        $email['to'] = [];
        return $email;
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
}
