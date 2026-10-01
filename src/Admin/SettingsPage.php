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
        $audience        = new NoticeAudience($this->settings);
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
                    <td id="valolink-updates" aria-live="polite">
                        <?php if (!$updater_enabled) : ?>
                            <p><em><?php esc_html_e('Auto-updater is not configured for this site.', 'valolink-plugin'); ?></em></p>
                        <?php else : ?>
                            <p data-valolink-status>
                                <?php if ($update_info['available']) : ?>
                                    <strong style="color:#b32d2e;">
                                        <?php printf(
                                            esc_html__('Update available: %s', 'valolink-plugin'),
                                            esc_html($update_info['new_version']),
                                        ); ?>
                                    </strong>
                                <?php elseif ($update_info['known']) : ?>
                                    <span style="color:#118a4c;">✓ <?php esc_html_e('Up to date.', 'valolink-plugin'); ?></span>
                                <?php else : ?>
                                    <em><?php esc_html_e('Update status not yet checked.', 'valolink-plugin'); ?></em>
                                <?php endif; ?>
                            </p>
                            <p>
                                <a href="<?php echo esc_url($this->update_now_url()); ?>" class="button button-primary" data-valolink-update
                                   <?php echo $update_info['available'] ? '' : 'style="display:none"'; ?>>
                                    <?php esc_html_e('Update now', 'valolink-plugin'); ?>
                                </a>
                                <a href="<?php echo esc_url($this->check_updates_url()); ?>" class="button" data-valolink-check>
                                    <?php $update_info['known'] ? esc_html_e('Check again', 'valolink-plugin') : esc_html_e('Check for updates', 'valolink-plugin'); ?>
                                </a>
                            </p>
                            <?php $this->render_update_script(); ?>
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

                <h2><?php esc_html_e('Admin notices', 'valolink-plugin'); ?></h2>
                <p class="description">
                    <?php printf(
                        /* translators: %s: email domain, e.g. valolink.fi */
                        esc_html__('The plugin\'s own notices (pending Accesslink changes, staging mode) are shown only to the users ticked here. Until a selection is saved, that is everyone with an @%s address.', 'valolink-plugin'),
                        esc_html(NoticeAudience::DEFAULT_DOMAIN),
                    ); ?>
                </p>
                <input type="hidden" name="valolink_notice_users_present" value="1">
                <fieldset style="margin-top:8px;">
                    <?php foreach ($audience->candidates() as $user) : ?>
                        <label style="display:block;margin:4px 0;">
                            <input
                                type="checkbox"
                                name="valolink_notice_users[]"
                                value="<?php echo esc_attr((string) $user->ID); ?>"
                                <?php checked($audience->includes($user)); ?>
                            >
                            <?php echo esc_html($user->display_name); ?>
                            <span style="color:#646970;">&lt;<?php echo esc_html($user->user_email); ?>&gt;</span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

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
            $was = $this->settings->is_module_enabled($manifest->id);
            $now = in_array($manifest->id, $submitted, true);
            $this->settings->set_module_enabled($manifest->id, $now);

            // A module switched off is not loaded again, so one that leaves
            // something outside the database — a file in wp-content — is
            // told here, while it can still clean up.
            if ($was && !$now && method_exists($manifest->class, 'on_disable')) {
                try {
                    ($manifest->class)::on_disable();
                } catch (\Throwable $e) {
                    error_log(sprintf('[valolink-plugin] module "%s" failed to clean up on disable: %s', $manifest->id, $e->getMessage()));
                }
            }
        }

        if (!empty($_POST['valolink_notice_users_present'])) {
            $users = isset($_POST['valolink_notice_users']) && is_array($_POST['valolink_notice_users'])
                ? array_map('absint', wp_unslash($_POST['valolink_notice_users']))
                : [];
            (new NoticeAudience($this->settings))->save($users);
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

    /** The classic update screen — the no-JS fallback — flagged to offer the way back here. */
    private function update_now_url(): string
    {
        $basename = defined('VALOLINK_PLUGIN_BASENAME') ? VALOLINK_PLUGIN_BASENAME : '';
        return wp_nonce_url(
            self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($basename) . '&' . Updater::RETURN_FLAG . '=1'),
            'upgrade-plugin_' . $basename,
        );
    }

    /**
     * Check and update without leaving this page. Checking used to reload
     * wp-admin into the plugins list, and updating ended there too, where
     * this plugin's row had to be found by eye every time.
     *
     * The update is core's own `update-plugin` admin-ajax action, the one the
     * plugins list runs for its inline "Update now": it updates in place and
     * leaves the plugin active, where the classic update screen deactivates
     * it and reactivates it from an iframe. When it answers, this page
     * reloads, which is the first request served by the new version.
     *
     * The links keep working hrefs, so with the script absent or failing
     * they are the old round trips (which now come back here).
     */
    private function render_update_script(): void
    {
        $config = [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'checkAction'  => Updater::AJAX_CHECK_ACTION,
            'checkNonce'   => wp_create_nonce(Updater::AJAX_CHECK_ACTION),
            'updateNonce'  => wp_create_nonce('updates'),
            'plugin'       => defined('VALOLINK_PLUGIN_BASENAME') ? VALOLINK_PLUGIN_BASENAME : '',
            'slug'         => defined('VALOLINK_PLUGIN_BASENAME') ? dirname(VALOLINK_PLUGIN_BASENAME) : '',
            'pageUrl'      => admin_url('admin.php?page=' . self::MENU_SLUG),
            'i18n'         => [
                'checking'  => __('Checking…', 'valolink-plugin'),
                'available' => __('Update available: %s', 'valolink-plugin'),
                'upToDate'  => __('Up to date.', 'valolink-plugin'),
                'again'     => __('Check again', 'valolink-plugin'),
                'updating'  => __('Updating…', 'valolink-plugin'),
                'updated'   => __('Updated to %s. Reloading…', 'valolink-plugin'),
                'failed'    => __('The update did not finish: %s', 'valolink-plugin'),
                'classic'   => __('Try on the update screen', 'valolink-plugin'),
                'error'     => __('Could not reach the site.', 'valolink-plugin'),
            ],
        ];
        ?>
        <script>
        (function () {
            const cfg = <?php echo wp_json_encode($config); ?>;
            const cell = document.getElementById('valolink-updates');
            if (!cell || !window.fetch) return;
            const status = cell.querySelector('[data-valolink-status]');
            const check = cell.querySelector('[data-valolink-check]');
            const update = cell.querySelector('[data-valolink-update]');
            const fallback = update.getAttribute('href');

            const say = (text, colour, strong) => {
                status.replaceChildren();
                const el = document.createElement(strong ? 'strong' : 'span');
                el.textContent = text;
                if (colour) el.style.color = colour;
                status.append(el);
            };
            const post = (data) => fetch(cfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams(data),
            }).then((r) => r.json());

            check.addEventListener('click', (e) => {
                e.preventDefault();
                if (check.getAttribute('aria-disabled') === 'true') return;
                check.setAttribute('aria-disabled', 'true');
                say(cfg.i18n.checking);
                post({ action: cfg.checkAction, _ajax_nonce: cfg.checkNonce })
                    .then((res) => {
                        if (!res.success) throw new Error((res.data && res.data.message) || cfg.i18n.error);
                        if (res.data.available) {
                            say(cfg.i18n.available.replace('%s', res.data.new_version), '#b32d2e', true);
                            update.style.display = '';
                            update.focus();
                        } else {
                            say('✓ ' + cfg.i18n.upToDate, '#118a4c');
                            update.style.display = 'none';
                            check.focus();
                        }
                        check.textContent = cfg.i18n.again;
                    })
                    .catch((err) => say(err.message || cfg.i18n.error, '#b32d2e'))
                    .finally(() => check.removeAttribute('aria-disabled'));
            });

            update.addEventListener('click', (e) => {
                e.preventDefault();
                if (update.getAttribute('aria-disabled') === 'true') return;
                update.setAttribute('aria-disabled', 'true');
                check.setAttribute('aria-disabled', 'true');
                say(cfg.i18n.updating);
                post({ action: 'update-plugin', plugin: cfg.plugin, slug: cfg.slug, _ajax_nonce: cfg.updateNonce })
                    .then((res) => {
                        if (!res.success) {
                            throw new Error((res.data && (res.data.errorMessage || res.data.message)) || cfg.i18n.error);
                        }
                        say(cfg.i18n.updated.replace('%s', res.data.newVersion || ''), '#118a4c', true);
                        window.location.replace(cfg.pageUrl);
                    })
                    .catch((err) => {
                        say(cfg.i18n.failed.replace('%s', err.message || cfg.i18n.error), '#b32d2e');
                        const link = document.createElement('a');
                        link.href = fallback;
                        link.textContent = cfg.i18n.classic;
                        status.append(' ', link);
                        update.removeAttribute('aria-disabled');
                        check.removeAttribute('aria-disabled');
                    });
            });
        })();
        </script>
        <?php
    }

    private function check_updates_url(): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . Updater::CHECK_ACTION),
            Updater::CHECK_ACTION,
        );
    }
}
