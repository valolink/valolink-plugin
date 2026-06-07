<?php

declare(strict_types=1);

namespace Valolink\Plugin;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Modules\Branding\BrandingModule;

final class Plugin
{
    public static function boot(): void
    {
        $settings = new Settings();
        $registry = new Registry();
        self::register_modules($registry);

        if (is_admin()) {
            (new SettingsPage($settings, $registry))->register();
        }

        $loader = new Loader($settings, $registry, Context::detect());
        $loader->load();
    }

    public static function on_activate(): void
    {
        if (get_option(Settings::OPTION_KEY) === false) {
            add_option(Settings::OPTION_KEY, ['modules' => []], '', false);
        }
    }

    public static function on_deactivate(): void
    {
        // Clear any cron events the plugin scheduled. Modules that register cron must use the valolink_ prefix.
        foreach (_get_cron_array() ?: [] as $timestamp => $hooks) {
            foreach (array_keys($hooks) as $hook) {
                if (is_string($hook) && str_starts_with($hook, 'valolink_')) {
                    wp_unschedule_hook($hook);
                }
            }
        }
    }

    public static function register_modules(Registry $registry): void
    {
        $registry->register(new ModuleManifest(
            id: BrandingModule::MODULE_ID,
            label: __('Agency Branding', 'valolink-plugin'),
            description: __('Replace the WordPress login logo and add agency support contact info beneath the login form.', 'valolink-plugin'),
            class: BrandingModule::class,
        ));
    }
}
