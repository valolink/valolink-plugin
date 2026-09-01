<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Modules\Accesslink\Seo\SeoAdapterFactory;
use Valolink\Plugin\Modules\Logging\EventLogger;
use Valolink\Plugin\Settings;

/**
 * The single implementation of propose / approve / reject.
 *
 * Both entry points — the REST routes and the wp-admin queue screen — come
 * through here, so approval behaves identically whichever drives it. That is
 * the seam that lets EngineLink aggregate queues across sites later without
 * any of this logic having to move.
 */
final class ChangeService
{
    /** Only a user who could publish the change by hand may approve it. */
    public const APPROVE_CAP = 'publish_posts';

    public function __construct(
        private readonly Settings $settings,
        private readonly ChangeRepository $repo,
        private readonly PostApplier $applier,
    ) {}

    /** @return array<int, string> */
    public function allowed_post_types(): array
    {
        $configured = $this->settings->get_module_setting(
            AccesslinkModule::MODULE_ID,
            'allowed_post_types',
            ['post', 'page'],
        );

        return is_array($configured) && $configured !== [] ? array_values($configured) : ['post', 'page'];
    }

    // -------------------------------------------------------------------------
    // Propose
    // -------------------------------------------------------------------------

    public function propose(array $input, ?string $requested_by): array|\WP_Error
    {
        $action = (string) ($input['action'] ?? '');
        if (!in_array($action, [ChangeRepository::ACTION_CREATE, ChangeRepository::ACTION_UPDATE], true)) {
            return new \WP_Error('bad_action', 'action must be "create" or "update".', ['status' => 400]);
        }

        $idempotency_key = isset($input['idempotency_key'])
            ? sanitize_text_field((string) $input['idempotency_key'])
            : null;

        // Agents retry. A repeated key returns the original row untouched
        // rather than filing a duplicate proposal.
        if ($idempotency_key !== null && $idempotency_key !== '') {
            $existing = $this->repo->find_by_idempotency_key($idempotency_key);
            if ($existing !== null) {
                return $existing;
            }
        }

        $fields = $this->sanitize_fields($input['fields'] ?? []);
        if ($fields === []) {
            return new \WP_Error(
                'no_fields',
                'fields must contain at least one of: ' . implode(', ', $this->applier->allowed_fields()),
                ['status' => 400],
            );
        }

        $note = isset($input['note']) ? sanitize_textarea_field((string) $input['note']) : null;

        return $action === ChangeRepository::ACTION_CREATE
            ? $this->propose_create($input, $fields, $note, $requested_by, $idempotency_key)
            : $this->propose_update($input, $fields, $note, $requested_by, $idempotency_key);
    }

    private function propose_create(
        array $input,
        array $fields,
        ?string $note,
        ?string $requested_by,
        ?string $idempotency_key,
    ): array|\WP_Error {
        $post_type = sanitize_key((string) ($input['post_type'] ?? 'post'));
        if (!in_array($post_type, $this->allowed_post_types(), true)) {
            return new \WP_Error('bad_post_type', 'post_type not permitted on this site.', ['status' => 400]);
        }

        $requested_status = sanitize_key((string) ($input['status'] ?? 'publish'));
        if (!in_array($requested_status, PostApplier::ALLOWED_STATUSES, true)) {
            return new \WP_Error('bad_status', 'Unsupported target status.', ['status' => 400]);
        }

        $invalid = $this->applier->validate($post_type, $fields);
        if (is_wp_error($invalid)) {
            return $invalid;
        }

        $draft_id = $this->applier->create_draft($fields, $post_type);
        if (is_wp_error($draft_id)) {
            return $draft_id;
        }

        $id = $this->repo->insert([
            'action'          => ChangeRepository::ACTION_CREATE,
            'target_id'       => $draft_id,
            'post_type'       => $post_type,
            'payload'         => ['fields' => $fields, 'requested_status' => $requested_status],
            'summary'         => $this->summarize($fields['post_title'] ?? '(untitled)'),
            'note'            => $note,
            'requested_by'    => $requested_by,
            'idempotency_key' => $idempotency_key,
        ]);

        $this->audit('accesslink_proposed', [
            'change_id' => $id,
            'action'    => 'create',
            'post_id'   => $draft_id,
            'by'        => $requested_by,
        ]);

        return $this->repo->find($id) ?? [];
    }

