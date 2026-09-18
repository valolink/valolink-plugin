<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Logging;

/**
 * Maps WP actions → EventLogger calls. Filter hot-path events early so
 * we never insert spam (revisions, autosaves, no-op transitions).
 */
final class Hooks
{
    /** One failed-login row per IP per this window — throttle against hammering. */
    private const LOGIN_FAIL_THROTTLE_SECONDS = 300;

    /**
     * Post types whose saves are not edits anyone made to the site: WordPress's
     * own plumbing; menu items, logged once per menu instead; product
     * variations, saved in a batch with their product and mostly as meta, so
     * their rows would only repeat the product's; WooCommerce's order records,
     * which are customer transactions with a history of their own; and Action
     * Scheduler's legacy queue.
     */
    private const UNLOGGED_POST_TYPES = [
        'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'nav_menu_item',
        'product_variation', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription',
        'scheduled-action',
    ];

    /** Post columns compared on a save, under the names the log reports them by. */
    private const TRACKED_COLUMNS = [
        'post_title'     => 'title',
        'post_content'   => 'content',
        'post_excerpt'   => 'excerpt',
        'post_status'    => 'status',
        'post_name'      => 'slug',
        'post_date'      => 'date',
        'post_author'    => 'author',
        'post_parent'    => 'parent',
        'menu_order'     => 'order',
        'post_password'  => 'password',
        'comment_status' => 'comments',
    ];

    /** @var array<int, true> menus already logged in this request */
    private array $menus_logged = [];

    public function register(): void
    {
        // Auth
        add_action('wp_login',          [$this, 'on_login'], 10, 2);
        add_action('wp_login_failed',   [$this, 'on_login_failed'], 10, 1);
        add_action('wp_logout',         [$this, 'on_logout'], 10, 1);

        // Plugins
        add_action('activated_plugin',   [$this, 'on_plugin_activated'], 10, 2);
        add_action('deactivated_plugin', [$this, 'on_plugin_deactivated'], 10, 2);
        add_action('deleted_plugin',     [$this, 'on_plugin_deleted'], 10, 2);

        // Themes
        add_action('switch_theme', [$this, 'on_theme_switched'], 10, 3);

        // Upgrades — core/plugin/theme installs and updates
        add_action('upgrader_process_complete', [$this, 'on_upgrader_complete'], 10, 2);

        // Users
        add_action('user_register', [$this, 'on_user_register'], 10, 1);
        add_action('delete_user',   [$this, 'on_user_delete'], 10, 2);

        // Posts — every save of every content type, plus trash, restore and delete
        add_action('wp_after_insert_post', [$this, 'on_post_saved'], 10, 4);
        add_action('trashed_post',         [$this, 'on_post_trashed'], 10, 2);
        add_action('untrashed_post',       [$this, 'on_post_restored'], 10, 2);
        add_action('deleted_post',         [$this, 'on_post_deleted'], 10, 2);

        // Menus — one row per menu, not one per item
        add_action('wp_create_nav_menu',      [$this, 'on_menu_created'], 10, 1);
        add_action('wp_update_nav_menu',      [$this, 'on_menu_saved'], 10, 1);
        add_action('wp_update_nav_menu_item', [$this, 'on_menu_saved'], 10, 1);
        add_action('pre_delete_term',         [$this, 'on_menu_deleting'], 10, 2);

        // Email — covers both native wp_mail and our Resend-routed sends.
        add_action('wp_mail_succeeded', [$this, 'on_mail_sent'],   10, 1);
        add_action('wp_mail_failed',    [$this, 'on_mail_failed'], 10, 1);
    }

    public function on_login(string $user_login, \WP_User $user): void
    {
        EventLogger::log('auth.login', [
            '__user_id'    => $user->ID,
            '__user_login' => $user_login,
        ]);
    }

