<?php

declare(strict_types=1);

namespace Valolink\Plugin;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Modules\Branding\BrandingModule;
use Valolink\Plugin\Modules\Email\EmailModule;
use Valolink\Plugin\Modules\EngineLink\EngineLinkModule;
use Valolink\Plugin\Modules\Logging\LoggingModule;
use Valolink\Plugin\Modules\Scripts\ScriptsModule;
use Valolink\Plugin\Modules\Staging\MuPluginInstaller;
use Valolink\Plugin\Modules\Staging\StagingModule;

final class Plugin
{
    public static function boot(): void
    {
        $settings = new Settings();
        $registry = new Registry();
        self::register_modules($registry, $settings);

        (new Updater($settings))->register();

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
        // Drop the staging mu-loader into wp-content/mu-plugins so that
        // "disable plugins on staging" can intercept active_plugins.
        MuPluginInstaller::install();
    }

    public static function on_deactivate(): void
    {
        foreach (_get_cron_array() ?: [] as $timestamp => $hooks) {
            foreach (array_keys($hooks) as $hook) {
                if (is_string($hook) && str_starts_with($hook, 'valolink_')) {
                    wp_unschedule_hook($hook);
                }
            }
        }
    }

    public static function register_modules(Registry $registry, Settings $settings): void
    {
        $registry->register(new ModuleManifest(
            id: BrandingModule::MODULE_ID,
            label: __('Agency Branding', 'valolink-plugin'),
            description: __('Replace the WordPress login logo and add agency support contact info beneath the login form.', 'valolink-plugin'),
            class: BrandingModule::class,
        ));

        $registry->register(new ModuleManifest(
            id: StagingModule::MODULE_ID,
            label: __('Staging', 'valolink-plugin'),
            description: __('Detects staging environments (or forces staging mode) and lets you switch on per-feature controls: block indexing, intercept mail, disable Woo gateways, require login, redirect to a "coming soon" page, disable specific plugins, and block auto-updates. Configure under Valolink → Staging.', 'valolink-plugin'),
            class: StagingModule::class,
            default_enabled: false,
            constructor_args: [$settings],
        ));

        $registry->register(new ModuleManifest(
            id: EngineLinkModule::MODULE_ID,
            label: __('EngineLink Companion', 'valolink-plugin'),
            description: __('Exposes REST endpoints for EngineLink to pull site inventory (WP version, PHP, plugins, health). Requires an API key set below.', 'valolink-plugin'),
            class: EngineLinkModule::class,
            constructor_args: [$settings],
        ));

        $registry->register(new ModuleManifest(
            id: LoggingModule::MODULE_ID,
            label: __('Event Log', 'valolink-plugin'),
            description: __('Records site events (logins, plugin/theme changes, user changes, publishes) into a local table. Exposed to EngineLink via the same REST namespace. Pruned daily; retention configurable.', 'valolink-plugin'),
            class: LoggingModule::class,
            default_enabled: true,
            constructor_args: [$settings],
        ));

        $registry->register(new ModuleManifest(
            id: ScriptsModule::MODULE_ID,
            label: __('Scripts', 'valolink-plugin'),
            description: __('Manage JavaScript snippets and external script URLs with per-snippet loading strategy (head/async/defer/footer/on-interaction/on-scroll) and frontend/admin/logged-in/logged-out placement. Configure under Valolink → Scripts.', 'valolink-plugin'),
            class: ScriptsModule::class,
            default_enabled: false,
            constructor_args: [$settings],
        ));

        $registry->register(new ModuleManifest(
            id: EmailModule::MODULE_ID,
            label: __('Email (Resend)', 'valolink-plugin'),
            description: __('Routes wp_mail() through Resend\'s HTTPS API — avoids blocked SMTP ports. Force From email/name, default Reply-To, BCC catch-all, fallback on failure, admin alerts, and a one-click test send. Per-message logging goes through the Event Log module. Configure under Valolink → Email.', 'valolink-plugin'),
            class: EmailModule::class,
            default_enabled: false,
            constructor_args: [$settings],
        ));
    }
}