    private function propose_update(
        array $input,
        array $fields,
        ?string $note,
        ?string $requested_by,
        ?string $idempotency_key,
    ): array|\WP_Error {
        $target_id = (int) ($input['target_id'] ?? 0);
        if ($target_id <= 0) {
            return new \WP_Error('no_target', 'target_id is required for an update.', ['status' => 400]);
        }

        $post = get_post($target_id);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('not_found', 'No post with that id.', ['status' => 404]);
        }

        if (!in_array($post->post_type, $this->allowed_post_types(), true)) {
            return new \WP_Error('bad_post_type', 'post_type not permitted on this site.', ['status' => 400]);
        }

        $invalid = $this->applier->validate($post->post_type, $fields);
        if (is_wp_error($invalid)) {
            return $invalid;
        }

        $id = $this->repo->insert([
            'action'          => ChangeRepository::ACTION_UPDATE,
            'target_id'       => $target_id,
            'post_type'       => $post->post_type,
            'base_hash'       => $this->applier->hash($target_id, array_keys($fields)),
            'payload'         => ['fields' => $fields],
            'summary'         => $this->summarize($post->post_title),
            'note'            => $note,
            'requested_by'    => $requested_by,
            'idempotency_key' => $idempotency_key,
        ]);

        $this->audit('accesslink_proposed', [
            'change_id' => $id,
            'action'    => 'update',
            'post_id'   => $target_id,
            'by'        => $requested_by,
        ]);