    public function on_login_failed(string $user_login): void
    {
        // Throttle: collapse a burst of failed logins from one IP into a single
        // row per window so login-hammering can't flood the table. The gate is
        // a transient — on our stack that's the Redis object cache, so it costs
        // no DB write. Independent of the Security module's login guard (which,
        // when enabled, stops most of this upstream before wp_login_failed even
        // fires). Fails OPEN (logs) on any error — this runs inside a hook,
        // outside the loader's try/catch, so it must never throw into the login
        // path; a duplicate row beats a fatal.
        try {
            $ip = EventLogger::client_ip();
            if ($ip !== null) {
                $key = 'vl_llf_' . md5($ip);
                if (get_transient($key)) {
                    return;
                }
                set_transient($key, 1, self::LOGIN_FAIL_THROTTLE_SECONDS);
            }
        } catch (\Throwable $e) {
            // fall through to logging
        }

        EventLogger::log('auth.login_failed', [
            '__user_login' => $user_login,
            '__message'    => "Failed login for: {$user_login}",
        ], 'warning');
    }

    public function on_logout($user_id): void
    {
        $user_login = null;
        if ($user_id && ($u = get_user_by('id', (int) $user_id))) {
            $user_login = $u->user_login;
        }
        EventLogger::log('auth.logout', [
            '__user_id'    => $user_id ? (int) $user_id : null,
            '__user_login' => $user_login,
        ]);
    }

    public function on_plugin_activated(string $plugin, bool $network_wide): void
    {
        EventLogger::log('plugin.activated', [
            'plugin'       => $plugin,
            'network_wide' => $network_wide,
            '__message'    => "Activated: {$plugin}",
        ]);
    }

    public function on_plugin_deactivated(string $plugin, bool $network_wide): void
    {
        EventLogger::log('plugin.deactivated', [
            'plugin'       => $plugin,
            'network_wide' => $network_wide,
            '__message'    => "Deactivated: {$plugin}",
        ]);
    }

    public function on_plugin_deleted(string $plugin, bool $deleted): void
    {
        if (!$deleted) {
            return;
        }
        EventLogger::log('plugin.deleted', [
            'plugin'    => $plugin,
            '__message' => "Deleted: {$plugin}",
        ], 'warning');
    }

    public function on_theme_switched(string $new_name, \WP_Theme $new_theme, \WP_Theme $old_theme): void
    {
        EventLogger::log('theme.switched', [
            'from'      => $old_theme->get_stylesheet(),
            'to'        => $new_theme->get_stylesheet(),
            '__message' => "Theme: {$old_theme->get_stylesheet()} → {$new_theme->get_stylesheet()}",
        ]);
    }

    /**
     * Fires after a successful upgrade run. $hook_extra describes what was upgraded.
     */
    public function on_upgrader_complete(\WP_Upgrader $upgrader, array $hook_extra): void
    {
        $type   = $hook_extra['type']   ?? null;   // plugin | theme | core
        $action = $hook_extra['action'] ?? null;   // install | update

        if ($type === 'core') {
            EventLogger::log('core.updated', [
                'new_version' => get_bloginfo('version'),
                '__message'   => 'WordPress core updated to ' . get_bloginfo('version'),
            ]);
            return;
        }

        // An install carries no `plugin` or `theme` key — only an update does —
        // so for an install the upgrader itself says what it just unpacked.
        // Reading the missing key warned on every install and logged none.
        $items = [];
        if ($type === 'plugin') {
            $items = $hook_extra['plugins'] ?? (isset($hook_extra['plugin']) ? [$hook_extra['plugin']] : []);
            if ($items === [] && $action === 'install' && $upgrader instanceof \Plugin_Upgrader) {
                $installed = $upgrader->plugin_info();
                $items = $installed ? [$installed] : [];
            }
        } elseif ($type === 'theme') {
            $items = $hook_extra['themes'] ?? (isset($hook_extra['theme']) ? [$hook_extra['theme']] : []);
            if ($items === [] && $action === 'install' && $upgrader instanceof \Theme_Upgrader) {
                $installed = $upgrader->theme_info();
                $items = $installed instanceof \WP_Theme ? [$installed->get_stylesheet()] : [];
            }
        }

        if (!$items) {
            return;
        }

        $event = sprintf('%s.%s', $type, $action === 'install' ? 'installed' : 'updated');
        foreach ((array) $items as $item) {
            EventLogger::log($event, [
                $type       => $item,
                '__message' => ucfirst($type) . ' ' . ($action === 'install' ? 'installed' : 'updated') . ': ' . $item,
            ]);
        }
    }

