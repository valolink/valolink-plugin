<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Comments;

use Valolink\Plugin\Context;
use Valolink\Plugin\Module;

/**
 * Comment mode on the front end for people who edit the site: turn it on
 * from the admin bar, click a part of the page, write, send. Comments pin
 * to what they were written on, stay findable when the page changes (the
 * ladder in assets/comments/comments.js), and can be read through
 * Accesslink with the block they concern, so the agent works from them.
 *
 * Independent of the other modules: it needs only its own table and the
 * front-end assets. Accesslink, when present, reads the comments and
 * reports the changes it made for them through one action.
 */
final class CommentsModule implements Module
{
    public const MODULE_ID  = 'comments';
    public const CAPABILITY = 'edit_posts';
    public const REST_NS    = 'valolink/v1';
    public const PURGE_DAYS = 90;
    public const PURGE_HOOK = 'valolink_comments_purge';

    public function should_load(Context $context): bool
    {
        return $context->is_frontend || $context->is_rest || $context->is_admin || $context->is_cron;
    }

    public function register(): void
    {
        add_action('init', [CommentTable::class, 'maybe_install']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar'], 90);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        // Accesslink says so when a change it filed for a comment goes live.
        add_action('valolink_accesslink_applied', [$this, 'on_change_applied']);
        add_action(self::PURGE_HOOK, [$this, 'purge']);
        if (!wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::PURGE_HOOK);
        }
    }

    public function uninstall(): void
    {
        wp_clear_scheduled_hook(self::PURGE_HOOK);
        CommentTable::drop();
    }

    public static function can_comment(): bool
    {
        return is_user_logged_in() && current_user_can(self::CAPABILITY);
    }

    // -------------------------------------------------------------------------
    // Front end
    // -------------------------------------------------------------------------

    public function add_admin_bar(\WP_Admin_Bar $bar): void
    {
        if (is_admin() || !self::can_comment()) {
            return;
        }
        $open = is_singular() ? (new CommentRepository())->count_open((int) get_queried_object_id()) : 0;
        $bar->add_node([
            'id'    => 'valolink-comment-mode',
            'title' => '<span class="ab-icon dashicons dashicons-admin-comments"></span><span class="ab-label">' . esc_html__('Kommentoi', 'valolink-plugin') . '</span>',
            'href'  => '#',
            'meta'  => ['title' => __('Kommenttitila: napsauta sivun kohtaa ja kirjoita', 'valolink-plugin')],
        ]);
        $bar->add_node([
            'id'    => 'valolink-comments-visible',
            'title' => '<span class="ab-label">' . esc_html(sprintf(__('Kommentit (%d)', 'valolink-plugin'), $open)) . '</span>',
            'href'  => '#',
            'meta'  => ['title' => __('Näytä tai piilota kommentit', 'valolink-plugin')],
        ]);
    }

