<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\MaintenancePage;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

/**
 * The customer's own logo, colours and fonts on the page WordPress shows
 * while it updates, instead of core's grey "Briefly unavailable" box.
 *
 * Nothing runs for visitors. The page is a file, written from wp-admin (see
 * DropIn) from this module's own settings; ThemeImport can pre-fill those
 * from Avada or GeneratePress, but only when asked and never as a
 * dependency. Every hook body is guarded: a failure here costs a branded
 * maintenance page, never the admin screen it ran on.
 */
final class MaintenancePageModule implements Module
{
    public const MODULE_ID      = 'maintenance_page';
    public const SUBPAGE_SLUG   = 'valolink-maintenance';
    public const SAVE_ACTION    = 'valolink_maintenance_save';
    public const IMPORT_ACTION  = 'valolink_maintenance_import';
    public const PREVIEW_ACTION = 'valolink_maintenance_preview';
    private const NONCE         = 'valolink_maintenance';
    private const REPORT_KEY    = 'valolink_maintenance_report_';

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        return $context->is_admin;
    }

    public function register(): void
    {
        add_action('admin_menu', fn () => $this->guard(fn () => $this->add_settings_page(), 'menu'));
        add_action('admin_post_' . self::SAVE_ACTION, fn () => $this->guard(fn () => $this->handle_save(), 'save'));
        add_action('admin_post_' . self::IMPORT_ACTION, fn () => $this->guard(fn () => $this->handle_import(), 'import'));
        add_action('admin_post_' . self::PREVIEW_ACTION, fn () => $this->guard(fn () => $this->handle_preview(), 'preview'));
        // Keeps the file in step with anything that changed it outside this
        // screen — a new plugin version with a new page design, a replaced
        // logo file. Cheap: a hash comparison against the file's first line.
        add_action('admin_init', fn () => $this->guard(fn () => DropIn::sync($this->all()), 'sync'));
    }

    public function uninstall(): void
    {
        $this->guard(static function (): void {
            DropIn::remove();
            GoogleFonts::forget();
        }, 'uninstall');
        $this->settings->forget_module(self::MODULE_ID);
    }

    /**
     * Switched off on the Valolink screen. The module is not loaded then, so
     * the settings page calls this directly: an off module leaves no file.
     */
    public static function on_disable(): void
    {
        try {
            DropIn::remove();
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] maintenance page: removing the drop-in failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------

    private function add_settings_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Maintenance page', 'valolink-plugin'),
            __('Maintenance page', 'valolink-plugin'),
            'manage_options',
            self::SUBPAGE_SLUG,
            fn () => $this->guard(fn () => $this->render(), 'render'),
        );
    }

    private function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_enqueue_media();

        $s        = $this->all();
        $resolved = Branding::resolve($s);
        // Synced here as well as on admin_init, so the screen reports what
        // writing the file actually did rather than inferring it.
        $status   = DropIn::sync($s);
        $report   = get_transient(self::REPORT_KEY . get_current_user_id());
        if (is_array($report)) {
            delete_transient(self::REPORT_KEY . get_current_user_id());
        }
        $defaults = Branding::default_texts((string) get_locale());
        $field    = static fn (string $key): string => esc_attr((string) ($s[$key] ?? ''));
        $preview  = wp_nonce_url(admin_url('admin-post.php?action=' . self::PREVIEW_ACTION), self::NONCE);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Maintenance page', 'valolink-plugin'); ?></h1>
            <p><?php esc_html_e('What visitors see for the minute or so WordPress takes to update itself, a plugin or a theme — in place of the default "Briefly unavailable for scheduled maintenance" box. The page is written to wp-content/maintenance.php, because during an update WordPress cannot run plugins.', 'valolink-plugin'); ?></p>

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'valolink-plugin'); ?></p></div>
            <?php endif; ?>

            <?php if (is_array($report)) : ?>
                <div class="notice notice-info is-dismissible">
                    <p><strong><?php esc_html_e('Imported from the theme. Check the result below and save if you change anything.', 'valolink-plugin'); ?></strong></p>
                    <ul style="list-style:disc;margin-left:2em;">
                        <?php foreach (array_merge((array) ($report['found'] ?? []), (array) ($report['missed'] ?? [])) as $line) : ?>
                            <li><?php echo esc_html((string) $line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($status === 'foreign') : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('wp-content/maintenance.php already exists and was not written by this plugin, so it is left alone and this page is not in use. Remove that file to use this one.', 'valolink-plugin'); ?></p></div>
            <?php elseif ($status === 'unwritable') : ?>
                <div class="notice notice-error"><p><?php esc_html_e('wp-content/maintenance.php could not be written. Check that PHP can write to wp-content.', 'valolink-plugin'); ?></p></div>
            <?php else : ?>
                <p><span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> <?php esc_html_e('In use: the next update will show this page.', 'valolink-plugin'); ?>
                    <a class="button" href="<?php echo esc_url($preview); ?>" target="_blank" rel="noopener"><?php esc_html_e('Preview', 'valolink-plugin'); ?></a></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:1em 0;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::IMPORT_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE); ?>
                <button class="button"><?php esc_html_e('Import logo, colours and fonts from the theme', 'valolink-plugin'); ?></button>
                <span class="description"><?php esc_html_e('Reads Avada or GeneratePress where present and overwrites the logo, colour and font fields with what it finds. Texts are left as they are.', 'valolink-plugin'); ?></span>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE); ?>

                <h2><?php esc_html_e('Texts', 'valolink-plugin'); ?></h2>
                <p class="description"><?php esc_html_e('Empty fields use the default for the site language, shown as the placeholder.', 'valolink-plugin'); ?></p>
                <table class="form-table" role="presentation"><tbody>
                    <?php
                    $this->text_row('site_name', __('Site name', 'valolink-plugin'), $field('site_name'), (string) $resolved['site_name'], __('Shown when there is no logo, and as the logo\'s alt text.', 'valolink-plugin'));
                    $this->text_row('heading', __('Heading', 'valolink-plugin'), $field('heading'), $defaults['heading']);
                    $this->text_row('message', __('Message', 'valolink-plugin'), $field('message'), $defaults['message']);
                    $this->text_row('secondary', __('Second-language line', 'valolink-plugin'), $field('secondary'), $defaults['secondary'], __('A smaller line underneath. Enter "-" to leave it out.', 'valolink-plugin'));
                    ?>
                </tbody></table>

                <h2><?php esc_html_e('Logo and colours', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation"><tbody>
                    <?php $this->url_row('logo_url', __('Logo', 'valolink-plugin'), $field('logo_url'), 'image', __('SVG or a transparent PNG. Files in the Media Library up to 300 KB are built into the page.', 'valolink-plugin')); ?>
                    <tr>
                        <th scope="row"><label for="vlm-logo_width"><?php esc_html_e('Logo width', 'valolink-plugin'); ?></label></th>
                        <td><input type="number" id="vlm-logo_width" name="logo_width" min="0" max="600" value="<?php echo $field('logo_width'); ?>" style="width:6em;"> px
                            <p class="description"><?php esc_html_e('0 or empty: up to 260 px.', 'valolink-plugin'); ?></p></td>
                    </tr>
                    <?php
                    $this->colour_row('background', __('Background', 'valolink-plugin'), $field('background'), Branding::DEFAULT_BACKGROUND);
                    $this->colour_row('text', __('Text', 'valolink-plugin'), $field('text'), Branding::DEFAULT_TEXT);
                    $this->colour_row('accent', __('Accent', 'valolink-plugin'), $field('accent'), Branding::DEFAULT_ACCENT);
                    ?>
                </tbody></table>

                <h2><?php esc_html_e('Fonts', 'valolink-plugin'); ?></h2>
                <p class="description"><?php esc_html_e('A family name alone is used when the visitor\'s device has it. With a font file the page always shows it: leave the file empty and save, and a Google font of that name is fetched once and stored on this site.', 'valolink-plugin'); ?></p>
                <table class="form-table" role="presentation"><tbody>
                    <?php foreach (['heading' => __('Heading font', 'valolink-plugin'), 'body' => __('Body font', 'valolink-plugin')] as $role => $label) : ?>
                        <tr>
                            <th scope="row"><label for="vlm-<?php echo esc_attr($role); ?>_font"><?php echo esc_html($label); ?></label></th>
                            <td>
                                <input type="text" id="vlm-<?php echo esc_attr($role); ?>_font" name="<?php echo esc_attr($role); ?>_font" value="<?php echo $field($role . '_font'); ?>" class="regular-text" placeholder="<?php esc_attr_e('System font', 'valolink-plugin'); ?>">
                                <label><?php esc_html_e('Weight', 'valolink-plugin'); ?>
                                    <input type="number" name="<?php echo esc_attr($role); ?>_weight" min="100" max="900" step="100" value="<?php echo $field($role . '_weight'); ?>" placeholder="<?php echo $role === 'heading' ? '700' : '400'; ?>" style="width:5em;"></label>
                            </td>
                        </tr>
                        <?php $this->url_row($role . '_font_url', __('Font file', 'valolink-plugin'), $field($role . '_font_url'), '', __('.woff2 or .woff.', 'valolink-plugin')); ?>
                    <?php endforeach; ?>
                </tbody></table>

                <?php submit_button(__('Save', 'valolink-plugin')); ?>
            </form>
        </div>
        <script>
        document.querySelectorAll('[data-vlm-pick]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                if (!window.wp || !wp.media) { return; }
                var frame = wp.media({ multiple: false, library: button.dataset.vlmPick ? { type: button.dataset.vlmPick } : {} });
                frame.on('select', function () {
                    document.getElementById(button.dataset.vlmTarget).value = frame.state().get('selection').first().get('url');
                });
                frame.open();
            });
        });
        </script>
        <?php
    }

    private function text_row(string $key, string $label, string $value, string $placeholder, string $help = ''): void
    {
        printf(
            '<tr><th scope="row"><label for="vlm-%1$s">%2$s</label></th><td><input type="text" id="vlm-%1$s" name="%1$s" value="%3$s" placeholder="%4$s" class="large-text">%5$s</td></tr>',
            esc_attr($key),
            esc_html($label),
            $value,
            esc_attr($placeholder),
            $help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : '',
        );
    }

    private function url_row(string $key, string $label, string $value, string $media_type, string $help): void
    {
        printf(
            '<tr><th scope="row"><label for="vlm-%1$s">%2$s</label></th><td><input type="url" id="vlm-%1$s" name="%1$s" value="%3$s" class="large-text"> '
                . '<button class="button" data-vlm-pick="%4$s" data-vlm-target="vlm-%1$s">%5$s</button><p class="description">%6$s</p></td></tr>',
            esc_attr($key),
            esc_html($label),
            $value,
            esc_attr($media_type),
            esc_html__('Media Library', 'valolink-plugin'),
            esc_html($help),
        );
    }

    private function colour_row(string $key, string $label, string $value, string $default): void
    {
        printf(
            '<tr><th scope="row"><label for="vlm-%1$s">%2$s</label></th><td><input type="text" id="vlm-%1$s" name="%1$s" value="%3$s" placeholder="%4$s" pattern="#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})" style="width:8em;"> '
                . '<span style="display:inline-block;width:1.4em;height:1.4em;vertical-align:middle;border:1px solid #ccc;background:%5$s;"></span></td></tr>',
            esc_attr($key),
            esc_html($label),
            $value,
            esc_attr($default),
            esc_attr($value !== '' ? $value : $default),
        );
    }

    // -------------------------------------------------------------------------

    private function handle_save(): void
    {
        $this->authorise();

        $clean = [];
        foreach (array_keys(Branding::FIELDS) as $key) {
            $raw = isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
            $value = Branding::clean($key, $raw);
            $clean[$key] = $value ?? '';
        }

        // A family with no file: try for the Google font of that name. A miss
        // is fine — the page then asks for the family by name.
        foreach (['heading', 'body'] as $role) {
            if ($clean[$role . '_font'] !== '' && $clean[$role . '_font_url'] === '') {
                try {
                    $url = GoogleFonts::fetch((string) $clean[$role . '_font'], (int) ($clean[$role . '_weight'] ?: ($role === 'heading' ? 700 : 400)));
                } catch (\Throwable $e) {
                    $url = null;
                }
                if ($url !== null) {
                    $clean[$role . '_font_url'] = $url;
                }
            }
        }

        $this->settings->set_module_settings(self::MODULE_ID, $clean);
        DropIn::sync($this->all());
        $this->back(['saved' => '1']);
    }

    private function handle_import(): void
    {
        $this->authorise();

        $report = ThemeImport::run();
        if ($report['values'] !== []) {
            $this->settings->set_module_settings(self::MODULE_ID, $report['values']);
            DropIn::sync($this->all());
        }
        set_transient(self::REPORT_KEY . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS);
        $this->back([]);
    }

    private function handle_preview(): void
    {
        $this->authorise();
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo DropIn::html($this->all()); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        exit;
    }

    private function authorise(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);
    }

    private function back(array $args): void
    {
        wp_safe_redirect(add_query_arg(['page' => self::SUBPAGE_SLUG] + $args, admin_url('admin.php')));
        exit;
    }

    private function all(): array
    {
        $all = $this->settings->all();
        $mine = $all['modules'][self::MODULE_ID]['settings'] ?? [];

        return is_array($mine) ? $mine : [];
    }

    /**
     * Run a hook body so that nothing it throws reaches WordPress. The
     * Loader only guards loading; this guards everything after.
     */
    private function guard(callable $fn, string $what): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log(sprintf('[valolink-plugin] maintenance page (%s) failed: %s in %s:%d', $what, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    }
}
