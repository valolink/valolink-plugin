<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Logging;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Settings;

/**
 * Admin viewer for the event log. Lives under Valolink → Event Log.
 *
 * Default "folded" view groups similar entries by (event, level, user_login, ip, hour bucket)
 * and shows a count + first/last-seen range. Single-row groups display normally. A "raw" view
 * shows every entry. Delete buttons remove single rows, folded groups, or all matching the
 * current filter. Retention is configured at the bottom of the page.
 */
final class LogViewerPage
{
    public const MENU_SLUG        = 'valolink-plugin-logs';
    public const CAPABILITY       = 'manage_options';
    public const DELETE_ACTION    = 'valolink_log_delete';
    public const CLEAR_ACTION     = 'valolink_log_clear';
    public const RETENTION_ACTION = 'valolink_log_save_retention';

    private const DEFAULT_RETENTION_DAYS = 90;
    private const PER_PAGE     = 50;
    /**
     * SQL DATE_FORMAT mask for the folding bucket. Hour-resolution.
     * Percent signs are doubled because this expression is passed through $wpdb->prepare,
     * which would otherwise treat %Y/%m/%d/%H as its own placeholders.
     */
    private const BUCKET_FMT   = '%%Y-%%m-%%d %%H:00:00';
    private const BUCKET_SECS  = 3600;

