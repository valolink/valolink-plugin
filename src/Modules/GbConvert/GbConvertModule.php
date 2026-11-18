<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\GbConvert;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

/**
 * Converts GenerateBlocks v1 blocks (container, grid, headline, button, image,
 * and Pro's v1 accordion) to v2 blocks (element, text, media, Pro accordion).
 *
 * The conversion runs in the block editor, through GenerateBlocks' own block
 * transforms (assets/gb-convert/editor.js): the v1→v2 style mapping lives in
 * GB's JavaScript and v2 blocks build their `css` attribute only while the
 * editor renders them, so a PHP rewrite of post_content would have to copy both.
 * The admin page lists what has v1 blocks, opens a post converted but unsaved
 * (preview), or converts and saves posts one after another in a hidden editor
 * iframe (bulk). The post's v1 content is copied to post meta first, once, and
 * Restore puts it back.
 *
 * Left as v1, and listed: query loops (no transform), button containers (no
 * transform), dynamic data (the transforms drop it), global styles, the legacy
 * inner-container layout, shape dividers, Google fonts, tabs. Built on GB 2.5.1 /
 * Pro 2.7.1, tested on valolink.fi staging 2026-11-18.
 */
final class GbConvertModule implements Module
{
    public const MODULE_ID      = 'gb_convert';
    public const SUBPAGE_SLUG   = 'valolink-gb-convert';
    public const OPEN_ACTION    = 'valolink_gb_convert_open';
    public const RESTORE_ACTION = 'valolink_gb_convert_restore';
    public const NONCE          = 'valolink_gb_convert';
    public const QUERY_ARG      = 'valolink_gb_convert';
    public const META_BACKUP    = '_valolink_gb_v1_content';
    public const META_TIME      = '_valolink_gb_v1_time';

    /** v1 block names; the accordion is a container with variantRole "accordion". */
    public const V1_BLOCKS = [
        'generateblocks/container',
        'generateblocks/grid',
        'generateblocks/headline',
        'generateblocks/button',
        'generateblocks/button-container',
        'generateblocks/image',
        'generateblocks/query-loop',
    ];

    private const ACCORDION_ROLES = ['accordion', 'accordion-item', 'accordion-toggle', 'accordion-content'];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function should_load(Context $context): bool
    {
        return $context->is_admin;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_page']);
        add_action('admin_post_' . self::OPEN_ACTION, [$this, 'handle_open']);
        add_action('admin_post_' . self::RESTORE_ACTION, [$this, 'handle_restore']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor']);
    }

    public function uninstall(): void
    {
        delete_post_meta_by_key(self::META_BACKUP);
        delete_post_meta_by_key(self::META_TIME);
        $this->settings->forget_module(self::MODULE_ID);
    }

    public function add_page(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('GenerateBlocks v2', 'valolink-plugin'),
            __('GenerateBlocks v2', 'valolink-plugin'),
            SettingsPage::CAPABILITY,
            self::SUBPAGE_SLUG,
            [$this, 'render_page'],
        );
    }

    // -------------------------------------------------------------------------
    // Scan
    // -------------------------------------------------------------------------

    /**
     * Posts that hold v1 blocks or a backup, with counts.
     *
     * @return list<array{id:int, title:string, type:string, status:string, v1:array<string,int>, stays:array<string,int>, backup:string, editor:bool}>
     */
    public static function scan(): array
    {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type NOT IN ('revision', 'customize_changeset', 'oembed_cache')
               AND post_status NOT IN ('trash', 'auto-draft', 'inherit')
               AND post_content LIKE '%<!-- wp:generateblocks/%'
             UNION
             SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . self::META_BACKUP . "'"
        );

