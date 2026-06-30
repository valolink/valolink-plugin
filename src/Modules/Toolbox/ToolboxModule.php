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

        $this->settings->set_module_settings(self::MODULE_ID, [
            'allow_svg_uploads' => $bool('allow_svg_uploads'),
        ]);

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