    public function __construct(private readonly Settings $settings) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu'], 12); // after the main Valolink page
        add_action('admin_post_' . self::DELETE_ACTION,    [$this, 'handle_delete']);
        add_action('admin_post_' . self::CLEAR_ACTION,     [$this, 'handle_clear']);
        add_action('admin_post_' . self::RETENTION_ACTION, [$this, 'handle_set_retention']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Event Log', 'valolink-plugin'),
            __('Event Log', 'valolink-plugin'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        global $wpdb;
        $table = LogTable::table_name();

        // ---- filters ----
        $f = $this->read_filters();
        [$where_sql, $where_args] = $this->build_where($f);

        $view  = $f['view'];
        $paged = max(1, (int) ($_GET['paged'] ?? 1));

        // ---- facets for the event dropdown (always raw count) ----
        $facets = $wpdb->get_results(
            "SELECT event, COUNT(*) AS c FROM $table GROUP BY event ORDER BY c DESC LIMIT 100",
            ARRAY_A,
        );

        $base_url = admin_url('admin.php?page=' . self::MENU_SLUG);
        $keep     = $this->preserved_args($f);

        // ---- query rows + total ----
        $offset = ($paged - 1) * self::PER_PAGE;
        if ($view === 'raw') {
            [$rows, $total] = $this->query_raw($table, $where_sql, $where_args, $offset);
        } else {
            [$rows, $total] = $this->query_folded($table, $where_sql, $where_args, $offset);
        }

        $total_pages = max(1, (int) ceil($total / self::PER_PAGE));

        $this->render_html($rows, $total, $total_pages, $paged, $view, $facets, $f, $keep, $base_url);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $facets
     * @param array<string, mixed>             $f
     * @param array<string, mixed>             $keep
     */
    private function render_html(
        array $rows,
        int $total,
        int $total_pages,
        int $paged,
        string $view,
        array $facets,
        array $f,
        array $keep,
        string $base_url,
    ): void {
        $toggle_view  = $view === 'raw' ? 'folded' : 'raw';
        $toggle_url   = add_query_arg(array_merge($keep, ['view' => $toggle_view]), $base_url);
        $clear_args   = array_filter($keep, static fn($v) => $v !== '' && $v !== null);

        $confirm_filter = esc_js(__('Delete every log entry matching the current filter? This cannot be undone.', 'valolink-plugin'));
        $confirm_all    = esc_js(__('Delete ALL log entries? This cannot be undone.', 'valolink-plugin'));
        $confirm_row    = esc_js(__('Delete this entry?', 'valolink-plugin'));
        $confirm_group  = esc_js(__('Delete every entry in this group?', 'valolink-plugin'));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Event Log', 'valolink-plugin'); ?></h1>
            <a href="<?php echo esc_url($toggle_url); ?>" class="page-title-action">
                <?php echo $view === 'raw'
                    ? esc_html__('Folded view', 'valolink-plugin')
                    : esc_html__('Raw view', 'valolink-plugin'); ?>
            </a>

            <form method="get" class="valolink-log-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
                <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">

                <select name="event">
                    <option value=""><?php esc_html_e('All events', 'valolink-plugin'); ?></option>
                    <?php foreach ($facets as $facet) : ?>
                        <option value="<?php echo esc_attr($facet['event']); ?>" <?php selected($f['event'], $facet['event']); ?>>
                            <?php echo esc_html($facet['event']) . ' (' . (int) $facet['c'] . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="user"
                       value="<?php echo esc_attr($f['user']); ?>"
                       placeholder="<?php esc_attr_e('User login', 'valolink-plugin'); ?>"
                       size="14">

                <input type="text" name="ip"
                       value="<?php echo esc_attr($f['ip']); ?>"
                       placeholder="<?php esc_attr_e('IP', 'valolink-plugin'); ?>"
                       size="14">

                <select name="level">
                    <option value=""><?php esc_html_e('Any level', 'valolink-plugin'); ?></option>
                    <option value="info"    <?php selected($f['level'], 'info');    ?>>info</option>
                    <option value="warning" <?php selected($f['level'], 'warning'); ?>>warning</option>
                    <option value="error"   <?php selected($f['level'], 'error');   ?>>error</option>
                </select>

                <input type="datetime-local" name="after"
                       value="<?php echo esc_attr($this->utc_to_form($f['after'])); ?>"
                       title="<?php esc_attr_e('After (UTC)', 'valolink-plugin'); ?>">
                <input type="datetime-local" name="before"
                       value="<?php echo esc_attr($this->utc_to_form($f['before'])); ?>"
                       title="<?php esc_attr_e('Before (UTC)', 'valolink-plugin'); ?>">

                <button type="submit" class="button"><?php esc_html_e('Filter', 'valolink-plugin'); ?></button>
                <a class="button" href="<?php echo esc_url(add_query_arg('view', $view, $base_url)); ?>"><?php esc_html_e('Clear', 'valolink-plugin'); ?></a>

                <span class="valolink-log-summary">
                    <?php
                    $label = $view === 'raw'
                        ? _n('%d entry', '%d entries', $total, 'valolink-plugin')
                        : _n('%d group', '%d groups', $total, 'valolink-plugin');
                    printf(esc_html($label), $total);
                    ?>
                </span>
            </form>

            <div class="valolink-log-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::CLEAR_ACTION); ?>">
                    <input type="hidden" name="scope"  value="filter">
                    <?php foreach ($clear_args as $k => $v) : ?>
                        <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr((string) $v); ?>">
                    <?php endforeach; ?>
                    <?php wp_nonce_field(self::CLEAR_ACTION); ?>
                    <button type="submit" class="button button-secondary"
                            onclick="return confirm('<?php echo $confirm_filter; ?>');"
                            <?php disabled(empty($clear_args)); ?>>
                        <?php esc_html_e('Delete matching filter', 'valolink-plugin'); ?>
                    </button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::CLEAR_ACTION); ?>">
                    <input type="hidden" name="scope"  value="all">
                    <?php wp_nonce_field(self::CLEAR_ACTION); ?>
                    <button type="submit" class="button button-link-delete"
                            onclick="return confirm('<?php echo $confirm_all; ?>');">
                        <?php esc_html_e('Delete ALL entries', 'valolink-plugin'); ?>
                    </button>
                </form>
            </div>

            <table class="widefat striped valolink-log-table">
                <thead>
                    <tr>
                        <th style="width:170px;"><?php esc_html_e('When', 'valolink-plugin'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Level', 'valolink-plugin'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Event', 'valolink-plugin'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('User', 'valolink-plugin'); ?></th>
                        <th><?php esc_html_e('Message / context', 'valolink-plugin'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('IP', 'valolink-plugin'); ?></th>
                        <th style="width:64px;text-align:right;"><?php esc_html_e('×', 'valolink-plugin'); ?></th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="8"><em><?php esc_html_e('No entries.', 'valolink-plugin'); ?></em></td></tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row) :
                        $is_folded = !empty($row['__folded']);
                        $count     = (int) ($row['count'] ?? 1);
                        ?>
                        <tr>
                            <td>
                                <?php if ($is_folded && $count > 1) : ?>
                                    <span title="<?php echo esc_attr(sprintf(
                                        __('First: %1$s · Last: %2$s', 'valolink-plugin'),
                                        get_date_from_gmt($row['first_at']),
                                        get_date_from_gmt($row['last_at']),
                                    )); ?>">
                                        <?php echo esc_html(get_date_from_gmt($row['last_at'])); ?>
                                    </span>
                                <?php else : ?>
                                    <?php echo esc_html(get_date_from_gmt($row['created_at'] ?? $row['last_at'])); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="valolink-level valolink-level-<?php echo esc_attr($row['level']); ?>">
                                    <?php echo esc_html($row['level']); ?>
                                </span>
                            </td>
                            <td><code><?php echo esc_html($row['event']); ?></code></td>
                            <td>
                                <?php if (!empty($row['user_login'])) : ?>
                                    <a href="<?php echo esc_url(add_query_arg([...$keep, 'user' => $row['user_login']], $base_url)); ?>">
                                        <?php echo esc_html($row['user_login']); ?>
                                    </a>
                                <?php else : ?>
                                    <span class="valolink-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($is_folded && $count > 1) :
                                    $expand_args = array_merge($keep, [
                                        'view'   => 'raw',
                                        'event'  => $row['event'],
                                        'level'  => $row['level'],
                                        'user'   => $row['user_login'] ?? '',
                                        'ip'     => $row['ip'] ?? '',
                                        'after'  => $row['bucket_start'],
                                        'before' => $row['bucket_end'],
                                        'paged'  => null,
                                    ]);
                                    ?>
                                    <em><?php
                                        printf(
                                            esc_html(_n('%d occurrence in this hour', '%d occurrences in this hour', $count, 'valolink-plugin')),
                                            $count,
                                        );
                                    ?></em>
                                    &nbsp;<a href="<?php echo esc_url(add_query_arg($expand_args, $base_url)); ?>">
                                        <?php esc_html_e('expand →', 'valolink-plugin'); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html($row['message'] ?? ''); ?>
                                    <?php if (!empty($row['context'])) :
                                        $decoded = json_decode((string) $row['context'], true);
                                        ?>
                                        <details class="valolink-context">
                                            <summary><?php esc_html_e('context', 'valolink-plugin'); ?></summary>
                                            <pre><?php echo esc_html(wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html($row['ip'] ?? '—'); ?></code></td>
                            <td style="text-align:right;">
                                <?php if ($count > 1) : ?>
                                    <strong>×<?php echo (int) $count; ?></strong>
                                <?php else : ?>
                                    <span class="valolink-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::DELETE_ACTION); ?>">
                                    <?php if ($is_folded && $count > 1) : ?>
                                        <input type="hidden" name="mode"         value="group">
                                        <input type="hidden" name="event"        value="<?php echo esc_attr($row['event']); ?>">
                                        <input type="hidden" name="level"        value="<?php echo esc_attr($row['level']); ?>">
                                        <input type="hidden" name="user_login"   value="<?php echo esc_attr((string) ($row['user_login'] ?? '')); ?>">
                                        <input type="hidden" name="user_is_null" value="<?php echo $row['user_login'] === null ? '1' : '0'; ?>">
                                        <input type="hidden" name="ip"           value="<?php echo esc_attr((string) ($row['ip'] ?? '')); ?>">
                                        <input type="hidden" name="ip_is_null"   value="<?php echo $row['ip'] === null ? '1' : '0'; ?>">
                                        <input type="hidden" name="bucket_start" value="<?php echo esc_attr($row['bucket_start']); ?>">
                                        <input type="hidden" name="bucket_end"   value="<?php echo esc_attr($row['bucket_end']); ?>">
                                    <?php else : ?>
                                        <input type="hidden" name="mode" value="row">
                                        <input type="hidden" name="id"   value="<?php echo (int) ($row['id'] ?? $row['last_id']); ?>">
                                    <?php endif; ?>
                                    <?php wp_nonce_field(self::DELETE_ACTION); ?>
                                    <button type="submit" class="button button-small button-link-delete"
                                            onclick="return confirm('<?php echo $is_folded && $count > 1 ? $confirm_group : $confirm_row; ?>');">
                                        <?php esc_html_e('Delete', 'valolink-plugin'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1) : ?>
                <div class="tablenav valolink-log-pagination">
                    <?php if ($paged > 1) : ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg([...$keep, 'paged' => $paged - 1], $base_url)); ?>">
                            ‹ <?php esc_html_e('Previous', 'valolink-plugin'); ?>
                        </a>
                    <?php endif; ?>

                    <span class="valolink-log-page">
                        <?php printf(esc_html__('Page %1$d of %2$d', 'valolink-plugin'), $paged, $total_pages); ?>
                    </span>

                    <?php if ($paged < $total_pages) : ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg([...$keep, 'paged' => $paged + 1], $base_url)); ?>">
                            <?php esc_html_e('Next', 'valolink-plugin'); ?> ›
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <hr style="margin:24px 0 16px;">
            <h2><?php esc_html_e('Retention', 'valolink-plugin'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="valolink-log-retention">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::RETENTION_ACTION); ?>">
                <?php wp_nonce_field(self::RETENTION_ACTION); ?>
                <label for="valolink-log-retention-days">
                    <?php esc_html_e('Keep entries for', 'valolink-plugin'); ?>
                </label>
                <input
                    type="number"
                    id="valolink-log-retention-days"
                    name="retention_days"
                    value="<?php echo esc_attr((string) $this->retention_days()); ?>"
                    min="0"
                    step="1"
                    class="small-text"
                >
                <?php esc_html_e('days', 'valolink-plugin'); ?>
                <button type="submit" class="button"><?php esc_html_e('Save', 'valolink-plugin'); ?></button>
                <span class="description">
                    <?php esc_html_e('Older entries are pruned daily. Set to 0 to keep forever.', 'valolink-plugin'); ?>
                </span>
            </form>

