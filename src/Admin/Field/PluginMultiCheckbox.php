<?php

declare(strict_types=1);

namespace Valolink\Plugin\Admin\Field;

/**
 * Renders a checkbox list of active WordPress plugins, suitable for embedding
 * in any module's settings form. Admin context only — relies on get_plugin_data().
 */
final class PluginMultiCheckbox
{
    /**
     * Render the checkbox list.
     *
     * @param string   $field_name HTML name attribute (include [] for array, e.g. "disabled_plugins[]")
     * @param string[] $checked    Plugin file paths that should be checked
     * @param string   $description Optional description shown above the list
     */
    public static function render(
        string $field_name,
        array $checked,
        string $description = '',
    ): void {
        $plugins = self::active_plugins_with_names();

        if (empty($plugins)) {
            echo '<p>' . esc_html__('No other active plugins found.', 'valolink-plugin') . '</p>';
            return;
        }
        ?>
        <fieldset>
            <?php if ($description !== '') : ?>
                <p class="description" style="margin-bottom:.75em;"><?php echo esc_html($description); ?></p>
            <?php endif; ?>
            <ul style="margin:0;columns:2;column-gap:2em;list-style:none;">
                <?php foreach ($plugins as $file => $name) : ?>
                    <li style="break-inside:avoid;margin-bottom:6px;">
                        <label>
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr($field_name); ?>"
                                value="<?php echo esc_attr($file); ?>"
                                <?php checked(in_array($file, $checked, true)); ?>
                            >
                            <?php echo esc_html($name); ?>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </fieldset>
        <?php
    }

    /**
     * Sanitize a raw POST value against the list of installed plugins.
     *
     * @param  mixed    $raw  Raw POST value (array, or absent/null)
     * @return string[]       Validated plugin file paths
     */
    public static function sanitize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed = array_keys(get_plugins());

        return array_values(array_filter(
            array_map('sanitize_text_field', wp_unslash($raw)),
            static fn(string $file): bool => in_array($file, $installed, true),
        ));
    }

    /**
     * Return active plugins as [file_path => display_name], excluding this plugin,
     * sorted alphabetically by name.
     *
     * @return array<string, string>
     */
    public static function active_plugins_with_names(): array
    {
        $active_files = (array) get_option('active_plugins', []);

        if (defined('VALOLINK_PLUGIN_BASENAME')) {
            $active_files = array_diff($active_files, [VALOLINK_PLUGIN_BASENAME]);
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = [];
        foreach ($active_files as $file) {
            $path = WP_PLUGIN_DIR . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $data     = get_plugin_data($path, false, false);
            $plugins[$file] = $data['Name'] !== '' ? $data['Name'] : $file;
        }

        asort($plugins);
        return $plugins;
    }
}