        $rows = [];
        foreach (array_map('intval', $ids) as $id) {
            $post = get_post($id);
            if (!$post || in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)) {
                continue;
            }
            $v1 = $stays = [];
            self::count(parse_blocks($post->post_content), $v1, $stays, false);
            $backup = (string) get_post_meta($id, self::META_TIME, true);
            if (!$v1 && $backup === '') {
                continue;
            }
            $rows[] = [
                'id'     => $id,
                'title'  => $post->post_title !== '' ? $post->post_title : '#' . $id,
                'type'   => $post->post_type,
                'status' => $post->post_status,
                'v1'     => $v1,
                'stays'  => $stays,
                'backup' => $backup,
                'editor' => function_exists('use_block_editor_for_post') && use_block_editor_for_post($post),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['type'], $a['status'] !== 'publish', $a['title']] <=> [$b['type'], $b['status'] !== 'publish', $b['title']]);

        return $rows;
    }

    /**
     * Counts v1 blocks by short name, and why a block will stay v1 — the same
     * rules editor.js applies.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, int> $v1
     * @param array<string, int> $stays
     */
    private static function count(array $blocks, array &$v1, array &$stays, bool $in_loop): void
    {
        foreach ($blocks as $block) {
            $name  = (string) ($block['blockName'] ?? '');
            $inner = $in_loop || $name === 'generateblocks/query-loop';
            if (in_array($name, self::V1_BLOCKS, true)) {
                $short      = substr($name, strlen('generateblocks/'));
                $v1[$short] = ($v1[$short] ?? 0) + 1;
                $reason     = self::stays_reason($name, (array) ($block['attrs'] ?? []), $inner);
                if ($reason !== null) {
                    $stays[$reason] = ($stays[$reason] ?? 0) + 1;
                }
            }
            if (!empty($block['innerBlocks'])) {
                self::count($block['innerBlocks'], $v1, $stays, $inner);
            }
        }
    }

    /** @param array<string, mixed> $a */
    private static function stays_reason(string $name, array $a, bool $in_loop): ?string
    {
        $role = (string) ($a['variantRole'] ?? '');
        return match (true) {
            $in_loop                                                       => __('query loop', 'valolink-plugin'),
            $name === 'generateblocks/button-container'                    => __('button container', 'valolink-plugin'),
            !empty($a['useDynamicData']) || !empty($a['dynamicContentType']) || !empty($a['dynamicLinkType'])
                                                                           => __('dynamic data', 'valolink-plugin'),
            !empty($a['useGlobalStyle']) || !empty($a['isGlobalStyle'])    => __('global style', 'valolink-plugin'),
            $role !== '' && !in_array($role, self::ACCORDION_ROLES, true)  => sprintf(__('variant "%s"', 'valolink-plugin'), $role),
            !empty($a['googleFont'])                                       => __('Google font', 'valolink-plugin'),
            !empty($a['shapeDividers'])                                    => __('shape divider', 'valolink-plugin'),
            $name === 'generateblocks/container' && (!empty($a['useInnerContainer']) || (int) ($a['blockVersion'] ?? 0) < 3)
                                                                           => __('legacy layout', 'valolink-plugin'),
            default                                                        => null,
        };
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /** Backs the post up (first time only) and opens it in the editor with the converter. */
    public function handle_open(): void
    {
        $id   = $this->guarded_post_id();
        $mode = ($_GET['mode'] ?? '') === 'run' ? 'run' : 'preview';
        $post = get_post($id);
        if (get_post_meta($id, self::META_BACKUP, true) === '') {
            // wp_slash: update_post_meta unslashes, and block JSON carries backslashes.
            update_post_meta($id, self::META_BACKUP, wp_slash($post->post_content));
            update_post_meta($id, self::META_TIME, current_time('mysql'));
        }
        $url = add_query_arg(self::QUERY_ARG, $mode, (string) get_edit_post_link($id, 'raw'));
        wp_safe_redirect($url);
        exit;
    }

    public function handle_restore(): void
    {
        $id     = $this->guarded_post_id();
        $backup = get_post_meta($id, self::META_BACKUP, true);
        $done   = '0';
        if (is_string($backup) && $backup !== '') {
            $result = wp_update_post(['ID' => $id, 'post_content' => wp_slash($backup)], true);
            if (!is_wp_error($result)) {
                delete_post_meta($id, self::META_BACKUP);
                delete_post_meta($id, self::META_TIME);
                $done = '1';
            }
        }
        wp_safe_redirect(add_query_arg(['page' => self::SUBPAGE_SLUG, 'restored' => $done], admin_url('admin.php')));
        exit;
    }

    private function guarded_post_id(): int
    {
        check_admin_referer(self::NONCE);
        $id = absint($_GET['post'] ?? 0);
        if (!$id || !get_post($id) || !current_user_can(SettingsPage::CAPABILITY) || !current_user_can('edit_post', $id)) {
            wp_die(esc_html__('You cannot convert this post.', 'valolink-plugin'), '', ['response' => 403]);
        }

        return $id;
    }

    public static function action_url(string $action, int $id, array $args = []): string
    {
        return wp_nonce_url(add_query_arg(['action' => $action, 'post' => $id] + $args, admin_url('admin-post.php')), self::NONCE);
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public function enqueue_editor(): void
    {
        $mode = sanitize_key((string) ($_GET[self::QUERY_ARG] ?? ''));
        if (!in_array($mode, ['preview', 'run'], true) || !current_user_can(SettingsPage::CAPABILITY)) {
            return;
        }
        $handle = 'valolink-gb-convert-editor';
        wp_enqueue_script($handle, self::asset_url('editor.js'), ['wp-blocks', 'wp-data', 'wp-editor', 'wp-block-editor', 'wp-notices', 'wp-dom-ready'], self::version(), true);
        wp_add_inline_script($handle, 'window.valolinkGbConvert = ' . wp_json_encode([
            'mode'     => $mode,
            'adminUrl' => add_query_arg('page', self::SUBPAGE_SLUG, admin_url('admin.php')),
        ]) . ';', 'before');
    }

    public function enqueue_admin(string $hook): void
    {
        if (!str_ends_with($hook, '_page_' . self::SUBPAGE_SLUG)) {
            return;
        }
        wp_enqueue_script('valolink-gb-convert-admin', self::asset_url('admin.js'), [], self::version(), true);
    }

    private static function asset_url(string $file): string
    {
        $base = defined('VALOLINK_PLUGIN_URL') ? VALOLINK_PLUGIN_URL : plugin_dir_url(dirname(__DIR__, 3) . '/valolink-plugin.php');

        return $base . 'assets/gb-convert/' . $file;
    }

    private static function version(): string
    {
        return defined('VALOLINK_PLUGIN_VERSION') ? VALOLINK_PLUGIN_VERSION : '0';
    }

    // -------------------------------------------------------------------------
    // Page
    // -------------------------------------------------------------------------

    public function render_page(): void
    {
        if (!current_user_can(SettingsPage::CAPABILITY)) {
            return;
        }
        $gb   = defined('GENERATEBLOCKS_VERSION') ? GENERATEBLOCKS_VERSION : null;
        $pro  = defined('GENERATEBLOCKS_PRO_VERSION') ? GENERATEBLOCKS_PRO_VERSION : null;
        $rows = self::scan();
        $left = array_sum(array_map(static fn (array $r): int => array_sum($r['v1']), $rows));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('GenerateBlocks v1 → v2', 'valolink-plugin'); ?></h1>

            <?php if (isset($_GET['restored'])) : ?>
                <div class="notice notice-<?php echo $_GET['restored'] === '1' ? 'success' : 'error'; ?> is-dismissible"><p>
                    <?php echo $_GET['restored'] === '1' ? esc_html__('v1 content restored.', 'valolink-plugin') : esc_html__('Restore failed.', 'valolink-plugin'); ?>
                </p></div>
            <?php endif; ?>

            <?php if ($gb === null || version_compare($gb, '2.0', '<')) : ?>
                <div class="notice notice-error"><p><?php esc_html_e('Needs GenerateBlocks 2.0 or newer active: the conversion uses its block transforms.', 'valolink-plugin'); ?></p></div>
            <?php endif; ?>

            <p>
                <?php echo esc_html(sprintf(
                    /* translators: 1: GB version, 2: GB Pro version, 3: v1 block count, 4: post count */
                    __('GenerateBlocks %1$s, Pro %2$s. %3$d v1 blocks in %4$d posts.', 'valolink-plugin'),
                    $gb ?? '—',
                    $pro ?? '—',
                    $left,
                    count(array_filter($rows, static fn (array $r): bool => (bool) $r['v1'])),
                )); ?>
            </p>
            <p class="description">
                <?php esc_html_e('Preview opens the post in the editor converted but unsaved: check it there or with Preview, then Save/Update to keep it, or leave without saving. Convert saves straight away, one post after another in a hidden editor. Before either, the post\'s v1 content is copied aside once; Restore puts it back. Blocks listed under "Stays v1" are left as they are.', 'valolink-plugin'); ?>
            </p>

            <p>
                <button type="button" class="button button-primary" id="vgc-run" disabled><?php esc_html_e('Convert selected', 'valolink-plugin'); ?></button>
                <button type="button" class="button" id="vgc-stop" style="display:none"><?php esc_html_e('Stop after this post', 'valolink-plugin'); ?></button>
                <span id="vgc-status" style="margin-left:1em"></span>
            </p>

            <table class="widefat striped" id="vgc-table">
                <thead><tr>
                    <td class="check-column"><input type="checkbox" id="vgc-all"></td>
                    <th><?php esc_html_e('Post', 'valolink-plugin'); ?></th>
                    <th><?php esc_html_e('Type', 'valolink-plugin'); ?></th>
                    <th><?php esc_html_e('v1 blocks', 'valolink-plugin'); ?></th>
                    <th><?php esc_html_e('Stays v1', 'valolink-plugin'); ?></th>
                    <th><?php esc_html_e('Result', 'valolink-plugin'); ?></th>
                    <th><?php esc_html_e('Actions', 'valolink-plugin'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!$rows) : ?>
                    <tr><td colspan="7"><?php esc_html_e('No v1 GenerateBlocks blocks left.', 'valolink-plugin'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r) :
                    $can = $r['v1'] && $r['editor'];
                    $fmt = static fn (array $c): string => implode(', ', array_map(static fn ($k, $v): string => "$k $v", array_keys($c), $c));
                    ?>
                    <tr data-id="<?php echo (int) $r['id']; ?>" data-run="<?php echo esc_url(self::action_url(self::OPEN_ACTION, $r['id'], ['mode' => 'run'])); ?>">
                        <th class="check-column"><?php if ($can) : ?><input type="checkbox" class="vgc-pick"><?php endif; ?></th>
                        <td>
                            <strong><?php echo esc_html($r['title']); ?></strong>
                            <span class="description">#<?php echo (int) $r['id']; ?><?php echo $r['status'] !== 'publish' ? ' · ' . esc_html($r['status']) : ''; ?></span>
                            <?php if ($r['type'] !== 'wp_block' && $r['type'] !== 'gp_elements' && ($link = get_permalink($r['id']))) : ?>
                                · <a href="<?php echo esc_url($link); ?>" target="_blank"><?php esc_html_e('view', 'valolink-plugin'); ?></a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($r['type']); ?></td>
                        <td><?php echo esc_html($fmt($r['v1']) ?: '—'); ?></td>
                        <td><?php echo esc_html($fmt($r['stays']) ?: '—'); ?><?php echo !$r['editor'] ? esc_html__(' (no block editor)', 'valolink-plugin') : ''; ?></td>
                        <td class="vgc-result"><?php echo $r['backup'] !== '' ? esc_html(sprintf(__('backup %s', 'valolink-plugin'), $r['backup'])) : ''; ?></td>
                        <td>
                            <?php if ($can) : ?>
                                <a class="button button-small" href="<?php echo esc_url(self::action_url(self::OPEN_ACTION, $r['id'], ['mode' => 'preview'])); ?>"><?php esc_html_e('Preview', 'valolink-plugin'); ?></a>
                            <?php endif; ?>
                            <?php if ($r['backup'] !== '') : ?>
                                <a class="button button-small vgc-restore" href="<?php echo esc_url(self::action_url(self::RESTORE_ACTION, $r['id'])); ?>"
                                   onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Put the v1 content back? Edits made since the conversion are lost.', 'valolink-plugin'))); ?>)"><?php esc_html_e('Restore v1', 'valolink-plugin'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <iframe id="vgc-frame" title="converter" style="position:absolute;left:-10000px;width:1400px;height:900px" aria-hidden="true"></iframe>
        </div>
        <?php
    }
}
