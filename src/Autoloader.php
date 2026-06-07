<?php

declare(strict_types=1);

namespace Valolink\Plugin;

final class Autoloader
{
    private const PREFIX = 'Valolink\\Plugin\\';

    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    private static function load(string $class): void
    {
        if (!str_starts_with($class, self::PREFIX)) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));
        $path = VALOLINK_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    }
}
