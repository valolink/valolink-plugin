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
        $actions = [
            ChangeRepository::ACTION_CREATE,
            ChangeRepository::ACTION_UPDATE,
            ChangeRepository::ACTION_UPDATE_BLOCK,
            ChangeRepository::ACTION_UPDATE_TEXT,
        ];
        if (!in_array($action, $actions, true)) {
            return new \WP_Error(
                'bad_action',
                'action must be "create", "update", "update_text" or "update_block".',
                ['status' => 400],
            );
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

        // A block edit carries a path and HTML rather than a field map.
        if (in_array($action, [ChangeRepository::ACTION_UPDATE_BLOCK, ChangeRepository::ACTION_UPDATE_TEXT], true)) {
            $note_early = isset($input['note']) ? sanitize_textarea_field((string) $input['note']) : null;

            return $this->propose_update_block($action, $input, $note_early, $requested_by, $idempotency_key);
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

    private function propose_update_block(
        string $action,
        array $input,
        ?string $note,
        ?string $requested_by,
        ?string $idempotency_key,
    ): array|\WP_Error {
        $target_id = (int) ($input['target_id'] ?? 0);
        if ($target_id <= 0) {
            return new \WP_Error('no_target', 'target_id is required.', ['status' => 400]);
        }

        $post = get_post($target_id);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('not_found', 'No post with that id.', ['status' => 404]);
        }
        if (!in_array($post->post_type, $this->allowed_post_types(), true)) {
            return new \WP_Error('bad_post_type', 'post_type not permitted on this site.', ['status' => 400]);
        }

        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return new \WP_Error('no_path', 'path is required — see GET /content/{id}/blocks.', ['status' => 400]);
        }
        $is_text = $action === ChangeRepository::ACTION_UPDATE_TEXT;
        $key = $is_text ? 'text' : 'html';
        if (!array_key_exists($key, $input)) {
            return new \WP_Error('no_' . $key, sprintf('%s is required.', $key), ['status' => 400]);
        }
        $html = (string) $input[$key];

        $reader = new BlockReader();
        $block = $reader->get_at((string) $post->post_content, $path);
        if ($block === null) {
            return new \WP_Error('block_not_found', sprintf('No block at path %s.', $path), ['status' => 404]);
        }

        // Dry-run the replacement now rather than at approval, so a structural
        // failure reaches the agent instead of the reviewer's queue.
        $trial = $is_text
            ? $reader->replace_text_at((string) $post->post_content, $path, $html)
            : $reader->replace_at((string) $post->post_content, $path, $html);
        if (is_wp_error($trial)) {
            $trial->add_data(['status' => 400], $trial->get_error_code());

            return $trial;
        }

        // Cheap block-validity checks on the result. These cannot prove
        // Gutenberg will accept it — that verdict lives in JavaScript — but
        // they catch the mistakes an agent actually makes.
        $issues = (new BlockValidator())->check_diff((string) $post->post_content, $trial);
        if ($issues !== []) {
            return new \WP_Error(
                'invalid_block_markup',
                implode(' ', $issues),
                ['status' => 400, 'issues' => $issues],
            );
        }

        $id = $this->repo->insert([
            'action'          => $action,
            'target_id'       => $target_id,
            'post_type'       => $post->post_type,
            'base_hash'       => $this->block_hash($post, $path),
            'payload'         => [
                'path'          => $path,
                'html'          => $html,
                'block_name'    => $block['name'],
                'previous_html' => $block['html'],
            ],
            'summary'         => $this->summarize($post->post_title . ' — ' . $block['name']),
            'note'            => $note,
            'requested_by'    => $requested_by,
            'idempotency_key' => $idempotency_key,
        ]);

        $this->audit('accesslink_proposed', [
            'change_id' => $id,
            'action'    => $action,
            'post_id'   => $target_id,
            'path'      => $path,
            'by'        => $requested_by,
        ]);

        return $this->repo->find($id) ?? [];
    }

    /**
     * Staleness for a block edit covers the block itself, not the whole post —
     * so unrelated edits elsewhere on the page don't needlessly invalidate it,
     * while a change to this block (or its disappearance) does.
     */
    private function block_hash(\WP_Post $post, string $path): string
    {
        $block = (new BlockReader())->get_at((string) $post->post_content, $path);

        return hash('sha256', (string) wp_json_encode([
            'path' => $path,
            'name' => $block['name'] ?? null,
            'html' => $block['html'] ?? null,
        ]));
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

        if (in_array($change['action'], [ChangeRepository::ACTION_UPDATE_BLOCK, ChangeRepository::ACTION_UPDATE_TEXT], true)) {
            $post = get_post($target_id);
            if (!$post instanceof \WP_Post) {
                return $this->fail($id, 'Target post no longer exists.');
            }

            $path = (string) ($change['payload']['path'] ?? '');
            if (!hash_equals((string) $change['base_hash'], $this->block_hash($post, $path))) {
                $this->repo->update($id, [
                    'status'      => ChangeRepository::STATUS_STALE,
                    'reviewed_by' => get_current_user_id() ?: null,
                    'reviewed_at' => current_time('mysql', true),
                    'error'       => 'That block changed after this was proposed; re-read it and propose again.',
                ]);
                $this->audit('accesslink_stale', ['change_id' => $id, 'post_id' => $target_id], 'warning');

                return $this->repo->find($id) ?? [];
            }

            $reader = new BlockReader();
            $content = $change['action'] === ChangeRepository::ACTION_UPDATE_TEXT
                ? $reader->replace_text_at((string) $post->post_content, $path, (string) ($change['payload']['html'] ?? ''))
                : $reader->replace_at((string) $post->post_content, $path, (string) ($change['payload']['html'] ?? ''));
            if (is_wp_error($content)) {
                return $this->fail($id, $content->get_error_message());
            }

            $result = $this->applier->apply_raw_content($target_id, $content);
        } elseif ($change['action'] === ChangeRepository::ACTION_UPDATE) {
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
