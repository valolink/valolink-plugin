<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Modules\Accesslink\Seo\SeoAdapterFactory;
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

        // Oldest first. Approving reloads the page at the top, so the next
        // change to look at has to be the one at the top — and changes filed
        // against the same document frequently have to be applied in the
        // order the agent filed them.
        $pending  = $this->repo->list(ChangeRepository::STATUS_PENDING, 50, 0, true);
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
                            <th><?php esc_html_e('Reason given', 'valolink-plugin'); ?></th>
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
                            <td><?php echo esc_html((string) ($change['review_note'] ?? '')); ?></td>
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
        // A translation creates a draft exactly as `create` does, so it gets the
        // same review affordances: preview the draft in the real theme, open it
        // in the editor. Testing for ACTION_CREATE alone showed it as an edit of
        // a page that does not exist yet.
        $is_create = in_array($change['action'], ChangeRepository::DRAFT_ACTIONS, true);
        $is_translation = $change['action'] === ChangeRepository::ACTION_CREATE_TRANSLATION;
        $is_set_language = $change['action'] === ChangeRepository::ACTION_SET_LANGUAGE;
        $is_menu = $change['action'] === ChangeRepository::ACTION_UPDATE_MENU;
        $is_sync_meta = $change['action'] === ChangeRepository::ACTION_SYNC_TRANSLATION_META;
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

            <?php if ($change['post_type'] === ElementReader::POST_TYPE) : ?>
                <?php
                // An Element is site furniture, not a page. A reviewer looking at
                // a diff of one has no way of telling from the diff alone that
                // approving it changes every page the Element renders on.
                $element = (new ElementReader())->get((int) $change['target_id']);
                ?>
                <p class="notice notice-warning" style="padding:.5em 1em;margin:0 0 1em;">
                    <?php esc_html_e('This is a GeneratePress Element — site furniture, not one page. Approving changes it everywhere it displays.', 'valolink-plugin'); ?>
                    <?php if (!is_wp_error($element)) : ?>
                        <br>
                        <code><?php
                            echo esc_html(trim(sprintf(
                                '%s%s%s',
                                (string) ($element['element_type'] ?? '?'),
                                isset($element['block_type']) ? ' / ' . $element['block_type'] : '',
                                isset($element['hook']) ? ' @ ' . $element['hook'] : '',
                            )));
                        ?></code>
                        <?php if (!empty($element['display_conditions'])) : ?>
                            — <?php echo esc_html(wp_json_encode($element['display_conditions'])); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($change['post_type'] === ProductApplier::POST_TYPE && ProductApplier::available()) : ?>
                <?php $this->render_product_context($change); ?>
            <?php endif; ?>

            <?php if ($is_translation) : ?>
                <?php
                $source_id = (int) ($change['payload']['source_id'] ?? 0);
                $source    = $source_id > 0 ? get_post($source_id) : null;
                ?>
                <p>
                    <?php
                    printf(
                        /* translators: 1: source post title, 2: source language, 3: target language. */
                        esc_html__(
                            'Translation of %1$s (%2$s) into %3$s. The block structure is cloned from the source and only the text differs — check the wording, not the layout.',
                            'valolink-plugin',
                        ),
                        '<strong>' . esc_html($source->post_title ?? (string) $source_id) . '</strong>',
                        esc_html((string) ($change['payload']['source_lang'] ?? '?')),
                        '<strong>' . esc_html((string) ($change['payload']['lang'] ?? '?')) . '</strong>',
                    );
                    ?>
                    <?php if ($source instanceof \WP_Post) : ?>
                        <a target="_blank" rel="noopener" href="<?php echo esc_url((string) get_permalink($source_id)); ?>">
                            <?php esc_html_e('View the original', 'valolink-plugin'); ?>
                        </a>
                    <?php endif; ?>
                </p>
            <?php elseif ($is_menu) : ?>
                <?php $this->render_menu_diff($change); ?>
            <?php elseif ($is_sync_meta) : ?>
                <?php $this->render_meta_sync($change); ?>
            <?php elseif ($is_set_language) : ?>
                <p>
                    <?php
                    printf(
                        /* translators: %s: language slug. */
                        esc_html__(
                            'Assigns the language %s to this post. Nothing about the content changes; it only becomes translatable, and can then be linked to versions in other languages.',
                            'valolink-plugin',
                        ),
                        '<strong>' . esc_html((string) ($change['payload']['lang'] ?? '?')) . '</strong>',
                    );
                    ?>
                </p>
            <?php elseif ($is_create) : ?>
                <p><?php esc_html_e('Drafted and ready to preview. Approving publishes it.', 'valolink-plugin'); ?></p>
            <?php else : ?>
                <?php $this->render_diff($change); ?>
            <?php endif; ?>

            <p>
                <?php if ($is_menu) : ?>
                    <a class="button" target="_blank" rel="noopener"
                       href="<?php echo esc_url(admin_url('nav-menus.php?menu=' . $target_id)); ?>">
                        <?php esc_html_e('Open this menu in the editor', 'valolink-plugin'); ?>
                    </a>
                <?php elseif ($target_id > 0) : ?>
                    <?php if ($is_create) : ?>
                        <a class="button" target="_blank" rel="noopener"
                           href="<?php echo esc_url((string) get_preview_post_link($target_id)); ?>">
                            <?php esc_html_e('Preview draft', 'valolink-plugin'); ?>
                        </a>
                    <?php else : ?>
                        <?php if (!$is_set_language && !$is_sync_meta) : ?>
                            <?php // set_language changes no content, so a "proposed version" would
                                  // render the page exactly as it already is. ?>
                            <a class="button button-secondary" target="_blank" rel="noopener"
                               href="<?php echo esc_url(AccesslinkModule::preview_url((int) $change['id'], $target_id)); ?>">
                                <?php esc_html_e('Preview proposed version', 'valolink-plugin'); ?>
                            </a>
                        <?php endif; ?>
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

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(AccesslinkModule::REVIEW_NONCE); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(AccesslinkModule::REVIEW_ACTION); ?>">
                <input type="hidden" name="change_id" value="<?php echo esc_attr((string) $change['id']); ?>">
                <p>
                    <input type="text" name="review_note" class="regular-text"
                           placeholder="<?php esc_attr_e('Reason, if rejecting — the agent reads this', 'valolink-plugin'); ?>">
                </p>
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

        // Resolved through the same ChangeService method approval and the
        // front-end preview use, so all three describe one change. Null means
        // this action does not rewrite post_content and the field diff below
        // is the right view.
        $proposed = (new ChangeService($this->settings, $this->repo, new PostApplier()))
            ->proposed_content($change, $post);

        if (is_wp_error($proposed)) {
            printf(
                '<p><strong>%s</strong></p>',
                esc_html(sprintf(
                    /* translators: %s: why the proposed version could not be built. */
                    __('This change can no longer be shown against the current page: %s', 'valolink-plugin'),
                    $proposed->get_error_message(),
                )),
            );

            return;
        }

        if (is_string($proposed)) {
            $this->render_block_diff($change, (string) $post->post_content, $proposed);

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
            if (is_array($proposed)) {
                if (in_array($field, ElementReader::CONDITION_FIELDS, true)) {
                    // Same JSON shape current_value() produces, so equal
                    // conditions compare equal instead of always differing.
                    $normalised = ElementReader::normalise_conditions($field, $proposed);
                    $proposed = is_wp_error($normalised) ? '(invalid)' : (string) wp_json_encode($normalised);
                } else {
                    $proposed = implode(', ', $proposed);
                }
            } else {
                $proposed = (string) $proposed;
            }
            if ($current === $proposed) {
                continue;
            }

            echo '<h4>' . esc_html($field) . '</h4>';
            $this->render_field_diff($current, $proposed);
            if (in_array($field, ['seo_title', 'seo_description'], true)) {
                $this->render_seo_preview($applier, (int) $change['target_id'], $field, $proposed);
            }
            if (in_array($field, ProductApplier::PRICE_FIELDS, true) && ProductApplier::available()) {
                $this->render_price_preview($current, $proposed);
            }
        }
    }

    /**
     * What the SEO plugin will actually output for the proposed value, with a
     * length. The diff shows the stored string, template variables and all;
     * a reviewer judging a title against Google's ~60 characters needs the
     * rendered one, and the raw `%%sep%% %%sitename%%` tells them nothing.
     */
    private function render_seo_preview(PostApplier $applier, int $post_id, string $field, string $proposed): void
    {
        if ($proposed === '') {
            $template = $applier->seo()->title_template((string) get_post_type($post_id));
            if ($field === 'seo_title' && $template !== '') {
                printf(
                    '<p class="description">%s <code>%s</code></p>',
                    esc_html__('Empty — the plugin falls back to its template:', 'valolink-plugin'),
                    esc_html($template),
                );
            }

            return;
        }

        $rendered = $applier->seo()->render($post_id, $proposed);
        $shown    = $rendered !== '' ? $rendered : $proposed;
        $length   = mb_strlen($shown);
        $limit    = (int) (SeoAdapterFactory::RECOMMENDED[$field] ?? 0);
        printf(
            '<p class="description">%s <strong>%s</strong> — %s</p>',
            esc_html__('Renders as:', 'valolink-plugin'),
            esc_html($shown),
            esc_html($limit > 0
                ? sprintf(
                    /* translators: 1: rendered length, 2: recommended maximum */
                    __('%1$d characters, about %2$d recommended', 'valolink-plugin'),
                    $length,
                    $limit,
                )
                : sprintf(
                    /* translators: %d: rendered length */
                    __('%d characters', 'valolink-plugin'),
                    $length,
                )),
        );
    }

    /**
     * The settings a translation is missing, listed by name and value.
     *
     * No before-and-after, because there is no "before": every key here is
     * absent on the translation. What a reviewer needs is which settings are
     * about to appear and what they will say.
     */
    private function render_meta_sync(array $change): void
    {
        $source_id = (int) ($change['payload']['source_id'] ?? 0);
        $service = new ChangeService($this->settings, $this->repo, new PostApplier());
        $missing = $service->missing_meta($source_id, (int) $change['target_id']);

        if ($missing === []) {
            echo '<p><strong>' . esc_html__('Nothing left to copy — these were filled in after the proposal.', 'valolink-plugin') . '</strong></p>';

            return;
        }

        printf(
            '<p>%s</p>',
            esc_html(sprintf(
                /* translators: 1: number of settings, 2: source post title. */
                __('Copies %1$d settings this translation is missing from %2$s. Existing values are never overwritten.', 'valolink-plugin'),
                count($missing),
                get_the_title($source_id),
            )),
        );

        echo '<table class="widefat striped"><tbody>';
        foreach ($missing as $key => $value) {
            printf(
                '<tr><td style="width:35%%"><code>%s</code></td><td>%s</td></tr>',
                esc_html($key),
                esc_html(mb_substr(is_scalar($value) ? (string) $value : (string) wp_json_encode($value), 0, 160)),
            );
        }
        echo '</tbody></table>';
    }

    /**
     * A menu, before and after, as an indented tree.
     *
     * This is what the menu work was waiting on rather than the write: a menu
     * diffed as JSON is unreadable and diffed as prose says nothing, so
     * approving one would have been a rubber stamp. Indented label plus target
     * is the form a person actually pictures when they say "the menu", and it
     * makes a repointed item — same label, different destination — visible,
     * which is exactly the edit a translation produces.
     */
    private function render_menu_diff(array $change): void
    {
        $menu_id = (int) $change['target_id'];
        $reader  = new MenuReader();

        if (!wp_get_nav_menu_object($menu_id)) {
            echo '<p><strong>' . esc_html__('That menu no longer exists.', 'valolink-plugin') . '</strong></p>';

            return;
        }

        $current  = $reader->outline($menu_id);
        $proposed = implode("\n", $this->proposed_menu_lines((array) ($change['payload']['items'] ?? []), 0));

        echo '<h4>' . esc_html__('Menu', 'valolink-plugin') . ' <code>'
            . esc_html((string) ($change['payload']['menu_name'] ?? $menu_id)) . '</code></h4>';
        $this->render_field_diff($current, $proposed);
    }

    /**
     * The proposed tree in the same shape MenuReader::outline() produces, so the
     * two sides of the diff are comparable line for line.
     *
     * @return array<int, string>
     */
    private function proposed_menu_lines(array $items, int $depth): array
    {
        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? 'custom');
            $object_id = (int) ($item['object_id'] ?? 0);
            $target = match ($type) {
                'post_type' => sprintf(
                    '%s #%d (%s)',
                    (string) ($item['object'] ?? 'post'),
                    $object_id,
                    (string) (get_permalink($object_id) ?: ''),
                ),
                'taxonomy'          => sprintf('%s #%d', (string) ($item['object'] ?? ''), $object_id),
                'post_type_archive' => sprintf('archive: %s', (string) ($item['object'] ?? '')),
                default             => (string) ($item['url'] ?? ''),
            };

            // Same separator MenuReader::outline() uses, so both sides of the
            // diff line up character for character.
            $lines[] = str_repeat('    ', $depth) . (string) ($item['label'] ?? '') . '  →  ' . $target;

            if (!empty($item['children']) && is_array($item['children'])) {
                $lines = array_merge($lines, $this->proposed_menu_lines($item['children'], $depth + 1));
            }
        }

        return $lines;
    }

    /**
     * Block-level and structural changes.
     *
     * A whole-document diff of a nested GenerateBlocks page is unreadable —
     * that is the whole reason blocks are addressable — so each action is shown
     * at the scope it actually operates on. The two structural actions a text
     * diff cannot express get the block outline before and after as well, which
     * is the same tree-diff the deferred menu work is waiting on.
     */
    private function render_block_diff(array $change, string $current, string $proposed): void
    {
        $reader = new BlockReader();
        $path   = (string) ($change['payload']['path'] ?? '');
        $anchor = $reader->get_at($current, $path);
        $name   = (string) ($change['payload']['block_name'] ?? ($anchor['name'] ?? '?'));

        switch ((string) $change['action']) {
            case ChangeRepository::ACTION_UPDATE_TEXT:
            case ChangeRepository::ACTION_UPDATE_BLOCK:
                // Diff the block as it will actually end up rather than the
                // payload. update_text carries only the inner text, so showing
                // that would hide the wrapper the reviewer is being asked to
                // trust is untouched.
                printf(
                    '<h4>%s <code>%s</code></h4>',
                    esc_html__('Block', 'valolink-plugin'),
                    esc_html($name . ' @ ' . $path),
                );
                $this->render_field_diff(
                    (string) ($anchor['html'] ?? ''),
                    (string) ($reader->get_at($proposed, $path)['html'] ?? ''),
                );

                return;

            case ChangeRepository::ACTION_INSERT_BLOCK:
                if ($path === '') {
                    printf(
                        '<h4>%s</h4>',
                        esc_html(sprintf(
                            /* translators: %s: "start" or "end". */
                            __('Insert a block at the %s of the document', 'valolink-plugin'),
                            (string) ($change['payload']['position'] ?? 'end'),
                        )),
                    );
                } else {
                    printf(
                        '<h4>%s</h4>',
                        esc_html(sprintf(
                            /* translators: 1: before or after, 2: block name, 3: block path. */
                            __('Insert a block %1$s %2$s @ %3$s', 'valolink-plugin'),
                            (string) ($change['payload']['position'] ?? 'after'),
                            $name,
                            $path,
                        )),
                    );
                }
                $this->render_field_diff('', (string) ($change['payload']['markup'] ?? ''));
                break;

            case ChangeRepository::ACTION_DELETE_BLOCK:
                printf(
                    '<h4>%s <code>%s</code></h4>',
                    esc_html__('Delete block', 'valolink-plugin'),
                    esc_html($name . ' @ ' . $path),
                );
                $this->render_field_diff((string) ($anchor['html'] ?? ''), '');
                break;

            default:
                printf(
                    '<h4>%s</h4>',
                    esc_html(sprintf(
                        /* translators: 1: block name, 2: source path, 3: before or after, 4: destination path. */
                        __('Move %1$s from %2$s to %3$s %4$s', 'valolink-plugin'),
                        $name,
                        $path,
                        (string) ($change['payload']['position'] ?? 'after'),
                        (string) ($change['payload']['target_path'] ?? '?'),
                    )),
                );
                break;
        }

        // Folded by default: the outline of a GenerateBlocks page runs to a
        // hundred lines, and the line that moved is what the block diff above
        // already shows. It is here for the reviewer who wants to check where
        // in the document that block sits, not for every glance at the queue.
        echo '<details style="margin:1em 0;">';
        echo '<summary style="cursor:pointer;font-weight:600;">'
            . esc_html__('Block outline (before and after)', 'valolink-plugin')
            . '</summary>';
        $this->render_field_diff($this->outline($reader, $current), $this->outline($reader, $proposed));
        echo '</details>';
    }

    /**
     * The block tree as indented plain text, for diffing.
     *
     * Deliberately without paths: an insert or delete renumbers every later
     * sibling, so including them would mark the whole rest of the document as
     * changed and bury the one line that actually moved.
     */
    private function outline(BlockReader $reader, string $content): string
    {
        $lines = [];
        foreach (($reader->flatten($content)['blocks'] ?? []) as $block) {
            $text = trim((string) ($block['text'] ?? ''));
            $lines[] = str_repeat('    ', (int) ($block['depth'] ?? 0))
                . (string) ($block['name'] ?? '?')
                . ($text !== '' ? ' — ' . wp_trim_words($text, 8, '…') : '');
        }

        return implode("\n", $lines);
    }

    /**
     * Post types that are records rather than content. Still offered — a test
     * site may want everything ticked — but never without saying what ticking
     * one hands an agent.
     *
     * @return array<string, string> slug => warning
     */
    private static function sensitive_post_types(): array
    {
        return [
            'shop_order'        => __('Customer data. Orders carry customers\' notes, and a status or title change through Accesslink bypasses WooCommerce\'s order handling.', 'valolink-plugin'),
            'shop_subscription' => __('Customer data. Subscriptions carry customers\' notes and billing schedules, and edits bypass WooCommerce\'s own handling.', 'valolink-plugin'),
            'shop_coupon'       => __('Coupon codes are live discounts: the agent can read every code, and a title change renames one.', 'valolink-plugin'),
        ];
    }

    /**
     * What the product is and costs right now, and — when the change touches
     * price, stock or SKU — a plain statement that approving it changes what
     * customers pay or can order.
     */
    private function render_product_context(array $change): void
    {
        $line     = (new ProductApplier())->describe((int) $change['target_id']);
        $commerce = array_intersect(
            array_keys((array) ($change['payload']['fields'] ?? [])),
            ProductApplier::COMMERCE_FIELDS,
        );

        if ($commerce !== []) {
            printf(
                '<p class="notice notice-warning" style="padding:.5em 1em;margin:0 0 1em;">%s%s</p>',
                esc_html__('WooCommerce product. This changes what customers pay or can order, from the moment it is approved.', 'valolink-plugin'),
                $line !== '' ? '<br><code>' . esc_html($line) . '</code>' : '',
            );

            return;
        }

        if ($line !== '') {
            printf(
                '<p class="description">%s <code>%s</code></p>',
                esc_html__('WooCommerce product:', 'valolink-plugin'),
                esc_html($line),
            );
        }
    }

    /**
     * A price as the shop will print it, beside the old one and the change in
     * per cent. A misplaced decimal point is the mistake that matters here, and
     * "790" against "79.00" in a text diff hides it where "+900 %" does not.
     */
    private function render_price_preview(string $current, string $proposed): void
    {
        if ($proposed === '') {
            if ($current !== '') {
                echo '<p class="description">' . esc_html__('Removed — the sale ends and the regular price applies.', 'valolink-plugin') . '</p>';
            }

            return;
        }

        $line = sprintf(
            /* translators: %s: formatted price */
            __('Shows as %s', 'valolink-plugin'),
            ProductApplier::money($proposed),
        );
        $warn = false;
        if ($current !== '' && (float) $current > 0) {
            $percent = ((float) $proposed - (float) $current) / (float) $current * 100;
            $line .= sprintf(
                /* translators: 1: formatted old price, 2: signed percentage change */
                __(' — was %1$s, %2$s %%', 'valolink-plugin'),
                ProductApplier::money($current),
                ($percent > 0 ? '+' : '') . number_format_i18n($percent, 1),
            );
            $warn = abs($percent) >= 50;
        }

        printf(
            '<p class="description">%s%s</p>',
            esc_html($line),
            $warn
                ? ' <strong style="color:#b32d2e;">' . esc_html__('A change of half or more — check the decimal point.', 'valolink-plugin') . '</strong>'
                : '',
        );
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
        $types = array_map('strval', (array) $this->settings->get_module_setting(
            AccesslinkModule::MODULE_ID,
            'allowed_post_types',
            ['post', 'page'],
        ));
        // Every type with an admin UI is a candidate — that is what an operator
        // can find in wp-admin and reason about. Attachments are excluded
        // because Accesslink cannot write them (see the roadmap). A saved type
        // whose plugin is currently inactive stays listed and checked, so
        // saving the form does not silently drop it.
        $candidates = [];
        foreach (get_post_types(['show_ui' => true], 'objects') as $slug => $object) {
            if ($slug === 'attachment') {
                continue;
            }
            $candidates[(string) $slug] = (string) ($object->labels->name ?? $slug);
        }
        foreach ($types as $slug) {
            if (!isset($candidates[$slug])) {
                $candidates[$slug] = sprintf(
                    /* translators: %s: post type slug */
                    __('%s (not registered right now)', 'valolink-plugin'),
                    $slug,
                );
            }
        }
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
                        <?php if ($key !== '') : ?>
                            <?php
                            // "accesslink <host> <key>" on the clipboard is the shape the operator's
                            // shell function (alkey) recognises and saves into majorlink's .env.
                            $clip = 'accesslink ' . (string) wp_parse_url(home_url(), PHP_URL_HOST) . ' ' . $key;
                            ?>
                            <button type="button" class="button button-small" style="margin-left:.5em"
                                    data-copy="<?php echo esc_attr($clip); ?>"
                                    onclick="navigator.clipboard.writeText(this.dataset.copy).then(() => { this.textContent = '<?php echo esc_js(__('Copied', 'valolink-plugin')); ?>'; });">
                                <?php esc_html_e('Copy for majorlink', 'valolink-plugin'); ?>
                            </button>
                        <?php endif; ?>
                        <p class="description">
                            <?php esc_html_e('Propose-only. This key cannot approve anything — approving needs a logged-in user who can publish.', 'valolink-plugin'); ?>
                            <?php if ($key !== '') : ?>
                                <?php esc_html_e('Copy for majorlink puts the host and the key on the clipboard; run alkey in a terminal to save them.', 'valolink-plugin'); ?>
                            <?php endif; ?>
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
                    <th scope="row"><?php esc_html_e('Allow menu edits', 'valolink-plugin'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="allow_menu_edits" value="1"
                                <?php checked((bool) $this->settings->get_module_setting(
                                    AccesslinkModule::MODULE_ID,
                                    'allow_menu_edits',
                                    false,
                                )); ?>>
                            <?php esc_html_e('Let agents propose changes to navigation menus', 'valolink-plugin'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Off by default. A menu is site structure rather than content, and a proposal replaces the whole item tree — read the before-and-after in the queue before approving one.', 'valolink-plugin'); ?>
                        </p>
                    </td>
                </tr>
                <?php if (ProductApplier::available()) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Allow price and stock edits', 'valolink-plugin'); ?></th>
                    <td>
                        <?php // Tells the handler this field was on the form, so saving
                              // settings while WooCommerce is inactive keeps the value. ?>
                        <input type="hidden" name="commerce_field" value="1">
                        <label>
                            <input type="checkbox" name="allow_commerce_edits" value="1"
                                <?php checked((bool) $this->settings->get_module_setting(
                                    AccesslinkModule::MODULE_ID,
                                    'allow_commerce_edits',
                                    false,
                                )); ?>>
                            <?php esc_html_e('Let agents propose prices, sales, stock and SKUs on WooCommerce products', 'valolink-plugin'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Off by default. Descriptions, product categories and visibility need only the product type ticked under Allowed post types; these fields change what customers pay and can order, from the moment a change is approved.', 'valolink-plugin'); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Notify on new changes', 'valolink-plugin'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="notify_enabled" value="1" <?php
                                checked((bool) $this->settings->get_module_setting(
                                    AccesslinkModule::MODULE_ID, 'notify_enabled', true,
                                )); ?>>
                            <?php esc_html_e('Email when an agent files a change', 'valolink-plugin'); ?>
                        </label>
                        <p>
                            <input type="text" class="regular-text" name="notify_emails"
                                   value="<?php echo esc_attr((string) $this->settings->get_module_setting(
                                       AccesslinkModule::MODULE_ID, 'notify_emails', '',
                                   )); ?>"
                                   placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                        </p>
                        <p class="description">
                            <?php esc_html_e('Comma-separated; the site admin address is used when blank. At most one mail per 15 minutes however many changes arrive. A notice also appears in wp-admin regardless, in case mail is unreliable.', 'valolink-plugin'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Allowed post types', 'valolink-plugin'); ?></th>
                    <td>
                        <fieldset>
                            <?php $sensitive = self::sensitive_post_types(); ?>
                            <?php foreach ($candidates as $slug => $label) : ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox" name="allowed_post_types[]"
                                           value="<?php echo esc_attr($slug); ?>"
                                        <?php checked(in_array($slug, $types, true)); ?>>
                                    <?php echo esc_html($label); ?>
                                    <code><?php echo esc_html($slug); ?></code>
                                    <?php if (isset($sensitive[$slug])) : ?>
                                        <br><span style="color:#b32d2e;margin-left:1.8em;">&#9888; <?php echo esc_html($sensitive[$slug]); ?></span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('Governs both what agents may read and what they may propose. With none ticked, posts and pages are allowed.', 'valolink-plugin'); ?>
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
