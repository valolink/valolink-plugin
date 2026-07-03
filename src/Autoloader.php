<?php

declare(strict_types=1);

namespace Valolink\Plugin;

final class Autoloader
{
    private const PREFIX = 'Valolink\\Plugin\\';

    public static function register(): void
    {
        // Idempotent: the staging mu-loader registers this early (on
        // `option_active_plugins`) and valolink-plugin.php registers it again
        // during normal bootstrap. spl_autoload_register does not dedupe
        // identical callbacks, so without this guard load() would run twice on
        // every class miss.
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        spl_autoload_register([self::class, 'load']);
    }

    private static function load(string $class): void
    {
        if (!str_starts_with($class, self::PREFIX)) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));
        // Resolve against this file's own directory (src/) rather than the
        // VALOLINK_PLUGIN_DIR constant. The staging mu-loader registers this
        // autoloader during the very early `option_active_plugins` filter —
        // before valolink-plugin.php has run and defined that constant — so
        // depending on it here fataled with "Undefined constant
        // Valolink\Plugin\VALOLINK_PLUGIN_DIR". __DIR__ is always available.
        $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    }
}