            <style>
                .valolink-log-filters { margin: 16px 0 8px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
                .valolink-log-actions { margin: 0 0 12px; }
                .valolink-log-summary { margin-left: auto; color: #646970; font-size: 12px; }
                .valolink-log-table td { vertical-align: top; }
                .valolink-log-pagination { margin-top: 12px; display: flex; align-items: center; gap: 10px; }
                .valolink-log-page { color: #646970; font-size: 12px; }
                .valolink-muted { color: #999; }
                .valolink-context summary { cursor: pointer; color: #2271b1; font-size: 12px; }
                .valolink-context pre { background: #f6f7f7; border: 1px solid #dcdcde; padding: 8px; margin-top: 4px; font-size: 11px; overflow-x: auto; max-width: 700px; }
                .valolink-level { display: inline-block; padding: 1px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
                .valolink-level-info    { background: #e0f2fe; color: #0369a1; }
                .valolink-level-warning { background: #fef3c7; color: #92400e; }
                .valolink-level-error   { background: #fee2e2; color: #991b1b; }
                .valolink-level-debug   { background: #f3f4f6; color: #4b5563; }
            </style>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Action handlers
    // -------------------------------------------------------------------------

    public function handle_delete(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::DELETE_ACTION);

        global $wpdb;
        $table = LogTable::table_name();
        $mode  = sanitize_text_field(wp_unslash($_POST['mode'] ?? ''));

        if ($mode === 'row') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $wpdb->delete($table, ['id' => $id], ['%d']);
            }
        } elseif ($mode === 'group') {
            $event        = sanitize_text_field(wp_unslash($_POST['event'] ?? ''));
            $level        = sanitize_text_field(wp_unslash($_POST['level'] ?? ''));
            $user_login   = sanitize_user(wp_unslash($_POST['user_login'] ?? ''));
            $user_is_null = ($_POST['user_is_null'] ?? '') === '1';
            $ip           = sanitize_text_field(wp_unslash($_POST['ip'] ?? ''));
            $ip_is_null   = ($_POST['ip_is_null'] ?? '') === '1';
            $bucket_start = sanitize_text_field(wp_unslash($_POST['bucket_start'] ?? ''));
            $bucket_end   = sanitize_text_field(wp_unslash($_POST['bucket_end'] ?? ''));

            if ($event !== '' && $level !== '' && $bucket_start !== '' && $bucket_end !== '') {
                $where = ['event = %s', 'level = %s', 'created_at >= %s', 'created_at < %s'];
                $args  = [$event, $level, $bucket_start, $bucket_end];

                if ($user_is_null) {
                    $where[] = 'user_login IS NULL';
                } else {
                    $where[] = 'user_login = %s';
                    $args[]  = $user_login;
                }
                if ($ip_is_null) {
                    $where[] = 'ip IS NULL';
                } else {
                    $where[] = 'ip = %s';
                    $args[]  = $ip;
                }

                $sql = "DELETE FROM $table WHERE " . implode(' AND ', $where);
                $wpdb->query($wpdb->prepare($sql, $args));
            }
        }

        $this->redirect_back();
    }

    public function handle_clear(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::CLEAR_ACTION);

        global $wpdb;
        $table = LogTable::table_name();
        $scope = sanitize_text_field(wp_unslash($_POST['scope'] ?? ''));

        if ($scope === 'all') {
            $wpdb->query("TRUNCATE TABLE $table");
        } else { // 'filter'
            $f = $this->read_filters_from_post();
            [$where_sql, $where_args] = $this->build_where($f);
            // Refuse a nuke disguised as a filter wipe — require at least one filter set.
            if ($this->has_any_filter($f)) {
                $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE $where_sql", $where_args));
            }
        }

        $this->redirect_back();
    }

    public function handle_set_retention(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'valolink-plugin'), '', ['response' => 403]);
        }
        check_admin_referer(self::RETENTION_ACTION);

        $days = max(0, (int) ($_POST['retention_days'] ?? self::DEFAULT_RETENTION_DAYS));
        $this->settings->set_module_setting(LoggingModule::MODULE_ID, 'retention_days', $days);

        $this->redirect_back();
    }

    public function retention_days(): int
    {
        return (int) $this->settings->get_module_setting(
            LoggingModule::MODULE_ID,
            'retention_days',
            self::DEFAULT_RETENTION_DAYS,
        );
    }

    private function redirect_back(): void
    {
        $back = wp_get_referer() ?: admin_url('admin.php?page=' . self::MENU_SLUG);
        wp_safe_redirect($back);
        exit;
    }

    // -------------------------------------------------------------------------
    // Filter parsing
    // -------------------------------------------------------------------------

    /** @return array<string, string> */
    private function read_filters(): array
    {
        return [
            'view'   => (($_GET['view'] ?? 'folded') === 'raw') ? 'raw' : 'folded',
            'event'  => isset($_GET['event'])  ? sanitize_text_field(wp_unslash((string) $_GET['event']))  : '',
            'user'   => isset($_GET['user'])   ? sanitize_user(wp_unslash((string) $_GET['user']))         : '',
            'ip'     => isset($_GET['ip'])     ? sanitize_text_field(wp_unslash((string) $_GET['ip']))     : '',
            'level'  => isset($_GET['level'])  ? sanitize_text_field(wp_unslash((string) $_GET['level']))  : '',
            'after'  => $this->normalize_datetime((string) ($_GET['after']  ?? '')),
            'before' => $this->normalize_datetime((string) ($_GET['before'] ?? '')),
        ];
    }

    /** @return array<string, string> */
    private function read_filters_from_post(): array
    {
        return [
            'view'   => 'folded',
            'event'  => sanitize_text_field(wp_unslash((string) ($_POST['event']  ?? ''))),
            'user'   => sanitize_user(wp_unslash((string) ($_POST['user']   ?? ''))),
            'ip'     => sanitize_text_field(wp_unslash((string) ($_POST['ip']     ?? ''))),
            'level'  => sanitize_text_field(wp_unslash((string) ($_POST['level']  ?? ''))),
            'after'  => $this->normalize_datetime((string) ($_POST['after']  ?? '')),
            'before' => $this->normalize_datetime((string) ($_POST['before'] ?? '')),
        ];
    }

    /**
     * @param  array<string, string> $f
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function build_where(array $f): array
    {
        $where = ['1=1'];
        $args  = [];
        if ($f['event'] !== '') { $where[] = 'event = %s';      $args[] = $f['event']; }
        if ($f['user']  !== '') { $where[] = 'user_login = %s'; $args[] = $f['user']; }
        if ($f['ip']    !== '') { $where[] = 'ip = %s';         $args[] = $f['ip']; }
        if ($f['level'] !== '') { $where[] = 'level = %s';      $args[] = $f['level']; }
        if ($f['after']  !== '') { $where[] = 'created_at >= %s'; $args[] = $f['after']; }
        if ($f['before'] !== '') { $where[] = 'created_at <  %s'; $args[] = $f['before']; }
        return [implode(' AND ', $where), $args];
    }

    /** @param array<string, string> $f */
    private function has_any_filter(array $f): bool
    {
        foreach (['event', 'user', 'ip', 'level', 'after', 'before'] as $k) {
            if (!empty($f[$k])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, string> $f @return array<string, string> */
    private function preserved_args(array $f): array
    {
        $keep = ['view' => $f['view']];
        foreach (['event', 'user', 'ip', 'level', 'after', 'before'] as $k) {
            if ($f[$k] !== '') {
                $keep[$k] = $f[$k];
            }
        }
        return $keep;
    }

    /** Accept `YYYY-MM-DDTHH:MM` (datetime-local) or `YYYY-MM-DD HH:MM:SS`; normalize to MySQL. */
    private function normalize_datetime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $raw = str_replace('T', ' ', $raw);
        $ts  = strtotime($raw . ' UTC');
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : '';
    }

    private function utc_to_form(string $mysql_utc): string
    {
        if ($mysql_utc === '') {
            return '';
        }
        // datetime-local wants `YYYY-MM-DDTHH:MM`
        return substr(str_replace(' ', 'T', $mysql_utc), 0, 16);
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, mixed> $where_args
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function query_raw(string $table, string $where_sql, array $where_args, int $offset): array
    {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            $where_args
                ? $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE $where_sql", $where_args)
                : "SELECT COUNT(*) FROM $table"
        );

        $row_args = [...$where_args, self::PER_PAGE, $offset];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, created_at, event, level, user_id, user_login, ip, message, context
                 FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d",
                $row_args,
            ),
            ARRAY_A,
        ) ?: [];

        // Normalize for the renderer.
        foreach ($rows as &$r) {
            $r['__folded']     = false;
            $r['count']        = 1;
            $r['last_at']      = $r['created_at'];
        }
        unset($r);

        return [$rows, $total];
    }

    /**
     * @param  array<int, mixed> $where_args
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function query_folded(string $table, string $where_sql, array $where_args, int $offset): array
    {
        global $wpdb;

        $bucket_expr = "DATE_FORMAT(created_at, '" . self::BUCKET_FMT . "')";

        // Total = number of distinct groups. Always run through prepare so the %% in
        // $bucket_expr is un-escaped to literal %; the trailing LIMIT %d (always 1) is
        // a no-op on a single-row COUNT but guarantees prepare has a placeholder.
        $count_sql = "SELECT COUNT(*) FROM (
            SELECT 1 FROM $table WHERE $where_sql
            GROUP BY event, level, user_login, ip, $bucket_expr
        ) g LIMIT %d";
        $total = (int) $wpdb->get_var(
            $wpdb->prepare($count_sql, [...$where_args, 1]),
        );

        $row_args = [...$where_args, self::PER_PAGE, $offset];
        $sql = "SELECT
                    MIN(id)         AS first_id,
                    MAX(id)         AS last_id,
                    MIN(created_at) AS first_at,
                    MAX(created_at) AS last_at,
                    event, level, user_login, ip,
                    COUNT(*)        AS count,
                    -- Stash the message/context from the latest row in the group via subqueries below.
                    $bucket_expr    AS bucket_start
                FROM $table
                WHERE $where_sql
                GROUP BY event, level, user_login, ip, $bucket_expr
                ORDER BY last_at DESC
                LIMIT %d OFFSET %d";
        $groups = $wpdb->get_results($wpdb->prepare($sql, $row_args), ARRAY_A) ?: [];

        // For singleton groups, fetch message + context via one batched lookup (keyed by last_id).
        $singleton_ids = [];
        foreach ($groups as $g) {
            if ((int) $g['count'] === 1) {
                $singleton_ids[] = (int) $g['last_id'];
            }
        }
        $extras = [];
        if ($singleton_ids) {
            $placeholders = implode(',', array_fill(0, count($singleton_ids), '%d'));
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, message, context FROM $table WHERE id IN ($placeholders)",
                    $singleton_ids,
                ),
                ARRAY_A,
            ) ?: [];
            foreach ($rows as $r) {
                $extras[(int) $r['id']] = $r;
            }
        }

        foreach ($groups as &$g) {
            $g['__folded']     = true;
            $g['count']        = (int) $g['count'];
            $g['bucket_end']   = gmdate('Y-m-d H:i:s', strtotime($g['bucket_start'] . ' UTC') + self::BUCKET_SECS);
            $g['created_at']   = $g['last_at']; // for renderer fallback
            $g['id']           = (int) $g['last_id'];

            if ($g['count'] === 1 && isset($extras[(int) $g['last_id']])) {
                $g['message'] = $extras[(int) $g['last_id']]['message'];
                $g['context'] = $extras[(int) $g['last_id']]['context'];
            } else {
                $g['message'] = null;
                $g['context'] = null;
            }
        }
        unset($g);

        return [$groups, $total];
    }
}
