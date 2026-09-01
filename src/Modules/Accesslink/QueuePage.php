<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Settings;

/**
 * The review screen. Renders the pending queue with a diff per change and
 * approve/reject buttons; every button is a nonced admin-post form that ends
 * up in ChangeService, the same code path the REST routes use.
 */
final class QueuePage
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ChangeRepository $repo,
        private readonly AccesslinkAuth $auth,
    ) {}

    public function render(): void
    {
        if (!current_user_can(ChangeService::APPROVE_CAP)) {
            wp_die(esc_html__('You do not have permission to review changes.', 'valolink-plugin'));
        }

        $pending  = $this->repo->list(ChangeRepository::STATUS_PENDING, 50);
        $recent   = array_filter(
            $this->repo->list(null, 30),
            static fn (array $c): bool => $c['status'] !== ChangeRepository::STATUS_PENDING,
        );
        $notice   = isset($_GET['vl_msg']) ? sanitize_key(wp_unslash($_GET['vl_msg'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Accesslink', 'valolink-plugin'); ?></h1>

            <?php $this->render_notice($notice); ?>
            <?php $this->render_status_banner(); ?>

            <h2><?php
                /* translators: %d: number of pending changes */
                printf(esc_html__('Pending changes (%d)', 'valolink-plugin'), count($pending));
            ?></h2>

            <?php if ($pending === []) : ?>
                <p><?php esc_html_e('Nothing waiting for review.', 'valolink-plugin'); ?></p>
            <?php else : ?>
                <?php foreach ($pending as $change) : ?>
                    <?php $this->render_change($change); ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($recent !== []) : ?>
                <h2><?php esc_html_e('Recently resolved', 'valolink-plugin'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('What', 'valolink-plugin'); ?></th>
                            <th><?php esc_html_e('Action', 'valolink-plugin'); ?></th>
                            <th><?php esc_html_e('Status', 'valolink-plugin'); ?></th>
                            <th><?php esc_html_e('Reviewed', 'valolink-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent as $change) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $change['summary']); ?></td>
                            <td><?php echo esc_html((string) $change['action']); ?></td>
                            <td>
                                <?php echo esc_html((string) $change['status']); ?>
                                <?php if (!empty($change['error'])) : ?>
                                    <br><small><?php echo esc_html((string) $change['error']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html((string) ($change['reviewed_at'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr>
            <?php $this->render_notes(); ?>

            <?php if (current_user_can('manage_options')) : ?>
                <hr>
                <?php $this->render_settings(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_change(array $change): void
    {
        $target_id = (int) $change['target_id'];
        $is_create = $change['action'] === ChangeRepository::ACTION_CREATE;
        ?>
        <div class="card" style="max-width:none;margin-bottom:1em;padding:1em;">
            <h3 style="margin-top:0;">
                <?php echo $is_create ? '+ ' : '&#9998; '; ?>
                <?php echo esc_html((string) $change['summary']); ?>
                <span style="font-weight:normal;color:#666;">
                    — <?php echo esc_html((string) $change['post_type']); ?>,
                    <?php echo esc_html((string) $change['created_at']); ?> UTC
                    <?php if (!empty($change['requested_by'])) : ?>
                        · <?php echo esc_html((string) $change['requested_by']); ?>
                    <?php endif; ?>
                </span>
            </h3>

            <?php if (!empty($change['note'])) : ?>
                <p><em><?php echo esc_html((string) $change['note']); ?></em></p>
            <?php endif; ?>

            <?php if ($is_create) : ?>
                <p><?php esc_html_e('Drafted and ready to preview. Approving publishes it.', 'valolink-plugin'); ?></p>
            <?php else : ?>
                <?php $this->render_diff($change); ?>
            <?php endif; ?>

            <p>
                <?php if ($target_id > 0) : ?>
                    <?php if ($is_create) : ?>
                        <a class="button" target="_blank" rel="noopener"
                           href="<?php echo esc_url((string) get_preview_post_link($target_id)); ?>">
                            <?php esc_html_e('Preview draft', 'valolink-plugin'); ?>
                        </a>
                    <?php else : ?>
                        <a class="button button-secondary" target="_blank" rel="noopener"
                           href="<?php echo esc_url(AccesslinkModule::preview_url((int) $change['id'], $target_id)); ?>">
                            <?php esc_html_e('Preview proposed version', 'valolink-plugin'); ?>
                        </a>
                        <a class="button" target="_blank" rel="noopener"
                           href="<?php echo esc_url((string) get_permalink($target_id)); ?>">
                            <?php esc_html_e('View current version', 'valolink-plugin'); ?>
                        </a>
                    <?php endif; ?>
                    <a class="button" target="_blank" rel="noopener"
                       href="<?php echo esc_url((string) get_edit_post_link($target_id)); ?>">
                        <?php
                        // For an update this opens the *live* post, which is
                        // deliberately still unchanged — labelled so it doesn't
                        // read as "edit the proposal".
                        echo $is_create
                            ? esc_html__('Open draft in editor', 'valolink-plugin')
                            : esc_html__('Open current version in editor', 'valolink-plugin');
                        ?>
                    </a>
                <?php endif; ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <?php wp_nonce_field(AccesslinkModule::REVIEW_NONCE); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(AccesslinkModule::REVIEW_ACTION); ?>">
                <input type="hidden" name="change_id" value="<?php echo esc_attr((string) $change['id']); ?>">
                <button class="button button-primary" name="decision" value="approve">
                    <?php esc_html_e('Approve', 'valolink-plugin'); ?>
                </button>
                <button class="button" name="decision" value="reject">
                    <?php esc_html_e('Reject', 'valolink-plugin'); ?>
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Diff the live post against what the change proposes, field by field.
     * wp_text_diff() is what the revisions screen uses and it escapes its own
     * output; if it is somehow unavailable we degrade to plain before/after
     * rather than rendering nothing.
     */
    private function render_diff(array $change): void
    {
        $post = get_post((int) $change['target_id']);
        if (!$post instanceof \WP_Post) {
            echo '<p><strong>' . esc_html__('Target post no longer exists.', 'valolink-plugin') . '</strong></p>';
            return;
        }

        // A block edit diffs just that block, which is the whole point of
        // addressing one — a whole-post diff of a nested GenerateBlocks page is
        // unreadable and a reviewer cannot judge it.
        if ($change['action'] === ChangeRepository::ACTION_UPDATE_BLOCK) {
            $path = (string) ($change['payload']['path'] ?? '');
            $current = (new BlockReader())->get_at((string) $post->post_content, $path);
            printf(
                '<h4>%s <code>%s</code></h4>',
                esc_html__('Block', 'valolink-plugin'),
                esc_html(($change['payload']['block_name'] ?? '?') . ' @ ' . $path),
            );
            $this->render_field_diff(
                $current['html'] ?? '',
                (string) ($change['payload']['html'] ?? ''),
            );

            return;
        }

        $applier = new PostApplier();
        $fields = $change['payload']['fields'] ?? [];
        foreach ($fields as $field => $proposed) {
            // Resolved through PostApplier so SEO meta, term lists and the
            // featured image diff the same way post columns do — and so the
            // diff can never disagree with the staleness hash about what
            // "current" means.
            $current = $applier->current_value((int) $change['target_id'], $field);
            $proposed = is_array($proposed) ? implode(', ', $proposed) : (string) $proposed;
            if ($current === $proposed) {
                continue;
            }

            echo '<h4>' . esc_html($field) . '</h4>';
            $this->render_field_diff($current, (string) $proposed);
        }
    }

    private function render_field_diff(string $current, string $proposed): void
    {
        if (function_exists('wp_text_diff')) {
            $diff = wp_text_diff($current, $proposed, [
                'title_left'  => __('Current', 'valolink-plugin'),
                'title_right' => __('Proposed', 'valolink-plugin'),
            ]);
            if ($diff !== '') {
                echo $diff; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_text_diff escapes internally.

                return;
            }
        }

        echo '<p><strong>' . esc_html__('Current', 'valolink-plugin') . '</strong></p>';
        echo '<pre style="white-space:pre-wrap;">' . esc_html($current) . '</pre>';
        echo '<p><strong>' . esc_html__('Proposed', 'valolink-plugin') . '</strong></p>';
        echo '<pre style="white-space:pre-wrap;">' . esc_html($proposed) . '</pre>';
    }

    private function render_status_banner(): void
    {
        if ($this->auth->api_key() === '') {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('No Accesslink API key yet — generate one below before pointing an agent at this site.', 'valolink-plugin')
                . '</p></div>';
        }

        if (!$this->auth->writes_enabled()) {
            echo '<div class="notice notice-info"><p>'
                . esc_html__('Accesslink writes are switched off. Existing changes can still be reviewed, but new ones are refused.', 'valolink-plugin')
                . '</p></div>';
        }
    }

    private function render_notice(string $msg): void
    {
        $map = [
            'approved' => [__('Change applied.', 'valolink-plugin'), 'success'],
            'rejected' => [__('Change rejected.', 'valolink-plugin'), 'success'],
            'stale'    => [__('The post changed after that was proposed — it was parked as stale, nothing was overwritten.', 'valolink-plugin'), 'warning'],
            'failed'   => [__('Applying that change failed. See the row below for the error.', 'valolink-plugin'), 'error'],
            'saved'    => [__('Settings saved.', 'valolink-plugin'), 'success'],
            'notes'    => [__('Agent notes updated.', 'valolink-plugin'), 'success'],
            'keyregen' => [__('API key regenerated.', 'valolink-plugin'), 'success'],
        ];

        if (!isset($map[$msg])) {
            return;
        }

        [$text, $type] = $map[$msg];
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($text),
        );
    }

    /**
     * What agents have told each other about this site. Shown to reviewers,
     * not just admins — a wrong note quietly steers every future agent, so the
     * people reviewing the output are exactly the ones who should see it.
     */
    private function render_notes(): void
    {
        $notes = new AgentNotes($this->settings);
        $all = $notes->all();
        $post_url = admin_url('admin-post.php');
        ?>
        <h2><?php
            /* translators: 1: number of notes, 2: maximum kept */
            printf(esc_html__('Agent notes (%1$d / %2$d)', 'valolink-plugin'), count($all), AgentNotes::MAX_NOTES);
        ?></h2>
        <p class="description">
            <?php esc_html_e('Durable facts agents leave for whoever works on this site next. Handed to every agent as part of its instructions, so a wrong one is worth deleting.', 'valolink-plugin'); ?>
        </p>

        <?php if ($all === []) : ?>
            <p><?php esc_html_e('No notes yet.', 'valolink-plugin'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <tbody>
                <?php foreach ($all as $note) : ?>
                    <tr>
                        <td style="width:14em;">
                            <strong><?php echo esc_html((string) ($note['author'] ?? 'unknown')); ?></strong><br>
                            <small><?php echo esc_html((string) $note['created_at']); ?> UTC</small>
                        </td>
                        <td><?php echo esc_html((string) $note['text']); ?></td>
                        <td style="width:6em;">
                            <form method="post" action="<?php echo esc_url($post_url); ?>">
                                <?php wp_nonce_field(AccesslinkModule::NOTE_NONCE); ?>
                                <input type="hidden" name="action" value="<?php echo esc_attr(AccesslinkModule::NOTE_ACTION); ?>">
                                <button class="button-link delete" name="delete_note"
                                        value="<?php echo esc_attr((string) $note['id']); ?>">
                                    <?php esc_html_e('Delete', 'valolink-plugin'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-top:1em;">
            <?php wp_nonce_field(AccesslinkModule::NOTE_NONCE); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(AccesslinkModule::NOTE_ACTION); ?>">
            <textarea name="new_note" rows="2" class="large-text"
                      maxlength="<?php echo esc_attr((string) AgentNotes::MAX_CHARS); ?>"
                      placeholder="<?php esc_attr_e('Add a note agents should know about this site…', 'valolink-plugin'); ?>"></textarea>
            <p>
                <button class="button"><?php esc_html_e('Add note', 'valolink-plugin'); ?></button>
                <?php if ($all !== []) : ?>
                    <button class="button-link delete" name="clear_notes" value="1"
                            style="margin-left:1em;">
                        <?php esc_html_e('Delete all notes', 'valolink-plugin'); ?>
                    </button>
                <?php endif; ?>
            </p>
        </form>
        <?php
    }

    /**
     * Admins only — the queue itself is visible to anyone who can publish, but
     * the API key on this panel is a write credential for the whole site.
     */
    private function render_settings(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $key   = $this->auth->api_key();
        $types = implode(', ', (array) $this->settings->get_module_setting(
            AccesslinkModule::MODULE_ID,
            'allowed_post_types',
            ['post', 'page'],
        ));
        ?>
        <h2><?php esc_html_e('Settings', 'valolink-plugin'); ?></h2>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(AccesslinkModule::SETTINGS_NONCE); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(AccesslinkModule::SETTINGS_ACTION); ?>">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Base URL', 'valolink-plugin'); ?></th>
                    <td><code><?php echo esc_html(rest_url(AccesslinkModule::REST_NAMESPACE)); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('API key', 'valolink-plugin'); ?></th>
                    <td>
                        <code><?php echo $key !== '' ? esc_html($key) : esc_html__('not generated', 'valolink-plugin'); ?></code>
                        <p class="description">
                            <?php esc_html_e('Propose-only. This key cannot approve anything — approving needs a logged-in user who can publish.', 'valolink-plugin'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Accept new changes', 'valolink-plugin'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="writes_enabled" value="1"
                                <?php checked($this->auth->writes_enabled()); ?>>
                            <?php esc_html_e('Allow agents to file changes', 'valolink-plugin'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Kill switch. Unchecking stops all incoming proposals immediately; the queue stays readable.', 'valolink-plugin'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Allowed post types', 'valolink-plugin'); ?></th>
                    <td>
                        <input type="text" class="regular-text" name="allowed_post_types"
                               value="<?php echo esc_attr($types); ?>">
                        <p class="description">
                            <?php esc_html_e('Comma-separated. Governs both what agents may read and what they may propose.', 'valolink-plugin'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Instructions for agents', 'valolink-plugin'); ?></th>
                    <td>
                        <?php
                        $instructions = (string) $this->settings->get_module_setting(
                            AccesslinkModule::MODULE_ID,
                            'instructions',
                            '',
                        );
                        ?>
                        <textarea name="instructions" rows="10" class="large-text code"
                                  maxlength="<?php echo esc_attr((string) GuideBuilder::INSTRUCTIONS_MAX_CHARS); ?>"
                        ><?php echo esc_textarea($instructions); ?></textarea>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: 1: current character count, 2: maximum */
                                esc_html__('Site-specific rules handed to every agent before it does anything — house style, terminology, sections to leave alone. %1$d / %2$d characters.', 'valolink-plugin'),
                                (int) mb_strlen($instructions),
                                (int) GuideBuilder::INSTRUCTIONS_MAX_CHARS,
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save settings', 'valolink-plugin')); ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(AccesslinkModule::REGEN_NONCE); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(AccesslinkModule::REGEN_ACTION); ?>">
            <?php submit_button(__('Regenerate API key', 'valolink-plugin'), 'secondary', 'submit', false); ?>
            <p class="description">
                <?php esc_html_e('Any agent using the old key stops working immediately.', 'valolink-plugin'); ?>
            </p>
        </form>
        <?php
    }
}
