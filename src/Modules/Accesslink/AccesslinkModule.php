<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Modules\Accesslink\Translation\TranslationAdapterFactory;
use Valolink\Plugin\Settings;

/**
 * Accesslink — lets an agent *propose* content changes over REST, which a human
 * then approves in wp-admin. Nothing an agent sends ever reaches a published
 * page on its own.
 *
 * Its own REST namespace and its own key, deliberately separate from the
 * EngineLink module's read-only inventory pull: different blast radius,
 * independently revocable.
 */
final class AccesslinkModule implements Module
{
    public const MODULE_ID      = 'accesslink';
    public const SUBPAGE_SLUG   = 'valolink-accesslink';
    public const REST_NAMESPACE = 'accesslink/v1';

    public const REVIEW_ACTION   = 'valolink_accesslink_review';
    public const REVIEW_NONCE    = 'valolink_accesslink_review';
    public const SETTINGS_ACTION = 'valolink_accesslink_settings';
    public const SETTINGS_NONCE  = 'valolink_accesslink_settings';
    public const REGEN_ACTION    = 'valolink_accesslink_regen';
    public const REGEN_NONCE     = 'valolink_accesslink_regen';
    public const NOTE_ACTION     = 'valolink_accesslink_note';
    public const NOTE_NONCE      = 'valolink_accesslink_note';

