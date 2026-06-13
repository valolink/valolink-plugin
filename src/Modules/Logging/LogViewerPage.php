<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Logging;

use Valolink\Plugin\Admin\SettingsPage;

/**
 * Admin viewer for the event log. Lives under Valolink → Event Log.
 * Server-rendered, GET-based filters, offset pagination. No JS.
 */
final class LogViewerPage
{
    public const MENU_SLUG  = 'valolink-plugin-logs';
    public const CAPABILITY = 'manage_options';

    private const PER_PAGE = 50;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu'], 12); // after the main Valolink page
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

        // Read filters
        $f_event = isset($_GET['event']) ? sanitize_text_field(wp_unslash((string) $_GET['event'])) : '';
        $f_user  = isset($_GET['user'])  ? sanitize_user(wp_unslash((string) $_GET['user']))       : '';
        $f_level = isset($_GET['level']) ? sanitize_text_field(wp_unslash((string) $_GET['level'])) : '';
        $paged   = max(1, (int) ($_GET['paged'] ?? 1));
        $offset  = ($paged - 1) * self::PER_PAGE;

        $where = ['1=1'];
        $args  = [];
        if ($f_event !== '') { $where[] = 'event = %s';      $args[] = $f_event; }
        if ($f_user !== '')  { $where[] = 'user_login = %s'; $args[] = $f_user; }
        if ($f_level !== '') { $where[] = 'level = %s';      $args[] = $f_level; }

        $where_sql = implode(' AND ', $where);

        // Total count for pagination
        $total = $args
            ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE $where_sql", $args))
            : (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");

        // Page of rows
        $row_args = [...$args, self::PER_PAGE, $offset];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, created_at, event, level, user_id, user_login, ip, message, context
                 FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d",
                $row_args,
            ),
            ARRAY_A,
        );

        // Facet list for the event dropdown (cached cheap)
        $facets = $wpdb->get_results(
            "SELECT event, COUNT(*) AS c FROM $table GROUP BY event ORDER BY c DESC LIMIT 100",
            ARRAY_A,
        );

        $total_pages = max(1, (int) ceil($total / self::PER_PAGE));
        $base_url    = admin_url('admin.php?page=' . self::MENU_SLUG);

        $keep = [];
        if ($f_event !== '') { $keep['event'] = $f_event; }
        if ($f_user !== '')  { $keep['user']  = $f_user; }
        if ($f_level !== '') { $keep['level'] = $f_level; }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Event Log', 'valolink-plugin'); ?></h1>

            <form method="get" class="valolink-log-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">

                <select name="event">
                    <option value=""><?php esc_html_e('All events', 'valolink-plugin'); ?></option>
                    <?php foreach ($facets as $f) : ?>
                        <option value="<?php echo esc_attr($f['event']); ?>" <?php selected($f_event, $f['event']); ?>>
                            <?php echo esc_html($f['event']) . ' (' . (int) $f['c'] . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="user"
                       value="<?php echo esc_attr($f_user); ?>"
                       placeholder="<?php esc_attr_e('User login', 'valolink-plugin'); ?>"
                       size="14">

                <select name="level">
                    <option value=""><?php esc_html_e('Any level', 'valolink-plugin'); ?></option>
                    <option value="info"    <?php selected($f_level, 'info');    ?>>info</option>
                    <option value="warning" <?php selected($f_level, 'warning'); ?>>warning</option>
                    <option value="error"   <?php selected($f_level, 'error');   ?>>error</option>
                </select>

                <button type="submit" class="button"><?php esc_html_e('Filter', 'valolink-plugin'); ?></button>
                <a class="button" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Clear', 'valolink-plugin'); ?></a>

                <span class="valolink-log-summary">
                    <?php printf(esc_html(_n('%d entry', '%d entries', $total, 'valolink-plugin')), $total); ?>
                </span>
            </form>

            <table class="widefat striped valolink-log-table">
                <thead>
                    <tr>
                        <th style="width:170px;"><?php esc_html_e('When', 'valolink-plugin'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Level', 'valolink-plugin'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Event', 'valolink-plugin'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('User', 'valolink-plugin'); ?></th>
                        <th><?php esc_html_e('Message / context', 'valolink-plugin'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('IP', 'valolink-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="6"><em><?php esc_html_e('No entries.', 'valolink-plugin'); ?></em></td></tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html(get_date_from_gmt($row['created_at'])); ?></td>
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
                                <?php echo esc_html($row['message'] ?? ''); ?>
                                <?php if (!empty($row['context'])) :
                                    $decoded = json_decode((string) $row['context'], true);
                                    ?>
                                    <details class="valolink-context">
                                        <summary><?php esc_html_e('context', 'valolink-plugin'); ?></summary>
                                        <pre><?php echo esc_html(wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html($row['ip'] ?? '—'); ?></code></td>
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

            <style>
                .valolink-log-filters { margin: 16px 0; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
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
}
