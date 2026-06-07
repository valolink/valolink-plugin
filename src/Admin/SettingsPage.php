<?php

declare(strict_types=1);

namespace Valolink\Plugin\Admin;

use Valolink\Plugin\Registry;
use Valolink\Plugin\Settings;

final class SettingsPage
{
    public const MENU_SLUG = 'valolink-plugin';
    public const CAPABILITY = 'manage_options';
    public const NONCE_ACTION = 'valolink_save_settings';
    public const SAVE_ACTION = 'valolink_save_settings';

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

        $manifests = $this->registry->all();
        $updated = isset($_GET['updated']) && $_GET['updated'] === '1';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Valolink Plugin', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html__('Settings saved.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <?php if (empty($manifests)) : ?>
                <p><?php echo esc_html__('No modules registered yet.', 'valolink-plugin'); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>

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
                                    <td><strong><?php echo esc_html($manifest->label); ?></strong></td>
                                    <td><?php echo esc_html($manifest->description); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php submit_button(__('Save Changes', 'valolink-plugin')); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_save(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(
                esc_html__('Insufficient permissions.', 'valolink-plugin'),
                '',
                ['response' => 403]
            );
        }

        check_admin_referer(self::NONCE_ACTION);

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
}