    public function on_user_register(int $user_id): void
    {
        $u = get_user_by('id', $user_id);
        EventLogger::log('user.created', [
            'target_user_id'    => $user_id,
            'target_user_login' => $u ? $u->user_login : null,
            'roles'             => $u ? $u->roles : [],
            '__message'         => $u ? "Created user: {$u->user_login}" : "Created user #{$user_id}",
        ]);
    }

    public function on_user_delete(int $user_id, ?int $reassign): void
    {
        $u = get_user_by('id', $user_id);
        EventLogger::log('user.deleted', [
            'target_user_id'    => $user_id,
            'target_user_login' => $u ? $u->user_login : null,
            'reassigned_to'     => $reassign,
            '__message'         => $u ? "Deleted user: {$u->user_login}" : "Deleted user #{$user_id}",
        ], 'warning');
    }

    public function on_mail_sent(array $mail_data): void
    {
        $to = $mail_data['to'] ?? null;
        if (is_array($to)) $to = implode(', ', $to);
        EventLogger::log('mail.sent', [
            'to'        => (string) ($to ?? ''),
            'subject'   => (string) ($mail_data['subject'] ?? ''),
            '__message' => 'Email sent: ' . (string) ($mail_data['subject'] ?? '(no subject)'),
        ]);
    }

    public function on_mail_failed(\WP_Error $error): void
    {
        $data = $error->get_error_data();
        $to   = is_array($data) ? ($data['to'] ?? null) : null;
        if (is_array($to)) $to = implode(', ', $to);
        EventLogger::log('mail.failed', [
            'to'           => (string) ($to ?? ''),
            'subject'      => is_array($data) ? (string) ($data['subject'] ?? '') : '',
            'error_code'   => $error->get_error_code(),
            'error_message'=> $error->get_error_message(),
            '__message'    => 'Email failed: ' . $error->get_error_message(),
        ], 'warning');
    }

    /**
     * Every save of a post, page, product or other content type — one row per
     * save, naming the post columns that changed.
     *
     * wp_after_insert_post rather than transition_post_status: it fires once
     * per save with terms and meta already in, hands over the post as it was
     * before, and runs for scheduled publishing, trashing and restoring too.
     * So publishing is logged from here as well, and a publish click is one
     * row rather than a transition plus a save.
     */
    public function on_post_saved(int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before): void
    {
        if ($post->post_status === 'auto-draft' || !self::loggable($post)) {
            return;
        }
        // The block editor saves a post over REST, then posts its legacy meta
        // boxes back in a second request that saves the post again. The first
        // request has already logged this click.
        if (isset($_GET['meta-box-loader'])) {
            return;
        }

        $old = $post_before instanceof \WP_Post ? $post_before->post_status : 'new';
        $new = $post->post_status;
        // Trashing and restoring are logged by their own hooks.
        if ($new === 'trash' || $old === 'trash') {
            return;
        }

        $is_new = in_array($old, ['new', 'auto-draft'], true);
        if ($new === 'publish' && $old !== 'publish') {
            $event = 'post.published';
        } elseif ($old === 'publish' && $new !== 'publish') {
            $event = 'post.unpublished';
        } else {
            $event = $is_new ? 'post.created' : 'post.updated';
        }

        $context = [
            'post_id'    => $post->ID,
            'post_type'  => $post->post_type,
            'post_title' => $post->post_title,
            'old_status' => $old,
            'new_status' => $new,
        ];

        $changed = [];
        if (!$is_new && $post_before instanceof \WP_Post) {
            foreach (self::TRACKED_COLUMNS as $column => $name) {
                if ((string) $post_before->{$column} !== (string) $post->{$column}) {
                    $changed[] = $name;
                }
            }
            $context['changed'] = $changed;
            if (in_array('title', $changed, true)) {
                $context['title_before'] = $post_before->post_title;
            }
            // A page whose content went from five thousand characters to none
            // is the edit this log most needs to make obvious.
            if (in_array('content', $changed, true)) {
                $context['content_chars'] = [mb_strlen($post_before->post_content), mb_strlen($post->post_content)];
            }
        }

        $what = self::describe($post);
        $context['__message'] = match ($event) {
            'post.published'   => "{$what} published",
            'post.unpublished' => "{$what} unpublished ({$old} → {$new})",
            'post.created'     => $post->post_type === 'attachment' ? "{$what} uploaded" : "{$what} created as {$new}",
            default            => $changed !== []
                ? "{$what} updated: " . implode(', ', $changed)
                : "{$what} saved with no post fields changed",
        };

        EventLogger::log($event, $context + self::origin());
    }

