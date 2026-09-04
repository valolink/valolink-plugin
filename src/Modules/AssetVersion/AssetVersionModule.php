<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\AssetVersion;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

/**
 * Stamps every locally-hosted script and style URL with a version derived from
 * the file's modification time.
 *
 * WHY
 * ---
 * Our nginx templates serve static assets with `expires max` — Cache-Control:
 * max-age=315360000, ten years, no revalidation. That is correct *if* the URL
 * changes when the file does. For an asset enqueued with `$ver = null`
 * WordPress emits no ?ver= at all, so the URL never changes: a plugin update
 * swaps the bytes on disk and every returning browser keeps executing its old
 * copy. A normal reload does not help — it revalidates the document but serves
 * subresources from cache while their max-age holds. Only a hard reload does.
 *
 * Measured on energiatuote.fi 2026-09-02: 62 of 68 script and style tags had no
 * ?ver= at all, including wc-blocks-middleware.js, wc-settings.js and
 * add-to-cart.min.js. WooCommerce had been updated the previous day, so
 * returning customers were running the previous day's cart JavaScript against
 * the current server. The same mechanism breaks wp-admin screens when a plugin
 * with unversioned admin assets updates.
 *
 * Worth being precise about the cause there, because the first reading was
 * wrong: WooCommerce *does* version those files (a build hash in
 * wc-blocks-middleware.asset.php, WC_VERSION for the frontend scripts). They
 * arrived unversioned because SecurityModule's `hide_wp_version` was stripping
 * ?ver= from every asset URL. That strip has since been removed — deleting a
 * version is never the right way to hide one. Replacing it, which is what this
 * module does, achieves the same disclosure goal without disabling busting.
 *
 * Genuinely versionless enqueues do exist, which is why this module still earns
 * its place. And `$ver = false` (the default) is not safe either: WordPress
 * substitutes the *WordPress* version, which does not change when a plugin
 * updates. Hence replacing existing versions by default rather than only
 * filling in blanks.
 *
 * SCOPE
 * -----
 * `script_loader_src` / `style_loader_src` cover everything printed through
 * wp_enqueue_script/wp_enqueue_style, on the frontend, in wp-admin and on
 * wp-login.php. Deliberately NOT covered, because they never pass through
 * WP_Scripts:
 *
 *   - Script Modules (wp_enqueue_script_module, WP 6.5+). Separate system with
 *     its own filters. In practice core and WooCommerce already give these
 *     content-hash versions, so they are not part of the problem.
 *   - Assets hardcoded into theme templates as raw <script>/<link> tags.
 *   - Chunks loaded at runtime by JS (webpack dynamic import), whose URLs are
 *     built in the browser and never seen by PHP.
 *
 * Those remain covered by the nginx side, which drops unversioned .css/.js to a
 * one-hour cache instead of ten years.
 */
final class AssetVersionModule implements Module
{
    public const MODULE_ID    = 'asset-version';
    public const SUBPAGE_SLUG = 'valolink-asset-version';
    public const NONCE_ACTION = 'valolink_save_asset_version';
    public const SAVE_ACTION  = 'valolink_save_asset_version';

    /**
     * Deliberately last, so this has the final say on the URL whatever else
     * filtered it first — a third-party plugin that rewrites or strips ?ver=
     * would otherwise silently undo the stamp.
     *
     * SecurityModule used to strip ?ver= here at priority 9999; that filter is
     * gone, but running last is still the correct posture.
     */
    private const FILTER_PRIORITY = 100000;

    /** @var array<string, string|null> Per-request memo: normalised URL → path. */
    private array $memo = [];

