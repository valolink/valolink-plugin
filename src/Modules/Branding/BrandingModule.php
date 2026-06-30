<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Branding;

use Valolink\Plugin\Admin\PluginDetector;
use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

final class BrandingModule implements Module
{
    public const MODULE_ID = 'branding';
    public const SUBPAGE_SLUG = 'valolink-branding';
    public const NONCE_ACTION = 'valolink_save_branding';
    public const SAVE_ACTION = 'valolink_save_branding';

    private const LOGO_RELATIVE_PATH = 'src/Modules/Branding/valolink-logo.png';

    /** Login-screen customizers that would fight for the same login_* hooks. */
    private const COMPETING_LOGIN_PLUGINS = [
        'loginpress/loginpress.php',
        'white-label-cms/wlcms-plugin.php',
        'login-customizer/login-customizer.php',
        'custom-login-page-customizer/login-customizer.php',
        'admin-customizer/admin-customizer.php',
    ];

    private Settings $settings;

    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
    }

    public function should_load(Context $context): bool
    {
        return $context->is_admin || $context->is_login;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);

        add_action('login_enqueue_scripts', [$this, 'render_login_styles']);
        add_filter('login_headerurl', [$this, 'filter_login_headerurl']);
        add_filter('login_headertext', [$this, 'filter_login_headertext']);
        add_action('login_footer', [$this, 'render_login_footer']);
    }

    public function uninstall(): void
    {
        $this->settings->forget_module(self::MODULE_ID);
    }

    public function add_settings_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Branding', 'valolink-plugin'),
            __('Branding', 'valolink-plugin'),
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

        $logo_url = (string) $this->setting('logo_url', '');
        $logo_link_url = (string) $this->setting('logo_link_url', '');
        $logo_alt_text = (string) $this->setting('logo_alt_text', '');
        $logo_width = (int) $this->setting('logo_width', 0);
        $logo_height = (int) $this->setting('logo_height', 0);
        $support_html = (string) $this->setting('support_html', '');

        $updated = isset($_GET['updated']) && $_GET['updated'] === '1';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Branding', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html__('Branding settings saved.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <?php PluginDetector::render_conflict_notice(
                PluginDetector::find_active_in(self::COMPETING_LOGIN_PLUGINS),
                __('Another login-screen customizer is active:', 'valolink-plugin'),
            ); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <h2><?php echo esc_html__('Login Logo', 'valolink-plugin'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="valolink_logo_url"><?php echo esc_html__('Logo image URL', 'valolink-plugin'); ?></label>
                            </th>
                            <td>
                                <input type="url" id="valolink_logo_url" name="logo_url" class="regular-text" value="<?php echo esc_attr($logo_url); ?>">
                                <p class="description"><?php echo esc_html__('Upload your logo via the Media Library and paste its URL here. Transparent PNG or SVG recommended.', 'valolink-plugin'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="valolink_logo_link_url"><?php echo esc_html__('Logo link URL', 'valolink-plugin'); ?></label>
                            </th>
                            <td>
                                <input type="url" id="valolink_logo_link_url" name="logo_link_url" class="regular-text" value="<?php echo esc_attr($logo_link_url); ?>">
                                <p class="description"><?php echo esc_html__('Where the logo should link to. Defaults to WordPress.org if empty.', 'valolink-plugin'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="valolink_logo_alt_text"><?php echo esc_html__('Logo alt / title text', 'valolink-plugin'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="valolink_logo_alt_text" name="logo_alt_text" class="regular-text" value="<?php echo esc_attr($logo_alt_text); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php echo esc_html__('Logo dimensions (px)', 'valolink-plugin'); ?>
                            </th>
                            <td>
                                <label>
                                    <?php echo esc_html__('Width', 'valolink-plugin'); ?>
                                    <input type="number" name="logo_width" min="0" max="1000" step="1" value="<?php echo esc_attr((string) $logo_width); ?>" style="width:6em;">
                                </label>
                                &nbsp;
                                <label>
                                    <?php echo esc_html__('Height', 'valolink-plugin'); ?>
                                    <input type="number" name="logo_height" min="0" max="1000" step="1" value="<?php echo esc_attr((string) $logo_height); ?>" style="width:6em;">
                                </label>
                                <?php $defaults = $this->defaults(); ?>
                                <p class="description"><?php printf(
                                    /* translators: 1: default width, 2: default height */
                                    esc_html__('Bundled logo is %1$d × %2$d px. Override if you use a different image.', 'valolink-plugin'),
                                    (int) $defaults['logo_width'],
                                    (int) $defaults['logo_height'],
                                ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2><?php echo esc_html__('Support Block', 'valolink-plugin'); ?></h2>
                <p class="description"><?php echo esc_html__('Shown beneath the login form. Basic HTML allowed (links, paragraphs, simple formatting).', 'valolink-plugin'); ?></p>
                <textarea name="support_html" rows="6" class="large-text code"><?php echo esc_textarea($support_html); ?></textarea>

                <?php submit_button(__('Save Changes', 'valolink-plugin')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Insufficient permissions.', 'valolink-plugin'),
                '',
                ['response' => 403]
            );
        }

        check_admin_referer(self::NONCE_ACTION);

        $logo_url = isset($_POST['logo_url']) && is_string($_POST['logo_url'])
            ? esc_url_raw(wp_unslash($_POST['logo_url']))
            : '';
        $logo_link_url = isset($_POST['logo_link_url']) && is_string($_POST['logo_link_url'])
            ? esc_url_raw(wp_unslash($_POST['logo_link_url']))
            : '';
        $logo_alt_text = isset($_POST['logo_alt_text']) && is_string($_POST['logo_alt_text'])
            ? sanitize_text_field(wp_unslash($_POST['logo_alt_text']))
            : '';
        $logo_width = isset($_POST['logo_width'])
            ? max(0, min(1000, (int) $_POST['logo_width']))
            : 0;
        $logo_height = isset($_POST['logo_height'])
            ? max(0, min(1000, (int) $_POST['logo_height']))
            : 0;
        $support_html = isset($_POST['support_html']) && is_string($_POST['support_html'])
            ? wp_kses_post(wp_unslash($_POST['support_html']))
            : '';

        $this->settings->set_module_settings(self::MODULE_ID, [
            'logo_url'      => $logo_url,
            'logo_link_url' => $logo_link_url,
            'logo_alt_text' => $logo_alt_text,
            'logo_width'    => $logo_width,
            'logo_height'   => $logo_height,
            'support_html'  => $support_html,
        ]);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    public function render_login_styles(): void
    {
        $logo_url = (string) $this->setting('logo_url', '');
        if ($logo_url === '') {
            return;
        }

        $width  = (int) $this->setting('logo_width', 0);
        $height = (int) $this->setting('logo_height', 0);

        ?>
        <style id="valolink-branding-login">
            #login h1 a,
            .login h1 a {
                background-image: url('<?php echo esc_url($logo_url); ?>');
                background-size: contain;
                background-position: center center;
                background-repeat: no-repeat;
                width: <?php echo $width; ?>px;
                height: <?php echo $height; ?>px;
            }
        </style>
        <?php
    }

    public function filter_login_headerurl(string $url): string
    {
        $custom = (string) $this->setting('logo_link_url', '');
        return $custom !== '' ? $custom : $url;
    }

    public function filter_login_headertext(string $text): string
    {
        $custom = (string) $this->setting('logo_alt_text', '');
        return $custom !== '' ? $custom : $text;
    }

    public function render_login_footer(): void
    {
        $support_html = (string) $this->setting('support_html', '');
        if ($support_html === '') {
            return;
        }

        echo '<div class="valolink-branding-support" style="text-align:center;max-width:320px;margin:24px auto 0;font-size:13px;color:#50575e;line-height:1.5;">';
        echo wp_kses_post($support_html);
        echo '</div>';
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        $stored = $this->settings->get_module_setting(self::MODULE_ID, $key, null);
        if ($stored === null || $stored === '' || $stored === 0 || $stored === '0') {
            $defaults = $this->defaults();
            return $defaults[$key] ?? $default;
        }
        return $stored;
    }

    /**
     * Canonical defaults for the Branding module. Used both to render the
     * form (pre-filled values) and at runtime when a stored value is empty.
     */
    private function defaults(): array
    {
        return [
            'logo_url'      => defined('VALOLINK_PLUGIN_URL') ? VALOLINK_PLUGIN_URL . self::LOGO_RELATIVE_PATH : '',
            'logo_link_url' => 'https://valolink.fi',
            'logo_alt_text' => 'Valolink Oy',
            'logo_width'    => 203,
            'logo_height'   => 50,
            'support_html'  => self::default_support_html(),
        ];
    }

    private static function default_support_html(): string
    {
        return <<<'HTML'
<div style="text-align: center;padding: 15px;background: #f6f7f7;margin-top: 20px;border: 1px solid #c3c4c7">
    <h3 style="margin: 0 0 8px 0;font-size: 15px;color: #1d2327">Valolinkin asiakaspalvelu</h3>
    <p style="font-size: 13px;color: #3c434a;margin: 0 0 12px 0;line-height: 1.5">
        Laita meille viestiä asiakaspalveluun, jos kaipaat apua sivuston muutoksissa tai sivustolla on jotain vialla.
    </p>
    <p style="margin: 4px 0;font-size: 13px">
        <a href="mailto:asiakaspalvelu@valolink.fi" style="color: #2271b1;text-decoration: none;font-weight: 600">asiakaspalvelu@valolink.fi</a>
    </p>
    <p style="margin: 4px 0;font-size: 13px">
        <a href="tel:+358443264445" style="color: #2271b1;text-decoration: none;font-weight: 600">+358 (0)44 326 4445</a> <span style="color: #8c8f94">(kiireelliset)</span>
    </p>
</div>
HTML;
    }
}