    public function enqueue(): void
    {
        if (!self::can_comment()) {
            return;
        }
        $version = defined('VALOLINK_PLUGIN_VERSION') ? VALOLINK_PLUGIN_VERSION : '0';
        $url     = defined('VALOLINK_PLUGIN_URL') ? VALOLINK_PLUGIN_URL : plugin_dir_url(dirname(__DIR__, 2) . '/valolink-plugin.php');
        wp_enqueue_style('valolink-comments', $url . 'assets/comments/comments.css', [], $version);
        wp_enqueue_script('valolink-comments', $url . 'assets/comments/comments.js', [], $version, true);
        $user = wp_get_current_user();
        wp_add_inline_script('valolink-comments', 'window.valolinkComments = ' . wp_json_encode([
            'rest'    => esc_url_raw(rest_url(self::REST_NS . '/comments')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'postId'  => is_singular() ? (int) get_queried_object_id() : 0,
            'url'     => (string) (is_singular() ? get_permalink() : home_url(add_query_arg([]))),
            'user'    => ['id' => (int) $user->ID, 'name' => $user->display_name, 'admin' => current_user_can('manage_options')],
            'i18n'    => [
                'write'     => __('Kirjoita kommentti…', 'valolink-plugin'),
                'send'      => __('Lähetä', 'valolink-plugin'),
                'cancel'    => __('Peruuta', 'valolink-plugin'),
                'reply'     => __('Vastaa', 'valolink-plugin'),
                'resolve'   => __('Ratkaistu', 'valolink-plugin'),
                'reopen'    => __('Avaa uudelleen', 'valolink-plugin'),
                'delete'    => __('Poista', 'valolink-plugin'),
                'confirm'   => __('Poistetaanko kommentti?', 'valolink-plugin'),
                'showAll'   => __('Näytä ratkaistut', 'valolink-plugin'),
                'hideAll'   => __('Piilota ratkaistut', 'valolink-plugin'),
                'moved'     => __('siirtynyt', 'valolink-plugin'),
                'near'      => __('lähellä', 'valolink-plugin'),
                'detached'  => __('irronnut', 'valolink-plugin'),
                'addressed' => __('muutos odottaa hyväksyntää', 'valolink-plugin'),
                'resolved'  => __('ratkaistu', 'valolink-plugin'),
                'modeOn'    => __('Kommentoi: päällä', 'valolink-plugin'),
                'modeOff'   => __('Kommentoi', 'valolink-plugin'),
                'comments'  => __('Kommentit', 'valolink-plugin'),
                'none'      => __('Ei kommentteja tällä sivulla.', 'valolink-plugin'),
                'page'      => __('sivu', 'valolink-plugin'),
            ],
        ]) . ';', 'before');
    }

    // -------------------------------------------------------------------------
    // REST for the front end (cookie-authenticated editors)
    // -------------------------------------------------------------------------

    public function register_routes(): void
    {
        $can = static fn (): bool => self::can_comment();
        register_rest_route(self::REST_NS, '/comments', [
            ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'handle_list'], 'permission_callback' => $can],
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'handle_create'], 'permission_callback' => $can],
        ]);
        register_rest_route(self::REST_NS, '/comments/(?P<id>\d+)', [
            ['methods' => \WP_REST_Server::DELETABLE, 'callback' => [$this, 'handle_delete'], 'permission_callback' => $can],
        ]);
        register_rest_route(self::REST_NS, '/comments/(?P<id>\d+)/replies', [
            'methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'handle_reply'], 'permission_callback' => $can,
        ]);
        register_rest_route(self::REST_NS, '/comments/(?P<id>\d+)/status', [
            'methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'handle_status'], 'permission_callback' => $can,
        ]);
        register_rest_route(self::REST_NS, '/comments/(?P<id>\d+)/resolution', [
            'methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'handle_resolution'], 'permission_callback' => $can,
        ]);
    }

    public function handle_list(\WP_REST_Request $request): \WP_REST_Response
    {
        $post_id = (int) $request->get_param('post_id');
        $status  = (string) ($request->get_param('status') ?? 'all');

        return new \WP_REST_Response(['comments' => (new CommentRepository())->list($post_id > 0 ? $post_id : null, $status)]);
    }

    public function handle_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new \WP_Error('bad_body', 'Expected a JSON object.', ['status' => 400]);
        }
        $text = trim(sanitize_textarea_field((string) ($body['text'] ?? '')));
        if ($text === '') {
            return new \WP_Error('no_text', 'text is required.', ['status' => 400]);
        }
        $post_id = (int) ($body['post_id'] ?? 0);
        $anchor  = is_array($body['anchor'] ?? null) ? self::clean_anchor($body['anchor']) : null;
        $quote   = trim(sanitize_textarea_field((string) ($body['quote'] ?? ($anchor['quote'] ?? ''))));
        $block   = $quote !== '' ? BlockLocator::locate($post_id, $quote) : null;
        $user    = wp_get_current_user();
        $repo    = new CommentRepository();
        $id = $repo->insert([
            'post_id'     => $post_id,
            'url'         => esc_url_raw((string) ($body['url'] ?? '')),
            'anchor'      => $anchor,
            'block_path'  => $block['path'] ?? null,
            'block_name'  => $block['name'] ?? null,
            'quote'       => $quote,
            'resolution'  => 'exact',
            'text'        => $text,
            'author_id'   => (int) $user->ID,
            'author_name' => $user->display_name,
        ]);

        return new \WP_REST_Response($repo->find($id) + ['replies' => []], 201);
    }

    public function handle_reply(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $repo   = new CommentRepository();
        $parent = $repo->find((int) $request['id']);
        if ($parent === null || $parent['parent_id'] !== null) {
            return new \WP_Error('not_found', 'No such comment.', ['status' => 404]);
        }
        $body = $request->get_json_params();
        $text = trim(sanitize_textarea_field((string) (is_array($body) ? ($body['text'] ?? '') : '')));
        if ($text === '') {
            return new \WP_Error('no_text', 'text is required.', ['status' => 400]);
        }
        $user = wp_get_current_user();
        $id = $repo->insert([
            'parent_id'   => $parent['id'],
            'post_id'     => $parent['post_id'],
            'url'         => $parent['url'],
            'status'      => $parent['status'],
            'text'        => $text,
            'author_id'   => (int) $user->ID,
            'author_name' => $user->display_name,
        ]);
        $repo->update($parent['id'], []);

        return new \WP_REST_Response($repo->find($id), 201);
    }

    public function handle_status(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $repo    = new CommentRepository();
        $comment = $repo->find((int) $request['id']);
        if ($comment === null) {
            return new \WP_Error('not_found', 'No such comment.', ['status' => 404]);
        }
        $body   = $request->get_json_params();
        $status = (string) (is_array($body) ? ($body['status'] ?? '') : '');
        if (!in_array($status, [CommentRepository::STATUS_OPEN, CommentRepository::STATUS_RESOLVED], true)) {
            return new \WP_Error('bad_status', 'status must be open or resolved.', ['status' => 400]);
        }
        $repo->set_status($comment['id'], $status, wp_get_current_user()->display_name);

        return new \WP_REST_Response($repo->find($comment['id']) + ['replies' => $repo->replies($comment['id'])]);
    }

    public function handle_resolution(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $repo    = new CommentRepository();
        $comment = $repo->find((int) $request['id']);
        if ($comment === null) {
            return new \WP_Error('not_found', 'No such comment.', ['status' => 404]);
        }
        $body = $request->get_json_params();
        $how  = (string) (is_array($body) ? ($body['resolution'] ?? '') : '');
        if (!in_array($how, CommentRepository::RESOLUTIONS, true)) {
            return new \WP_Error('bad_resolution', 'resolution must be one of: ' . implode(', ', CommentRepository::RESOLUTIONS), ['status' => 400]);
        }
        if ($comment['resolution'] !== $how) {
            $repo->update($comment['id'], ['resolution' => $how]);
        }

        return new \WP_REST_Response(['ok' => true]);
    }

    public function handle_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $repo    = new CommentRepository();
        $comment = $repo->find((int) $request['id']);
        if ($comment === null) {
            return new \WP_Error('not_found', 'No such comment.', ['status' => 404]);
        }
        if ($comment['author_id'] !== get_current_user_id() && !current_user_can('manage_options')) {
            return new \WP_Error('forbidden', 'Only the author or an administrator may delete a comment.', ['status' => 403]);
        }
        $repo->delete($comment['id']);

        return new \WP_REST_Response(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // Housekeeping and the Accesslink hand-off
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $change an applied Accesslink change */
    public function on_change_applied(array $change): void
    {
        if (!CommentTable::exists() || empty($change['id'])) {
            return;
        }
        (new CommentRepository())->resolve_by_change((int) $change['id'], sprintf('muutos #%d', (int) $change['id']));
    }

    public function purge(): void
    {
        if (CommentTable::exists()) {
            (new CommentRepository())->purge_resolved(self::PURGE_DAYS);
        }
    }

    /** Only the anchor's known keys, each cut to size. @param array<string, mixed> $a @return array<string, mixed> */
    private static function clean_anchor(array $a): array
    {
        $out = [];
        foreach (['quote', 'prefix', 'suffix', 'tag', 'selection'] as $key) {
            if (isset($a[$key]) && is_scalar($a[$key])) {
                $out[$key] = is_bool($a[$key]) ? $a[$key] : mb_substr(sanitize_textarea_field((string) $a[$key]), 0, 2000);
            }
        }
        if (is_array($a['chain'] ?? null)) {
            $out['chain'] = [];
            foreach (array_slice($a['chain'], 0, 12) as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $out['chain'][] = [
                    'tag'     => sanitize_key((string) ($step['tag'] ?? '')),
                    'id'      => mb_substr(sanitize_text_field((string) ($step['id'] ?? '')), 0, 100),
                    'classes' => array_values(array_slice(array_map(static fn ($c): string => mb_substr(sanitize_text_field((string) $c), 0, 60), (array) ($step['classes'] ?? [])), 0, 8)),
                    'nth'     => (int) ($step['nth'] ?? 0),
                    'text'    => mb_substr(sanitize_text_field((string) ($step['text'] ?? '')), 0, 80),
                ];
            }
        }

        return $out;
    }
}
