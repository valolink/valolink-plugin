<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
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

    public const PRUNE_HOOK = 'valolink_accesslink_prune';

    public function __construct(private readonly Settings $settings) {}

    public function should_load(Context $context): bool
    {
        // Admin covers both the review screen and the admin-post handlers.
        return $context->is_admin || $context->is_rest || $context->is_cron;
    }

    public function register(): void
    {
        ChangeTable::maybe_install();

        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_' . self::REVIEW_ACTION, [$this, 'handle_review']);
        add_action('admin_post_' . self::SETTINGS_ACTION, [$this, 'handle_settings']);
        add_action('admin_post_' . self::REGEN_ACTION, [$this, 'handle_regen_key']);
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
        $result = $this->service()->reject((int) $request['id']);

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
            'edit_link'    => $change['target_id'] ? get_edit_post_link((int) $change['target_id'], 'raw') : null,
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

        $service = $this->service();
        $result  = $decision === 'approve' ? $service->approve($id) : $service->reject($id);

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

        $this->settings->set_module_settings(self::MODULE_ID, [
            'writes_enabled'     => !empty($_POST['writes_enabled']),
            'allowed_post_types' => $types !== [] ? $types : ['post', 'page'],
        ]);

        $this->redirect_back('saved');
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

    private function redirect_back(string $msg): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::SUBPAGE_SLUG, 'vl_msg' => $msg],
            admin_url('admin.php'),
        ));
        exit;
    }
}