    public function on_post_trashed(int $post_id, string $previous_status = ''): void
    {
        $post = get_post($post_id);
        if ($post instanceof \WP_Post && self::loggable($post)) {
            EventLogger::log('post.trashed', self::post_context($post, $previous_status, 'trash', 'trashed'));
        }
    }

    public function on_post_restored(int $post_id, string $previous_status = ''): void
    {
        $post = get_post($post_id);
        if ($post instanceof \WP_Post && self::loggable($post)) {
            EventLogger::log(
                'post.restored',
                self::post_context($post, 'trash', $post->post_status, 'restored as ' . $post->post_status),
            );
        }
    }

    public function on_post_deleted(int $post_id, \WP_Post $post): void
    {
        if (self::loggable($post)) {
            EventLogger::log('post.deleted', self::post_context($post, $post->post_status, 'deleted', 'deleted'), 'warning');
        }
    }

    public function on_menu_created(int $menu_id): void
    {
        $this->log_menu('menu.created', $menu_id, 'created');
    }

    /**
     * A menu's items are posts, but a row per item would turn relabelling a
     * twenty-item menu into twenty rows. Saving a menu fires this for the menu
     * and again for every item, so it is logged once per menu per request.
     */
    public function on_menu_saved(int $menu_id): void
    {
        if ($menu_id > 0 && !isset($this->menus_logged[$menu_id])) {
            $this->log_menu('menu.updated', $menu_id, 'updated');
        }
    }

    /** Before the term goes, while the row can still name the menu. */
    public function on_menu_deleting(int $term_id, string $taxonomy): void
    {
        if ($taxonomy === 'nav_menu') {
            $this->log_menu('menu.deleted', $term_id, 'deleted', 'warning');
        }
    }

    private function log_menu(string $event, int $menu_id, string $verb, string $level = 'info'): void
    {
        if (!self::by_someone()) {
            return;
        }
        $this->menus_logged[$menu_id] = true;

        $menu = wp_get_nav_menu_object($menu_id);
        $name = $menu instanceof \WP_Term ? $menu->name : '#' . $menu_id;
        EventLogger::log($event, [
            'menu_id'   => $menu_id,
            'menu'      => $name,
            '__message' => sprintf('Menu "%s" %s', $name, $verb),
        ] + self::origin(), $level);
    }

    /** @return array<string, mixed> */
    private static function post_context(\WP_Post $post, string $old_status, string $new_status, string $verb): array
    {
        return [
            'post_id'    => $post->ID,
            'post_type'  => $post->post_type,
            'post_title' => $post->post_title,
            'old_status' => $old_status,
            'new_status' => $new_status,
            '__message'  => self::describe($post) . ' ' . $verb,
        ] + self::origin();
    }

    /** `Page "Etusivu"` — how every post row names its post. */
    private static function describe(\WP_Post $post): string
    {
        return sprintf('%s "%s"', ucfirst($post->post_type), $post->post_title !== '' ? $post->post_title : '#' . $post->ID);
    }

    /**
     * Whether a save is an edit to the site worth a row: not one of the
     * skipped types, and made by someone. A save with nobody signed in outside
     * cron and WP-CLI came from a visitor — a form stored as a post, a guest
     * checkout — or from an Accesslink agent, whose proposals have their own
     * audit rows.
     */
    private static function loggable(\WP_Post $post): bool
    {
        return !in_array($post->post_type, self::UNLOGGED_POST_TYPES, true) && self::by_someone();
    }

    /** A signed-in user, cron or WP-CLI. */
    private static function by_someone(): bool
    {
        return is_user_logged_in() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI);
    }

    /**
     * Where a change came from when no signed-in user made it. Empty for a
     * user's own edit, which the row's user columns already name.
     *
     * @return array<string, string>
     */
    private static function origin(): array
    {
        if (is_user_logged_in()) {
            return [];
        }
        if (wp_doing_cron()) {
            return ['via' => 'cron'];
        }

        return defined('WP_CLI') && WP_CLI ? ['via' => 'cli'] : [];
    }
}
