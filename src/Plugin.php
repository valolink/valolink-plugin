<?php

declare(strict_types=1);

namespace Valolink\Plugin;

use Valolink\Plugin\Admin\SettingsPage;
use Valolink\Plugin\Modules\Branding\BrandingModule;
use Valolink\Plugin\Modules\Email\EmailModule;
use Valolink\Plugin\Modules\EngineLink\EngineLinkModule;
use Valolink\Plugin\Modules\Logging\LoggingModule;
use Valolink\Plugin\Modules\Scripts\ScriptsModule;
use Valolink\Plugin\Modules\Security\SecurityModule;
use Valolink\Plugin\Modules\Staging\MuPluginInstaller;
use Valolink\Plugin\Modules\Staging\StagingModule;
use Valolink\Plugin\Modules\Toolbox\ToolboxModule;

final class Plugin
{
    public static function boot(): void
    {
        // Last-resort guard: boot() runs on `plugins_loaded`, so anything that
        // throws here would WSOD the site. Per-module failures are already
        // isolated in Loader::load(); this catches the surrounding wiring
        // (settings, registry, updater, settings page) so the plugin degrades
        // to inert-but-logged rather than fataling. Never let a Throwable escape.
        try {
            $settings = new Settings();
            $registry = new Registry();
            self::register_modules($registry, $settings);

            if (defined('VALOLINK_PLUGIN_GITHUB_REPO') && VALOLINK_PLUGIN_GITHUB_REPO !== 'OWNER/REPO') {
                (new Updater(VALOLINK_PLUGIN_FILE, VALOLINK_PLUGIN_GITHUB_REPO, VALOLINK_PLUGIN_VERSION))->register();
            }

            if (is_admin()) {
                (new SettingsPage($settings, $registry))->register();
            }

            $loader = new Loader($settings, $registry, Context::detect());
            $loader->load();
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[valolink-plugin] boot failed, plugin inactive this request: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
        }
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
            label: __('EngineLink', 'valolink-plugin'),
            description: __('Exposes REST endpoints for EngineLink to pull site inventory (WP version, PHP, plugins, health). Requires an API key set below.', 'valolink-plugin'),
            class: EngineLinkModule::class,
            default_enabled: false,
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

        $registry->register(new ModuleManifest(
            id: SecurityModule::MODULE_ID,
            label: __('Security', 'valolink-plugin'),
            description: __('Per-feature hardening toggles (disable XML-RPC, etc.). Configure under Valolink → Security.', 'valolink-plugin'),
            class: SecurityModule::class,
            default_enabled: false,
            constructor_args: [$settings],
        ));

        $registry->register(new ModuleManifest(
            id: ToolboxModule::MODULE_ID,
            label: __('Toolbox', 'valolink-plugin'),
            description: __('Grab-bag of small toggles (allow SVG uploads, …) that don\'t justify a module of their own. Configure under Valolink → Toolbox.', 'valolink-plugin'),
            class: ToolboxModule::class,
            default_enabled: false,
            constructor_args: [$settings],
        ));
    }
}
