<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Staging;

/**
 * Copies mu-loader.php into wp-content/mu-plugins so that the staging
 * "disable plugins" feature can intercept active_plugins before WP loads
 * regular plugins.
 */
final class MuPluginInstaller
{
    public const FILENAME = 'valolink-staging-loader.php';

    public static function install(): bool
    {
        if (!defined('WPMU_PLUGIN_DIR')) {
            return false;
        }

        $source = self::source_path();
        if (!file_exists($source)) {
            return false;
        }

        if (!is_dir(WPMU_PLUGIN_DIR) && !wp_mkdir_p(WPMU_PLUGIN_DIR)) {
            return false;
        }

        $target = WPMU_PLUGIN_DIR . '/' . self::FILENAME;

        // Skip the copy if target is already up-to-date.
        if (file_exists($target) && @md5_file($target) === @md5_file($source)) {
            return true;
        }

        return (bool) @copy($source, $target);
    }

    public static function remove(): void
    {
        if (!defined('WPMU_PLUGIN_DIR')) {
            return;
        }
        $target = WPMU_PLUGIN_DIR . '/' . self::FILENAME;
        if (file_exists($target)) {
            @unlink($target);
        }
    }

    public static function is_installed(): bool
    {
        return defined('WPMU_PLUGIN_DIR')
            && file_exists(WPMU_PLUGIN_DIR . '/' . self::FILENAME);
    }

    private static function source_path(): string
    {
        return __DIR__ . '/mu-loader.php';
    }
}
