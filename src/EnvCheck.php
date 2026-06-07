<?php

declare(strict_types=1);

namespace Valolink\Plugin;

final class EnvCheck
{
    public static function passes(): bool
    {
        if (version_compare(PHP_VERSION, VALOLINK_MIN_PHP, '<')) {
            return false;
        }

        $wp_version = self::wp_version();
        if ($wp_version !== null && version_compare($wp_version, VALOLINK_MIN_WP, '<')) {
            return false;
        }

        return true;
    }

    public static function render_notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $message = sprintf(
            /* translators: 1: required PHP version, 2: required WP version, 3: current PHP version, 4: current WP version */
            esc_html__('Valolink Plugin requires PHP %1$s+ and WordPress %2$s+. Detected PHP %3$s, WordPress %4$s. The plugin will remain inactive until the environment is upgraded.', 'valolink-plugin'),
            esc_html(VALOLINK_MIN_PHP),
            esc_html(VALOLINK_MIN_WP),
            esc_html(PHP_VERSION),
            esc_html(self::wp_version() ?? 'unknown')
        );

        printf('<div class="notice notice-error"><p>%s</p></div>', $message);
    }

    private static function wp_version(): ?string
    {
        global $wp_version;
        return is_string($wp_version) && $wp_version !== '' ? $wp_version : null;
    }
}
