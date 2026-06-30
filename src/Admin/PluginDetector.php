<?php

declare(strict_types=1);

namespace Valolink\Plugin\Admin;

/**
 * Thin wrapper around get_plugins() / active_plugins for surfacing
 * "you already have <other plugin> doing this" notices on our settings pages.
 *
 * Cheap: get_plugins() reads from a single options table call, results are cached
 * for the request. Only call from admin contexts.
 */
final class PluginDetector
{
    /** @var array<string, array<string, mixed>>|null  basename => plugin data */
    private static ?array $installed = null;
    /** @var array<int, string>|null  list of active plugin basenames */
    private static ?array $active    = null;

    public static function is_active(string $basename): bool
    {
        self::load();
        return in_array($basename, self::$active ?? [], true);
    }

    /**
     * Return active plugins from $candidates as [basename => display name], in input order.
     *
     * @param  array<int, string>  $candidates
     * @return array<string, string>
     */
    public static function find_active_in(array $candidates): array
    {
        self::load();
        $hits = [];
        foreach ($candidates as $basename) {
            if (in_array($basename, self::$active ?? [], true)) {
                $name = self::$installed[$basename]['Name'] ?? $basename;
                $hits[$basename] = (string) $name;
            }
        }
        return $hits;
    }

    /**
     * Render a yellow "heads up" notice listing the conflicting plugins. No-ops when empty.
     *
     * @param array<string, string> $hits  basename => display name
     */
    public static function render_conflict_notice(array $hits, string $intro): void
    {
        if (empty($hits)) {
            return;
        }
        $names = array_map(static fn(string $n): string => '<strong>' . esc_html($n) . '</strong>', $hits);
        ?>
        <div class="notice notice-warning inline" style="margin:12px 0;">
            <p>
                <?php echo esc_html($intro); ?>
                <?php echo wp_kses(implode(', ', $names), ['strong' => []]); ?>.
                <?php esc_html_e('Disable one to avoid duplicate work or silent overrides.', 'valolink-plugin'); ?>
            </p>
        </div>
        <?php
    }

    private static function load(): void
    {
        if (self::$installed !== null) {
            return;
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        self::$installed = get_plugins();
        self::$active    = (array) get_option('active_plugins', []);
    }
}
