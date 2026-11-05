<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Umami;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Modules\Staging\StagingDetector;
use Valolink\Plugin\Settings;

/**
 * Umami analytics: prints our Umami tracker on the front end for the website ID
 * set under Valolink → Umami.
 *
 * The script comes from cdn.valolink.fi, an alias of umami.valolink.fi whose
 * name ad blockers do not list. A staging clone keeps the production website
 * ID, so nothing is printed while the staging decision says staging — the
 * clone would otherwise report its visits as production's.
 *
 * Its own module rather than a Scripts snippet so that event tracking can grow
 * here later.
 */
final class UmamiModule implements Module
{
    public const MODULE_ID    = 'umami';
    public const SUBPAGE_SLUG = 'valolink-umami';
    public const NONCE_ACTION = 'valolink_save_umami';
    public const SAVE_ACTION  = 'valolink_save_umami';

    public const SCRIPT_URL = 'https://cdn.valolink.fi/script.js';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        return $context->is_frontend || $context->is_admin;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);
        add_action('wp_head', [$this, 'print_tracker'], 1);
    }

    public function uninstall(): void
    {
        $this->settings->forget_module(self::MODULE_ID);
    }

    // -------------------------------------------------------------------------
    // Front end
    // -------------------------------------------------------------------------

    public function print_tracker(): void
    {
        try {
            $website_id = $this->website_id();
            if ($website_id === '' || StagingDetector::is_staging()) {
                return;
            }
            wp_print_script_tag([
                'defer'           => true,
                'src'             => self::SCRIPT_URL,
                'data-website-id' => $website_id,
            ]);
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] umami: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function add_settings_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Umami', 'valolink-plugin'),
            __('Umami', 'valolink-plugin'),
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
        $invalid = isset($_GET['invalid']) && $_GET['invalid'] === '1';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Umami', 'valolink-plugin'); ?></h1>

            <?php if ($updated) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e('Umami settings saved.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>
            <?php if ($invalid) : ?>
                <div class="notice notice-error is-dismissible"><p>
                    <?php esc_html_e('That is not a website ID. Copy it from the website\'s settings in Umami: it looks like ab226a42-4040-4181-8b89-f256b4ea0d2a.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <p class="description" style="max-width:780px;">
                <?php
                printf(
                    /* translators: %s: tracker script URL */
                    esc_html__('Adds the Umami tracker (%s) to every front-end page. Nothing is added while the site counts as staging, so a clone does not report its visits as the production site\'s.', 'valolink-plugin'),
                    '<code>' . esc_html(self::SCRIPT_URL) . '</code>',
                );
                ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row">
                            <label for="valolink-umami-website-id"><?php esc_html_e('Website ID', 'valolink-plugin'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="valolink-umami-website-id" name="website_id" class="regular-text code"
                                   value="<?php echo esc_attr($this->website_id()); ?>"
                                   placeholder="ab226a42-4040-4181-8b89-f256b4ea0d2a">
                            <p class="description">
                                <?php esc_html_e('From the website\'s settings in Umami. Leave empty to add no tracker.', 'valolink-plugin'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody></table>

                <?php submit_button(__('Save Umami Settings', 'valolink-plugin')); ?>
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

        $raw        = isset($_POST['website_id']) ? sanitize_text_field(wp_unslash((string) $_POST['website_id'])) : '';
        $website_id = strtolower(trim($raw));
        $args       = ['page' => self::SUBPAGE_SLUG];

        if ($website_id === '' || preg_match(self::UUID_PATTERN, $website_id) === 1) {
            $this->settings->set_module_settings(self::MODULE_ID, ['website_id' => $website_id]);
            $args['updated'] = '1';
        } else {
            $args['invalid'] = '1';
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function website_id(): string
    {
        $id = $this->settings->get_module_setting(self::MODULE_ID, 'website_id', '');
        return is_string($id) && preg_match(self::UUID_PATTERN, $id) === 1 ? $id : '';
    }
}