    /** @var list<array{0: string, 1: string}>|null Lazily built [url_base, dir] pairs. */
    private ?array $bases = null;

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        // Cheap either way: two filters that only run when assets are printed.
        // Must stay true for admin so the settings page is reachable and so
        // wp-admin assets get stamped too — that is half the point.
        return true;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);

        add_filter('script_loader_src', [$this, 'version_asset'], self::FILTER_PRIORITY);
        add_filter('style_loader_src',  [$this, 'version_asset'], self::FILTER_PRIORITY);
    }

    public function uninstall(): void
    {
        $this->settings->forget_module(self::MODULE_ID);
    }

    // -------------------------------------------------------------------------
    // The filter
    // -------------------------------------------------------------------------

    /**
     * GRACEFUL FAILURE: this runs for every asset on every page render, inside a
     * hook callback and therefore outside the Loader's load-time try/catch. It
     * must never throw — a fatal here would WSOD the site. Every failure path
     * returns $src untouched, which is exactly today's behaviour.
     */
    public function version_asset(string $src): string
    {
        try {
            if ($src === '') {
                return $src;
            }
            if ($this->keep_existing() && $this->has_version($src)) {
                return $src;
            }

            $path = $this->resolve_local_path($src);
            if ($path === null) {
                return $src; // external host, or not a file we serve
            }

            $mtime = @filemtime($path);
            if ($mtime === false) {
                return $src;
            }

            // Readable on purpose: "?ver=20260901114335" says at a glance when
            // the file was last written, which is useful when diagnosing a
            // stale-asset report. Any changing token would bust the cache
            // equally well.
            return add_query_arg('ver', gmdate('YmdHis', $mtime), $src);
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] asset-version: ' . $e->getMessage());
            return $src;
        }
    }

    // -------------------------------------------------------------------------
    // URL → filesystem
    // -------------------------------------------------------------------------

    private function resolve_local_path(string $src): ?string
    {
        $key = $this->normalise($src);
        if ($key === '') {
            return null;
        }
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $resolved = null;
        foreach ($this->bases() as [$base_url, $base_dir]) {
            if (!str_starts_with($key, $base_url)) {
                continue;
            }
            $relative = ltrim(substr($key, strlen($base_url)), '/');
            if ($relative === '') {
                break;
            }

            $candidate = $base_dir . DIRECTORY_SEPARATOR . $relative;
            $real      = realpath($candidate);
            $real_base = realpath($base_dir);

            // Containment check. These URLs come from enqueued assets rather
            // than user input, but this method turns a string into a filesystem
            // read — keep it honest regardless of who supplied the string.
            if ($real !== false
                && $real_base !== false
                && str_starts_with($real, $real_base . DIRECTORY_SEPARATOR)
                && is_file($real)
            ) {
                $resolved = $real;
            }
            break; // bases are most-specific-first; the first prefix match decides
        }

        return $this->memo[$key] = $resolved;
    }

    /**
     * Reduce a URL to "host/path", dropping scheme, query and fragment, so that
     * http/https/protocol-relative variants all compare equal.
     *
     * Returns '' for anything we should not touch (data: URIs, URLs on another
     * host are handled by simply not matching a base below).
     */
    private function normalise(string $url): string
    {
        $url = strtok($url, '?#');
        if ($url === false || $url === '') {
            return '';
        }
        if (str_starts_with($url, '//')) {
            return substr($url, 2);
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return (string) preg_replace('#^https?://#i', '', $url);
        }
        if (str_starts_with($url, '/')) {
            // Root-relative. Qualify with our own host so it can match a base;
            // a subdirectory install keeps its prefix via the base URL itself.
            return $this->site_host() . $url;
        }
        return ''; // data:, relative, or something we do not recognise
    }

    /** @return list<array{0: string, 1: string}> */
    private function bases(): array
    {
        if ($this->bases !== null) {
            return $this->bases;
        }

        $pairs = [];
        $add = function (string $url, string $dir) use (&$pairs): void {
            $url = $this->normalise(trailingslashit($url));
            $dir = rtrim($dir, '/\\');
            if ($url !== '' && $dir !== '') {
                $pairs[] = [$url, $dir];
            }
        };

        // WP_CONTENT_DIR can live outside ABSPATH, so map it in its own right.
        $add(content_url(),  WP_CONTENT_DIR);
        $add(includes_url(), ABSPATH . WPINC);
        $add(admin_url(),    ABSPATH . 'wp-admin');
        $add(site_url('/'),  ABSPATH);

        // Longest base URL first so /wp-content/ wins over the site root.
        usort($pairs, static fn(array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        return $this->bases = $pairs;
    }

    private function site_host(): string
    {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    private function has_version(string $src): bool
    {
        $query = wp_parse_url($src, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return false;
        }
        parse_str($query, $args);
        return isset($args['ver']) && $args['ver'] !== '';
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function add_settings_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Asset Versioning', 'valolink-plugin'),
            __('Asset Versioning', 'valolink-plugin'),
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
            <h1><?php esc_html_e('Asset Versioning', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e('Asset versioning settings saved.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <p class="description" style="max-width:780px;">
                <?php esc_html_e('Adds ?ver=<file modification time> to every locally-hosted script and style URL, in the front end and in wp-admin. Our servers cache static assets for ten years, which is only safe when the URL changes as the file does. An asset with no version — or with a version that does not change when the file does, which is what WordPress supplies by default — keeps a URL that never changes, so an update leaves returning visitors running the old file until they hard-refresh. A modification time always changes with the file, and unlike the WordPress version it discloses nothing.', 'valolink-plugin'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Leave existing versions alone', 'valolink-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="keep_existing" value="1"
                                       <?php checked($this->keep_existing()); ?>>
                                <?php esc_html_e('Only add a version where one is missing entirely.', 'valolink-plugin'); ?>
                            </label>
                            <p class="description" style="max-width:700px;">
                                <?php esc_html_e('Off by default, and off is usually right. An asset enqueued without an explicit version gets the WordPress version instead, which does not change when the plugin that owns the file updates — so "has a version" is not the same as "has a version that means anything". Turn this on only if a plugin depends on the exact ?ver= value it sets.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <h2><?php esc_html_e('What this does not cover', 'valolink-plugin'); ?></h2>
                <p class="description" style="max-width:780px;">
                    <?php esc_html_e('Assets hardcoded into theme templates, script modules, and chunks that JavaScript loads at runtime never pass through WordPress\'s enqueue system, so nothing here can version them. The nginx configuration handles those separately by giving unversioned .css/.js a one-hour cache instead of ten years.', 'valolink-plugin'); ?>
                </p>

                <?php submit_button(__('Save Asset Versioning Settings', 'valolink-plugin')); ?>
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

        $this->settings->set_module_settings(self::MODULE_ID, [
            'keep_existing' => !empty($_POST['keep_existing']),
        ]);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function keep_existing(): bool
    {
        return (bool) $this->settings->get_module_setting(self::MODULE_ID, 'keep_existing', false);
    }
}
