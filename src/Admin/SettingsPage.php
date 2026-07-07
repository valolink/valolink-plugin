<?php

declare(strict_types=1);

namespace Valolink\Plugin\Admin;

use Valolink\Plugin\Registry;
use Valolink\Plugin\Settings;
use Valolink\Plugin\Updater;

final class SettingsPage
{
    public const MENU_SLUG    = 'valolink-plugin';
    public const CAPABILITY   = 'manage_options';
    public const NONCE_ACTION = 'valolink_save_settings';
    public const SAVE_ACTION  = 'valolink_save_settings';

    public function __construct(
        private readonly Settings $settings,
        private readonly Registry $registry,
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);
    }

    public function add_menu_page(): void
    {
        add_menu_page(
            __('Valolink', 'valolink-plugin'),
            __('Valolink', 'valolink-plugin'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-shield',
            81,
        );
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $manifests       = $this->registry->all();
        $updated         = isset($_GET['updated']) && $_GET['updated'] === '1';
        $update_info     = $this->plugin_update_info();
        $updater_enabled = defined('VALOLINK_PLUGIN_GITHUB_REPO') && VALOLINK_PLUGIN_GITHUB_REPO !== 'OWNER/REPO';
        $current_version = defined('VALOLINK_PLUGIN_VERSION') ? VALOLINK_PLUGIN_VERSION : '?';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Valolink Plugin', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html__('Settings saved.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Plugin', 'valolink-plugin'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Installed version', 'valolink-plugin'); ?></th>
                    <td><code><?php echo esc_html($current_version); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Updates', 'valolink-plugin'); ?></th>
                    <td>
                        <?php if (!$updater_enabled) : ?>
                            <p><em><?php esc_html_e('Auto-updater is not configured for this site.', 'valolink-plugin'); ?></em></p>
                        <?php elseif ($update_info['available']) : ?>
                            <p>
                                <strong style="color:#b32d2e;">
                                    <?php printf(
                                        esc_html__('Update available: %s', 'valolink-plugin'),
                                        esc_html($update_info['new_version']),
                                    ); ?>
                                </strong>
                            </p>
                            <p>
                                <a href="<?php echo esc_url($this->update_now_url()); ?>" class="button button-primary">
                                    <?php esc_html_e('Update now', 'valolink-plugin'); ?>
                                </a>
                                <a href="<?php echo esc_url($this->check_updates_url()); ?>" class="button">
                                    <?php esc_html_e('Check again', 'valolink-plugin'); ?>
                                </a>
                            </p>
                        <?php elseif ($update_info['known']) : ?>
                            <p>
                                <span style="color:#118a4c;">✓ <?php esc_html_e('Up to date.', 'valolink-plugin'); ?></span>
                            </p>
                            <p>
                                <a href="<?php echo esc_url($this->check_updates_url()); ?>" class="button">
                                    <?php esc_html_e('Check for updates', 'valolink-plugin'); ?>
                                </a>
                            </p>
                        <?php else : ?>
                            <p><em><?php esc_html_e('Update status not yet checked.', 'valolink-plugin'); ?></em></p>
                            <p>
                                <a href="<?php echo esc_url($this->check_updates_url()); ?>" class="button">
                                    <?php esc_html_e('Check for updates', 'valolink-plugin'); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <?php if (!empty($manifests)) : ?>
                    <h2><?php echo esc_html__('Modules', 'valolink-plugin'); ?></h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th style="width: 80px;"><?php echo esc_html__('Enabled', 'valolink-plugin'); ?></th>
                                <th><?php echo esc_html__('Module', 'valolink-plugin'); ?></th>
                                <th><?php echo esc_html__('Description', 'valolink-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manifests as $manifest) : ?>
                                <tr>
                                    <td>
                                        <input
                                            type="checkbox"
                                            name="valolink_enabled[]"
                                            value="<?php echo esc_attr($manifest->id); ?>"
                                            <?php checked($this->settings->is_module_enabled($manifest->id)); ?>
                                        >
                                    </td>
                                    <td><strong><?php echo esc_html($manifest->label()); ?></strong></td>
                                    <td><?php echo esc_html($manifest->description()); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php submit_button(__('Save Changes', 'valolink-plugin')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE_ACTION);

        // Module enables
        $submitted = isset($_POST['valolink_enabled']) && is_array($_POST['valolink_enabled'])
            ? array_map('sanitize_key', wp_unslash($_POST['valolink_enabled']))
            : [];

        foreach ($this->registry->all() as $manifest) {
            $this->settings->set_module_enabled(
                $manifest->id,
                in_array($manifest->id, $submitted, true),
            );
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    /**
     * Read WP's plugin-update transient (populated by the Updater).
     *
     * @return array{available: bool, known: bool, new_version: string}
     */
    private function plugin_update_info(): array
    {
        if (!defined('VALOLINK_PLUGIN_BASENAME')) {
            return ['available' => false, 'known' => false, 'new_version' => ''];
        }

        $basename = VALOLINK_PLUGIN_BASENAME;
        $transient = get_site_transient('update_plugins');

        if (is_object($transient)) {
            if (!empty($transient->response[$basename]->new_version)) {
                return [
                    'available'   => true,
                    'known'       => true,
                    'new_version' => (string) $transient->response[$basename]->new_version,
                ];
            }
            if (isset($transient->no_update[$basename])) {
                return ['available' => false, 'known' => true, 'new_version' => ''];
            }
        }

        return ['available' => false, 'known' => false, 'new_version' => ''];
    }

    private function update_now_url(): string
    {
        $basename = defined('VALOLINK_PLUGIN_BASENAME') ? VALOLINK_PLUGIN_BASENAME : '';
        return wp_nonce_url(
            self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($basename)),
            'upgrade-plugin_' . $basename,
        );
    }

    private function check_updates_url(): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . Updater::CHECK_ACTION),
            Updater::CHECK_ACTION,
        );
    }
}
