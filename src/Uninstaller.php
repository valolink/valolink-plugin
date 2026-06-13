<?php

declare(strict_types=1);

namespace Valolink\Plugin;

final class Uninstaller
{
    public static function run(): void
    {
        $settings = new Settings();
        $registry = new Registry();
        Plugin::register_modules($registry, $settings);

        foreach ($registry->all() as $manifest) {
            if (!class_exists($manifest->class)) {
                continue;
            }
            /** @var Module $module */
            $module = new ($manifest->class)(...$manifest->constructor_args);
            $module->uninstall();
        }

        foreach (_get_cron_array() ?: [] as $timestamp => $hooks) {
            foreach (array_keys($hooks) as $hook) {
                if (is_string($hook) && str_starts_with($hook, 'valolink_')) {
                    wp_unschedule_hook($hook);
                }
            }
        }

        delete_option(Settings::OPTION_KEY);
    }
}