    public const PRUNE_HOOK    = 'valolink_accesslink_prune';
    public const PREVIEW_PARAM = 'accesslink_preview';

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        // Admin covers both the review screen and the admin-post handlers.
        // CLI is included so the queue can be inspected and the table installed
        // from wp-cli — without it `wp eval` sees the classes (they autoload)
        // but never the schema, which makes the module untestable and
        // unadministrable from a shell. Neither CLI nor cron is a hot path.
        //
        // Frontend loads only when a preview is explicitly being asked for, so
        // ordinary page views keep the module's footprint at zero. Reading one
        // query var is as cheap as this check gets.
        return $context->is_admin
            || $context->is_rest
            || $context->is_cron
            || $context->is_cli
            || ($context->is_frontend && isset($_GET[self::PREVIEW_PARAM]));
    }

    public function register(): void
    {
        // A preview request never touches the queue schema.
        if (isset($_GET[self::PREVIEW_PARAM]) && !is_admin()) {
            add_filter('the_posts', [$this, 'filter_preview_posts'], 10, 2);
            return;
        }

        ChangeTable::maybe_install();

        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_notices', [$this, 'render_pending_notice']);
        add_action('admin_post_' . self::REVIEW_ACTION, [$this, 'handle_review']);
        add_action('admin_post_' . self::SETTINGS_ACTION, [$this, 'handle_settings']);
        add_action('admin_post_' . self::REGEN_ACTION, [$this, 'handle_regen_key']);
        add_action('admin_post_' . self::NOTE_ACTION, [$this, 'handle_note_admin']);
        add_action('rest_api_init', [$this, 'register_routes']);

        add_action(self::PRUNE_HOOK, [$this, 'handle_prune']);
        if (!wp_next_scheduled(self::PRUNE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK);
        }
    }

    public function uninstall(): void
    {
        wp_unschedule_hook(self::PRUNE_HOOK);
        ChangeTable::drop();
        $this->settings->forget_module(self::MODULE_ID);
    }

    // -------------------------------------------------------------------------
    // Wiring
    // -------------------------------------------------------------------------

    private function service(): ChangeService
    {
        return new ChangeService($this->settings, new ChangeRepository(), new PostApplier());
    }

    public function add_settings_page(): void
    {
        $pending = (new ChangeRepository())->count(ChangeRepository::STATUS_PENDING);
        $label   = __('Accesslink', 'valolink-plugin');
        if ($pending > 0) {
            $label .= sprintf(' <span class="awaiting-mod"><span class="pending-count">%d</span></span>', $pending);
        }

        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Accesslink', 'valolink-plugin'),
            $label,
            ChangeService::APPROVE_CAP,
            self::SUBPAGE_SLUG,
            [$this, 'render_page'],
        );
    }

    public function render_pending_notice(): void
    {
        (new ChangeNotifier($this->settings))->render_admin_notice();
    }

    public function render_page(): void
    {
        (new QueuePage($this->settings, new ChangeRepository(), new AccesslinkAuth($this->settings)))->render();
    }

    // -------------------------------------------------------------------------
    // REST
    // -------------------------------------------------------------------------

    public function register_routes(): void
    {
        $auth = new AccesslinkAuth($this->settings);

        // If the queue table never installed — no CREATE privilege, a filtered
        // prefix — answer honestly instead of leaking SQL errors from every
        // endpoint. The module degrades to unavailable, the site stays up.
        if (!ChangeTable::exists()) {
            register_rest_route(self::REST_NAMESPACE, '/(?P<any>.*)', [
                'methods'             => \WP_REST_Server::ALLMETHODS,
                'callback'            => static fn (): \WP_Error => new \WP_Error(
                    'accesslink_unavailable',
                    'Accesslink storage is not installed on this site; the module cannot operate.',
                    ['status' => 503],
                ),
                'permission_callback' => '__return_true',
            ]);

            return;
        }

        // Both verbs in one call rather than two registrations of the same
        // route — WP would merge those, but relying on that is needlessly
        // subtle. Note the different permission callbacks: proposing is
        // blocked by the kill switch, reading is not.
        register_rest_route(self::REST_NAMESPACE, '/changes', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_propose'],
                'permission_callback' => [$auth, 'check'],
            ],
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'handle_list'],
                'permission_callback' => [$auth, 'check_read'],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/changes/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_get'],
            'permission_callback' => [$auth, 'check_read'],
        ]);

        // Self-description. Auth-gated too — no reason to advertise the write
        // surface of a client site to anonymous callers.
        register_rest_route(self::REST_NAMESPACE, '/guide', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_guide'],
            'permission_callback' => [$auth, 'check_read'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/content', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_content_list'],
            'permission_callback' => [$auth, 'check_read'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/content/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_content_get'],
            'permission_callback' => [$auth, 'check_read'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/content/(?P<id>\d+)/blocks', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_content_blocks'],
            'permission_callback' => [$auth, 'check_read'],
        ]);

        // Dry-run the block checks without filing anything, so an agent can
        // iterate before it puts something in a human's queue.
        register_rest_route(self::REST_NAMESPACE, '/validate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_validate'],
            'permission_callback' => [$auth, 'check_read'],
        ]);

        // Lookups for the two fields whose valid values an agent cannot guess:
        // term slugs (creating terms is refused) and attachment ids (uploading
        // is not possible).
        register_rest_route(self::REST_NAMESPACE, '/taxonomies', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_taxonomies'],
            'permission_callback' => [$auth, 'check_read'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/media', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_media'],
            'permission_callback' => [$auth, 'check_read'],
        ]);

        // Multilingual lookups. Both answer on a site with no multilingual
        // plugin — reporting "unavailable, here is what was detected" is more
        // useful to an agent than a 404 it has to interpret.
        register_rest_route(self::REST_NAMESPACE, '/languages', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_languages'],
            'permission_callback' => [$auth, 'check_read'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/content/(?P<id>\d+)/translations', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_translations'],
            'permission_callback' => [$auth, 'check_read'],
        ]);

        // GeneratePress Elements are readable and editable as an ordinary post
        // type once the operator allows it; this exposes the `_generate_*` meta
        // that says what each one actually does and where it appears.
        register_rest_route(self::REST_NAMESPACE, '/elements', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_elements'],
            'permission_callback' => [$auth, 'check_read'],
        ]);

        // Writing a note respects the kill switch: "writes off" should mean
        // nothing lands in the database, not just no content changes. Deleting
        // is deliberately absent — curating what agents tell each other is the
        // operator's job, done in wp-admin, not something an agent can undo.
        register_rest_route(self::REST_NAMESPACE, '/notes', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'handle_notes_list'],
                'permission_callback' => [$auth, 'check_read'],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_notes_add'],
                'permission_callback' => [$auth, 'check'],
            ],
        ]);

        // Approve/reject are capability-gated, NOT key-gated: the propose key
        // must never be able to wave its own changes through. With no logged-in
        // user these 403, which is the intended behaviour — remote approval
        // from EngineLink will need its own approver credential, deliberately
        // not built yet.
        $can_approve = static fn (): bool => current_user_can(ChangeService::APPROVE_CAP);

        register_rest_route(self::REST_NAMESPACE, '/changes/(?P<id>\d+)/approve', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_approve'],
            'permission_callback' => $can_approve,
        ]);

        register_rest_route(self::REST_NAMESPACE, '/changes/(?P<id>\d+)/reject', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_reject'],
            'permission_callback' => $can_approve,
        ]);
    }

    /**
     * Prose for the model, structure for the code calling on its behalf. An
     * agent that only reads `guide` has everything; one that wants to branch on
     * capabilities without parsing English has `limits` and the allowlists.
     */
    public function handle_guide(): \WP_REST_Response
    {
        $service = $this->service();
        $guide = new GuideBuilder(
            $this->settings,
            $service,
            new AgentNotes($this->settings),
            new AccesslinkAuth($this->settings),
        );
        $markdown = $guide->build();

        return new \WP_REST_Response([
            'guide'              => $markdown,
            'chars'              => mb_strlen($markdown),
            'writes_enabled'     => (new AccesslinkAuth($this->settings))->writes_enabled(),
            'allowed_post_types' => $service->allowed_post_types(),
            'allowed_fields'     => (new PostApplier())->allowed_fields(),
            'seo_plugin'         => (new PostApplier())->seo()->id(),
            'allowed_statuses'   => PostApplier::ALLOWED_STATUSES,
            'actions'            => [
                'create', 'update', 'update_text', 'update_block',
                ...ChangeRepository::STRUCTURAL_ACTIONS,
            ],
            // What this particular install supports, so an agent can branch on
            // it rather than discovering absence through a failed proposal.
            'capabilities'       => [
                'seo'            => (new PostApplier())->seo()->can_write(),
                'taxonomy'       => true,
                'featured_image' => true,
                'blocks'         => true,
                'menus'          => false,
                'elements'       => false,
                'delete_post'    => false,
                'media_upload'   => false,
            ],
            'limits'             => [
                'content_list_max'     => ContentReader::LIST_MAX,
                'content_max_chars'    => ContentReader::CONTENT_MAX_CHARS,
                'note_max_chars'       => AgentNotes::MAX_CHARS,
                'notes_kept'           => AgentNotes::MAX_NOTES,
            ],
        ]);
    }

    public function handle_content_list(\WP_REST_Request $request): \WP_REST_Response
    {
        $reader = new ContentReader($this->service(), new PostApplier());

        return new \WP_REST_Response($reader->list([
            'search'    => $request->get_param('search'),
            'post_type' => $request->get_param('post_type'),
            'status'    => $request->get_param('status'),
            'limit'     => $request->get_param('limit'),
        ]));
    }

    public function handle_content_get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = (new ContentReader($this->service(), new PostApplier()))->get((int) $request['id']);

        return is_wp_error($result) ? $result : new \WP_REST_Response($result);
    }

    public function handle_content_blocks(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $post = get_post((int) $request['id']);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('not_found', 'No post with that id.', ['status' => 404]);
        }
        if (!in_array($post->post_type, $this->service()->allowed_post_types(), true)) {
            return new \WP_Error('bad_post_type', 'post_type not permitted on this site.', ['status' => 403]);
        }

        $flat = (new BlockReader())->flatten((string) $post->post_content);
        $flat['id'] = $post->ID;
        $flat['has_blocks'] = has_blocks($post->post_content);

        return new \WP_REST_Response($flat);
    }

    public function handle_validate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new \WP_Error('bad_body', 'Expected a JSON object.', ['status' => 400]);
        }

        $reader = new BlockReader();

        // Either validate supplied content outright, or dry-run a block edit
        // against a real post without queueing it.
        if (isset($body['target_id'], $body['path'])) {
            $post = get_post((int) $body['target_id']);
            if (!$post instanceof \WP_Post) {
                return new \WP_Error('not_found', 'No post with that id.', ['status' => 404]);
            }
            $path = (string) $body['path'];
            $result = array_key_exists('text', $body)
                ? $reader->replace_text_at((string) $post->post_content, $path, (string) $body['text'])
                : $reader->replace_at((string) $post->post_content, $path, (string) ($body['html'] ?? ''));

            if (is_wp_error($result)) {
                return new \WP_REST_Response([
                    'ok'     => false,
                    'issues' => [$result->get_error_message()],
                ]);
            }
            $issues = (new BlockValidator())->check_diff((string) $post->post_content, $result);
            $pre = (new BlockValidator())->check((string) $post->post_content);

            return new \WP_REST_Response([
                'ok'           => $issues === [],
                'issues'       => $issues,
                'pre_existing' => $pre,
                'note'         => 'issues = problems this edit would introduce. pre_existing = already wrong on that post, not your doing. Gutenberg decides final validity in JavaScript; PHP cannot reproduce that.',
            ]);
        } elseif (isset($body['content'])) {
            $content = (string) $body['content'];
        } else {
            return new \WP_Error(
                'nothing_to_validate',
                'Send either {content} or {target_id, path, text|html}.',
                ['status' => 400],
            );
        }

        $issues = (new BlockValidator())->check($content);

        return new \WP_REST_Response([
            'ok'     => $issues === [],
            'issues' => $issues,
            'note'   => 'These are server-side checks only. Gutenberg decides final validity in JavaScript by re-running each block\'s save(); PHP cannot reproduce that.',
        ]);
    }

    public function handle_taxonomies(): \WP_REST_Response
    {
        return new \WP_REST_Response(
            (new ContentReader($this->service(), new PostApplier()))->taxonomies(),
        );
    }

    public function handle_elements(): \WP_REST_Response
    {
        $reader = new ElementReader();
        $result = $reader->list();

        // Say plainly whether proposing against them is possible, rather than
        // letting an agent discover it through a rejected proposal.
        $result['editable'] = in_array(
            ElementReader::POST_TYPE,
            $this->service()->allowed_post_types(),
            true,
        );

        return new \WP_REST_Response($result);
    }

    public function handle_languages(): \WP_REST_Response
    {
        $tr = TranslationAdapterFactory::detect();

        return new \WP_REST_Response([
            'available' => $tr->available(),
            'plugin'    => $tr->plugin(),
            'default'   => $tr->default_language(),
            'languages' => $tr->languages(),
        ]);
    }

    /**
     * The translation group of one post, with the languages it is missing named
     * outright. An agent should not have to diff two lists to work out that the
     * English version does not exist yet.
     */
    public function handle_translations(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $tr = TranslationAdapterFactory::detect();
        $id = (int) $request['id'];

        $post = get_post($id);
        if (!$post instanceof \WP_Post
            || !in_array($post->post_type, $this->service()->allowed_post_types(), true)) {
            return new \WP_Error('not_found', 'No such post.', ['status' => 404]);
        }

        if (!$tr->available()) {
            return new \WP_REST_Response([
                'id'        => $id,
                'available' => false,
                'plugin'    => $tr->plugin(),
            ]);
        }

        $group  = $tr->translations($id);
        $source_modified = get_post_modified_time('Y-m-d H:i:s', true, $post);

        $out = [];
        foreach ($tr->languages() as $language) {
            $slug = $language['slug'];
            $tid  = $group[$slug] ?? 0;
            if ($tid === 0) {
                $out[$slug] = null;
                continue;
            }

            $t = get_post($tid);
            $modified = $t instanceof \WP_Post ? get_post_modified_time('Y-m-d H:i:s', true, $t) : null;
            $out[$slug] = [
                'id'           => $tid,
                'is_source'    => $tid === $id,
                'status'       => $t->post_status ?? null,
                'title'        => $t->post_title ?? null,
                'link'         => get_permalink($tid) ?: null,
                'modified_gmt' => $modified,
                // Older than the post it translates. Polylang tracks no such
                // thing, so this is a timestamp comparison and nothing more —
                // it flags "worth re-reading", not "definitely wrong".
                'outdated'     => $tid !== $id && $modified !== null && $modified < $source_modified,
            ];
        }

        return new \WP_REST_Response([
            'id'        => $id,
            'available' => true,
            'plugin'    => $tr->plugin(),
            'language'  => $tr->language_of($id),
            'missing'   => array_keys(array_filter($out, static fn ($v): bool => $v === null)),
            'translations' => $out,
        ]);
    }

    public function handle_media(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(
            (new ContentReader($this->service(), new PostApplier()))->media([
                'search' => $request->get_param('search'),
                'limit'  => $request->get_param('limit'),
            ]),
        );
    }

    public function handle_notes_list(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'notes' => (new AgentNotes($this->settings))->all(),
            'limits' => [
                'max_chars' => AgentNotes::MAX_CHARS,
                'kept'      => AgentNotes::MAX_NOTES,
            ],
        ]);
    }

    public function handle_notes_add(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        $text = is_array($body) ? (string) ($body['text'] ?? '') : '';
        $agent = sanitize_text_field((string) $request->get_header('x-accesslink-agent'));

        $note = (new AgentNotes($this->settings))->add($text, $agent !== '' ? $agent : null);

        return is_wp_error($note) ? $note : new \WP_REST_Response($note, 201);
    }

    public function handle_propose(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new \WP_Error('bad_body', 'Expected a JSON object.', ['status' => 400]);
        }

        $agent = sanitize_text_field((string) $request->get_header('x-accesslink-agent'));
        $result = $this->service()->propose($body, $agent !== '' ? $agent : null);

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($this->shape($result), 201);
    }

    public function handle_list(\WP_REST_Request $request): \WP_REST_Response
    {
        $status = $request->get_param('status');
        $status = is_string($status) && $status !== '' ? sanitize_key($status) : null;
        $limit  = min(100, max(1, (int) ($request->get_param('limit') ?: 50)));

        $rows = (new ChangeRepository())->list($status, $limit);

        return new \WP_REST_Response(['changes' => array_map([$this, 'shape'], $rows)]);
    }

    public function handle_get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $change = (new ChangeRepository())->find((int) $request['id']);
        if ($change === null) {
            return new \WP_Error('not_found', 'No such change.', ['status' => 404]);
        }

        return new \WP_REST_Response($this->shape($change));
    }

    public function handle_approve(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = $this->service()->approve((int) $request['id']);

        return is_wp_error($result) ? $result : new \WP_REST_Response($this->shape($result));
    }

    public function handle_reject(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        $reason = is_array($body) ? (string) ($body['reason'] ?? '') : '';
        $result = $this->service()->reject((int) $request['id'], $reason !== '' ? $reason : null);

        return is_wp_error($result) ? $result : new \WP_REST_Response($this->shape($result));
    }

    /** Wire shape — deliberately not the raw row. */
    private function shape(array $change): array
    {
        return [
            'id'           => (int) $change['id'],
            'status'       => $change['status'],
            'action'       => $change['action'],
            'entity_type'  => $change['entity_type'],
            'post_type'    => $change['post_type'],
            'target_id'    => $change['target_id'],
            'summary'      => $change['summary'],
            'note'         => $change['note'],
            'requested_by' => $change['requested_by'],
            'created_at'   => $change['created_at'],
            'reviewed_at'  => $change['reviewed_at'],
            'error'        => $change['error'],
            // Why a human turned it down. The agent's only feedback channel —
            // without it a rejection teaches nothing.
            'review_note'  => $change['review_note'] ?? null,
            // Built directly rather than via get_edit_post_link(), which is
            // capability-gated and so returns null for every request
            // authenticated by an Accesslink key — there is no WP user behind
            // one. The link is for whichever human the agent hands it to, so it
            // has to exist regardless of who asked.
            'edit_link'    => $change['target_id']
                ? admin_url('post.php?post=' . (int) $change['target_id'] . '&action=edit')
                : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Admin post handlers
    // -------------------------------------------------------------------------

    public function handle_review(): void
    {
        check_admin_referer(self::REVIEW_NONCE);
        if (!current_user_can(ChangeService::APPROVE_CAP)) {
            wp_die(esc_html__('You do not have permission to review changes.', 'valolink-plugin'));
        }

        $id       = isset($_POST['change_id']) ? (int) $_POST['change_id'] : 0;
        $decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';

        // Explicit allowlist — anything unrecognised must not fall through to
        // one of the two destructive branches.
        if (!in_array($decision, ['approve', 'reject'], true)) {
            $this->redirect_back('failed');
        }

        $reason = isset($_POST['review_note'])
            ? sanitize_textarea_field(wp_unslash($_POST['review_note']))
            : null;

        $service = $this->service();
        $result  = $decision === 'approve' ? $service->approve($id) : $service->reject($id, $reason);

        if (is_wp_error($result)) {
            $msg = 'failed';
        } elseif ($result['status'] === ChangeRepository::STATUS_STALE) {
            $msg = 'stale';
        } else {
            $msg = $decision === 'approve' ? 'approved' : 'rejected';
        }

        $this->redirect_back($msg);
    }

    public function handle_settings(): void
    {
        check_admin_referer(self::SETTINGS_NONCE);
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'valolink-plugin'));
        }

        $raw_types = isset($_POST['allowed_post_types'])
            ? sanitize_text_field(wp_unslash($_POST['allowed_post_types']))
            : '';
        $types = array_values(array_filter(array_map(
            static fn (string $t): string => sanitize_key(trim($t)),
            explode(',', $raw_types),
        )));

        $instructions = isset($_POST['instructions'])
            ? sanitize_textarea_field(wp_unslash($_POST['instructions']))
            : '';
        // Truncated rather than rejected: the guide has a fixed character
        // budget and silently shipping a half-sentence is worse than a visibly
        // clipped one, but losing the operator's whole edit would be worse still.
        $instructions = mb_substr($instructions, 0, GuideBuilder::INSTRUCTIONS_MAX_CHARS);

        $this->settings->set_module_settings(self::MODULE_ID, [
            'notify_enabled'     => !empty($_POST['notify_enabled']),
            'notify_emails'      => isset($_POST['notify_emails'])
                ? sanitize_text_field(wp_unslash($_POST['notify_emails']))
                : '',
            'writes_enabled'     => !empty($_POST['writes_enabled']),
            'allowed_post_types' => $types !== [] ? $types : ['post', 'page'],
            'instructions'       => $instructions,
        ]);

        $this->redirect_back('saved');
    }

    public function handle_note_admin(): void
    {
        check_admin_referer(self::NOTE_NONCE);
        if (!current_user_can(ChangeService::APPROVE_CAP)) {
            wp_die(esc_html__('You do not have permission to manage agent notes.', 'valolink-plugin'));
        }

        $notes = new AgentNotes($this->settings);

        if (isset($_POST['delete_note'])) {
            $notes->delete(sanitize_text_field(wp_unslash($_POST['delete_note'])));
        } elseif (isset($_POST['clear_notes'])) {
            $notes->clear();
        } elseif (isset($_POST['new_note'])) {
            $notes->add(sanitize_textarea_field(wp_unslash($_POST['new_note'])), 'operator');
        }

        $this->redirect_back('notes');
    }

    public function handle_regen_key(): void
    {
        check_admin_referer(self::REGEN_NONCE);
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to regenerate the key.', 'valolink-plugin'));
        }

        $this->settings->set_module_setting(self::MODULE_ID, 'api_key', wp_generate_password(48, false, false));

        $this->redirect_back('keyregen');
    }

    public function handle_prune(): void
    {
        $days = (int) $this->settings->get_module_setting(self::MODULE_ID, 'retention_days', 30);
        (new ChangeRepository())->prune(max(1, $days));
    }

    // -------------------------------------------------------------------------
    // Preview
    // -------------------------------------------------------------------------

    /**
     * Link that renders the target post through the theme with the proposed
     * values swapped in.
     *
     * Reviewing an update used to offer only a text diff, because the whole
     * point of the design is that the live post is untouched while a change is
     * pending — so "Preview" showed the unchanged original, which is worse than
     * useless. This renders the proposal without storing it anywhere.
     */
    public static function preview_url(int $change_id, int $target_id): string
    {
        return add_query_arg(
            [
                self::PREVIEW_PARAM => $change_id,
                '_wpnonce'          => wp_create_nonce(self::PREVIEW_PARAM . '_' . $change_id),
            ],
            (string) get_permalink($target_id),
        );
    }

    /**
     * Swap the proposed field values into the main query's post. Nothing is
     * written; the substitution lives and dies with this request.
     */
    public function filter_preview_posts(array $posts, \WP_Query $query): array
    {
        if (!$query->is_main_query() || $posts === []) {
            return $posts;
        }

        $change_id = isset($_GET[self::PREVIEW_PARAM]) ? (int) $_GET[self::PREVIEW_PARAM] : 0;
        if ($change_id <= 0) {
            return $posts;
        }

        // Nonce first, then capability: previewing a proposal must not be a way
        // to see, or to have the theme render, content the viewer couldn't
        // otherwise reach.
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::PREVIEW_PARAM . '_' . $change_id)) {
            return $posts;
        }
        if (!current_user_can(ChangeService::APPROVE_CAP)) {
            return $posts;
        }

        $change = (new ChangeRepository())->find($change_id);
        if ($change === null || $change['status'] !== ChangeRepository::STATUS_PENDING) {
            return $posts;
        }

        $target = (int) $change['target_id'];
        if ($target <= 0 || (int) $posts[0]->ID !== $target) {
            return $posts;
        }

        // Clone so the substitution doesn't bleed into the post cache — only
        // what this loop renders should show the proposal.
        $preview = clone $posts[0];

        // Every action that rewrites post_content resolves through the same
        // ChangeService method approval uses, so the page rendered here is the
        // page approving would produce. Deriving it separately is how a preview
        // starts quietly disagreeing with what gets applied.
        $content = $this->service()->proposed_content($change, $posts[0]);

        if (is_wp_error($content)) {
            // Failing loudly matters more than degrading gracefully here: the
            // only other option is rendering the current page under a button
            // that says "proposed version", which invites approving something
            // nobody has seen.
            wp_die(
                esc_html(sprintf(
                    /* translators: %s: reason the proposed version could not be built. */
                    __('This proposal can no longer be previewed: %s', 'valolink-plugin'),
                    $content->get_error_message(),
                )),
                esc_html__('Accesslink preview', 'valolink-plugin'),
                ['back_link' => true, 'response' => 200],
            );
        }

        if (is_string($content)) {
            $preview->post_content = $content;
            $posts[0] = $preview;
            nocache_headers();

            return $posts;
        }

        foreach (($change['payload']['fields'] ?? []) as $field => $value) {
            // Only the post columns are worth swapping for a preview — they
            // are what the theme renders from the post object. A proposed
            // category or SEO title changes nothing on the rendered page.
            if (in_array($field, PostApplier::POST_FIELDS, true)) {
                $preview->{$field} = (string) $value;
            }
        }
        $posts[0] = $preview;

        nocache_headers();

        return $posts;
    }

    private function redirect_back(string $msg): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'vl_msg' => $msg],
            admin_url('admin.php'),
        ));
        exit;
    }
}