        return $this->repo->find($id) ?? [];
    }

    // -------------------------------------------------------------------------
    // Approve / reject
    // -------------------------------------------------------------------------

    public function approve(int $id): array|\WP_Error
    {
        if (!current_user_can(self::APPROVE_CAP)) {
            return new \WP_Error('forbidden', 'You cannot approve changes.', ['status' => 403]);
        }

        $change = $this->repo->find($id);
        if ($change === null) {
            return new \WP_Error('not_found', 'No such change.', ['status' => 404]);
        }
        if ($change['status'] !== ChangeRepository::STATUS_PENDING) {
            return new \WP_Error('not_pending', 'That change is no longer pending.', ['status' => 409]);
        }

        $target_id = (int) $change['target_id'];
        $fields    = $change['payload']['fields'] ?? [];

        if ($change['action'] === ChangeRepository::ACTION_UPDATE) {
            // Staleness gate. Someone editing the post between proposal and
            // approval must not have their work silently overwritten.
            $current = $this->applier->hash($target_id, array_keys($fields));
            if ($current === '' ) {
                return $this->fail($id, 'Target post no longer exists.');
            }
            if (!hash_equals((string) $change['base_hash'], $current)) {
                $this->repo->update($id, [
                    'status'      => ChangeRepository::STATUS_STALE,
                    'reviewed_by' => get_current_user_id() ?: null,
                    'reviewed_at' => current_time('mysql', true),
                    'error'       => 'Post changed after this was proposed; re-propose against the current version.',
                ]);
                $this->audit('accesslink_stale', ['change_id' => $id, 'post_id' => $target_id], 'warning');

                return $this->repo->find($id) ?? [];
            }

            $result = $this->applier->apply_update($target_id, $fields);
        } else {
            $status = (string) ($change['payload']['requested_status'] ?? 'publish');
            $result = $this->applier->set_status($target_id, $status);
        }

        if (is_wp_error($result)) {
            return $this->fail($id, $result->get_error_message());
        }

        $this->repo->update($id, [
            'status'      => ChangeRepository::STATUS_APPLIED,
            'reviewed_by' => get_current_user_id() ?: null,
            'reviewed_at' => current_time('mysql', true),
            'error'       => null,
        ]);

        $this->audit('accesslink_applied', [
            'change_id' => $id,
            'action'    => $change['action'],
            'post_id'   => $target_id,
        ]);

        return $this->repo->find($id) ?? [];
    }

    public function reject(int $id): array|\WP_Error
    {
        if (!current_user_can(self::APPROVE_CAP)) {
            return new \WP_Error('forbidden', 'You cannot reject changes.', ['status' => 403]);
        }

        $change = $this->repo->find($id);
        if ($change === null) {
            return new \WP_Error('not_found', 'No such change.', ['status' => 404]);
        }
        if ($change['status'] !== ChangeRepository::STATUS_PENDING) {
            return new \WP_Error('not_pending', 'That change is no longer pending.', ['status' => 409]);
        }

        // A rejected create leaves an orphan draft behind, so bin it. Trash
        // rather than delete — recoverable if the rejection was a mis-click.
        if ($change['action'] === ChangeRepository::ACTION_CREATE && $change['target_id']) {
            wp_trash_post((int) $change['target_id']);
        }

        $this->repo->update($id, [
            'status'      => ChangeRepository::STATUS_REJECTED,
            'reviewed_by' => get_current_user_id() ?: null,
            'reviewed_at' => current_time('mysql', true),
        ]);

        $this->audit('accesslink_rejected', [
            'change_id' => $id,
            'post_id'   => $change['target_id'],
        ]);

        return $this->repo->find($id) ?? [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Keep only the fields an agent may set on this site, sanitising each by
     * its kind. Four kinds now live side by side — post columns, SEO strings,
     * taxonomy slug lists and an attachment id — so the switch is explicit
     * rather than a single cast.
     */
    private function sanitize_fields(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $allowed = $this->applier->allowed_fields();
        $out = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $raw)) {
                continue;
            }
            $value = $raw[$field];

            if ($field === 'post_content') {
                // Markup is kept — PostApplier decides how far to filter it at
                // apply time, mirroring WP's own unfiltered_html rule.
                $out[$field] = (string) $value;
                continue;
            }

            if (in_array($field, SeoAdapterFactory::FIELDS, true)) {
                $clean = sanitize_text_field((string) $value);
                $max = SeoAdapterFactory::MAX[$field] ?? 500;
                $out[$field] = mb_substr($clean, 0, $max);
                continue;
            }

            if (isset(PostApplier::TERM_FIELDS[$field])) {
                // Accepts slugs or names; PostApplier resolves them and refuses
                // anything that doesn't already exist.
                $terms = is_array($value) ? $value : [$value];
                $out[$field] = array_values(array_filter(array_map(
                    static fn ($t): string => sanitize_text_field((string) $t),
                    $terms,
                )));
                continue;
            }

            if ($field === PostApplier::MEDIA_FIELD) {
                $out[$field] = (int) $value;
                continue;
            }

            $out[$field] = sanitize_text_field((string) $value);
        }

        return $out;
    }

    private function summarize(string $title): string
    {
        $title = wp_strip_all_tags($title);
        return mb_substr($title !== '' ? $title : '(untitled)', 0, 200);
    }

    private function fail(int $id, string $message): \WP_Error
    {
        $this->repo->update($id, [
            'status'      => ChangeRepository::STATUS_FAILED,
            'reviewed_by' => get_current_user_id() ?: null,
            'reviewed_at' => current_time('mysql', true),
            'error'       => $message,
        ]);
        $this->audit('accesslink_failed', ['change_id' => $id, 'error' => $message], 'error');

        return new \WP_Error('apply_failed', $message, ['status' => 500]);
    }

    /**
     * Best-effort audit. The Logging module may be switched off, in which case
     * its table is absent and the insert quietly fails — per the plugin rule
     * that no module hard-depends on Module D being on.
     */
    private function audit(string $event, array $context, string $level = 'info'): void
    {
        if (!class_exists(EventLogger::class)) {
            return;
        }
        try {
            EventLogger::log($event, $context, $level);
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] accesslink audit failed: ' . $e->getMessage());
        }
    }
}
