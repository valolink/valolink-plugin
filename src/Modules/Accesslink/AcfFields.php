<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Advanced Custom Fields as proposable fields, named `acf:<field name>`.
 *
 * Present only when ACF is active. The field groups a post type has are read
 * from ACF itself, so the guide lists what this site actually defines, and a
 * value is written with update_field and the field's key, which is what
 * makes ACF keep its own reference meta for a field the post never had.
 *
 * Writable: the scalar kinds an agent can fill from a text — text, textarea,
 * wysiwyg (through the content sanitiser), number, range, email, url,
 * select, radio, button_group, checkbox, true_false, date, date-time, time
 * and colour pickers. Repeaters, flexible content, groups, relationships,
 * post objects, taxonomies, users, galleries, images, files and links are
 * readable (as stored, ids for the object kinds) and refused on write with
 * the type named: each has a shape a wrong value corrupts silently, and the
 * operator sets those in wp-admin.
 */
final class AcfFields
{
    public const PREFIX = 'acf:';

    public const WRITABLE_TYPES = [
        'text', 'textarea', 'wysiwyg', 'number', 'range', 'email', 'url',
        'select', 'radio', 'button_group', 'checkbox', 'true_false',
        'date_picker', 'date_time_picker', 'time_picker', 'color_picker',
    ];

    /** Layout-only kinds with no value of their own. */
    private const NO_VALUE_TYPES = ['tab', 'accordion', 'message'];

    /** @var array<string, array<string, array<string, mixed>>> post type => field name => field */
    private static array $cache = [];

    public static function available(): bool
    {
        return function_exists('acf_get_field_groups') && function_exists('acf_get_fields') && function_exists('update_field');
    }

    public static function is_acf(string $field): bool
    {
        return str_starts_with($field, self::PREFIX);
    }

    public static function name(string $field): string
    {
        return substr($field, strlen(self::PREFIX));
    }

    /**
     * The fields a post type has, by field name, in group order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields_for(string $post_type): array
    {
        if (!self::available() || $post_type === '') {
            return [];
        }
        if (isset(self::$cache[$post_type])) {
            return self::$cache[$post_type];
        }
        $out = [];
        foreach ((array) acf_get_field_groups(['post_type' => $post_type]) as $group) {
            $key = is_array($group) ? ($group['key'] ?? null) : null;
            if ($key === null) {
                continue;
            }
            foreach ((array) acf_get_fields($key) as $field) {
                if (!is_array($field) || empty($field['name']) || in_array((string) ($field['type'] ?? ''), self::NO_VALUE_TYPES, true)) {
                    continue;
                }
                $out[(string) $field['name']] ??= $field;
            }
        }

        return self::$cache[$post_type] = $out;
    }

    public static function applies_to(string $post_type): bool
    {
        return self::fields_for($post_type) !== [];
    }

    /**
     * `acf:<name>` for every field across the given post types.
     *
     * @param array<int, string> $post_types
     * @return array<int, string>
     */
    public static function field_names(array $post_types): array
    {
        $names = [];
        foreach ($post_types as $type) {
            foreach (array_keys(self::fields_for((string) $type)) as $name) {
                $names[self::PREFIX . $name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * A field by its `acf:` name: from the post type when known, else the
     * first of the given types that defines it.
     *
     * @param array<int, string> $post_types
     * @return array<string, mixed>|null
     */
    public static function field(string $field, ?string $post_type, array $post_types = []): ?array
    {
        $name = self::name($field);
        foreach ($post_type !== null && $post_type !== '' ? [$post_type] : $post_types as $type) {
            $def = self::fields_for((string) $type)[$name] ?? null;
            if ($def !== null) {
                return $def;
            }
        }

        return null;
    }

    public static function writable(array $field): bool
    {
        return in_array((string) ($field['type'] ?? ''), self::WRITABLE_TYPES, true);
    }

    /**
     * Every field of a post with its current value, for GET /content/{id}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function read(int $post_id): array
    {
        $out = [];
        foreach (self::fields_for((string) get_post_type($post_id)) as $name => $field) {
            $row = [
                'label'    => (string) ($field['label'] ?? $name),
                'type'     => (string) ($field['type'] ?? ''),
                'writable' => self::writable($field),
                'value'    => $this->read_field($post_id, self::PREFIX . $name),
            ];
            if (!empty($field['choices']) && is_array($field['choices'])) {
                $row['choices'] = array_map('strval', array_keys($field['choices']));
            }
            $out[self::PREFIX . $name] = $row;
        }

        return $out;
    }

    /** The current value as one string, the shape the diff and the hash compare. */
    public function read_field(int $post_id, string $field): string
    {
        $def = self::field($field, (string) get_post_type($post_id));
        if ($def === null) {
            return '';
        }

        return self::stringify(get_field((string) $def['key'], $post_id, false), (string) ($def['type'] ?? ''));
    }

    public static function stringify(mixed $value, string $type): string
    {
        if ($type === 'true_false') {
            return LayoutMeta::truthy($value) ? 'true' : 'false';
        }
        if ($value === null || $value === false) {
            return '';
        }
        if (is_array($value)) {
            return implode(', ', array_map(
                static fn ($v): string => is_scalar($v) ? (string) $v : (string) wp_json_encode($v),
                $value,
            ));
        }
        if (is_object($value)) {
            return (string) wp_json_encode($value);
        }

        return (string) $value;
    }

    /**
     * Proposal-time cleaning of one value, by the field's type where the
     * field is known on any allowed post type; unknown fields pass through
     * for validate() to refuse with the reason. Cleaning here means the
     * queue shows exactly what approval will write.
     *
     * @param array<int, string> $post_types
     */
    public static function sanitize(string $field, mixed $value, array $post_types): mixed
    {
        $def = self::field($field, null, $post_types);
        if ($def === null || !self::writable($def)) {
            return is_array($value) ? $value : (string) $value;
        }
        $clean = self::clean($def, $value);

        return is_wp_error($clean) ? (is_array($value) ? $value : (string) $value) : $clean;
    }

    /**
     * Every `acf:` field in a proposal, cleaned for this post type, or why not.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|\WP_Error
     */
    public function normalise(string $post_type, array $fields): array|\WP_Error
    {
        $out = [];
        foreach ($fields as $field => $value) {
            $field = (string) $field;
            $def = self::field($field, $post_type);
            if ($def === null) {
                return new \WP_Error(
                    'acf_unknown_field',
                    sprintf('%s is not a field of %s on this site; GET /content/{id} lists the fields a post has.', $field, $post_type),
                    ['status' => 400],
                );
            }
            if (!self::writable($def)) {
                return new \WP_Error(
                    'acf_field_readonly',
                    sprintf('%s is a %s field, which Accesslink reads but does not write; ask the operator to set it in wp-admin.', $field, (string) ($def['type'] ?? '')),
                    ['status' => 400],
                );
            }
            $clean = self::clean($def, $value);
            if (is_wp_error($clean)) {
                return $clean;
            }
            $out[$field] = $clean;
        }

        return $out;
    }

    /** Coerce one value to the field's type, or say why not. */
    public static function clean(array $def, mixed $value): mixed
    {
        $type    = (string) ($def['type'] ?? '');
        $field   = self::PREFIX . (string) ($def['name'] ?? '');
        $choices = is_array($def['choices'] ?? null) ? array_map('strval', array_keys($def['choices'])) : [];
        $multi   = !empty($def['multiple']) || $type === 'checkbox';
        $bad     = static fn (string $code, string $message): \WP_Error => new \WP_Error($code, $message, ['status' => 400]);

        switch ($type) {
            case 'text':
            case 'email':
                return sanitize_text_field((string) $value);
            case 'url':
                return esc_url_raw((string) $value);
            case 'textarea':
                return sanitize_textarea_field((string) $value);
            case 'wysiwyg':
                return ContentSanitizer::filter((string) $value);
            case 'number':
            case 'range':
                $s = trim((string) $value);
                if ($s === '') {
                    return '';
                }
                if (!is_numeric($s)) {
                    return $bad('acf_bad_number', sprintf('%s takes a number, not "%s".', $field, $s));
                }

                return $s;
            case 'true_false':
                return LayoutMeta::truthy($value) ? 'true' : 'false';
            case 'select':
            case 'radio':
            case 'button_group':
            case 'checkbox':
                $values = array_values(array_filter(
                    array_map(static fn ($v): string => sanitize_text_field((string) $v), (array) $value),
                    static fn (string $v): bool => $v !== '',
                ));
                if ($choices !== []) {
                    $unknown = array_diff($values, $choices);
                    if ($unknown !== []) {
                        return $bad('acf_bad_choice', sprintf('%s does not offer "%s"; choices: %s.', $field, implode('", "', $unknown), implode(', ', $choices)));
                    }
                }
                if (!$multi && count($values) > 1) {
                    return $bad('acf_single_choice', sprintf('%s takes one value.', $field));
                }

                return $multi ? $values : ($values[0] ?? '');
            case 'date_picker':
                $s = trim((string) $value);
                if ($s === '') {
                    return '';
                }
                if (!preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $s, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return $bad('acf_bad_date', sprintf('%s takes a date as YYYY-MM-DD.', $field));
                }

                return $m[1] . $m[2] . $m[3];
            case 'date_time_picker':
                $s = trim((string) $value);
                if ($s === '') {
                    return '';
                }
                $t = strtotime($s);
                if ($t === false) {
                    return $bad('acf_bad_datetime', sprintf('%s takes a date and time as YYYY-MM-DD HH:MM:SS.', $field));
                }

                return date('Y-m-d H:i:s', $t);
            case 'time_picker':
                $s = trim((string) $value);
                if ($s === '') {
                    return '';
                }
                if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $s)) {
                    return $bad('acf_bad_time', sprintf('%s takes a time as HH:MM:SS.', $field));
                }

                return strlen($s) === 5 ? $s . ':00' : $s;
            case 'color_picker':
                $s = trim((string) $value);
                if ($s === '') {
                    return '';
                }
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $s)) {
                    return $bad('acf_bad_color', sprintf('%s takes a colour as #rrggbb.', $field));
                }

                return strtolower($s);
            default:
                return $bad('acf_field_readonly', sprintf('%s is a %s field, which Accesslink reads but does not write.', $field, $type));
        }
    }

    /**
     * @param array<string, mixed> $fields already normalised for this post's type
     */
    public function write(int $post_id, array $fields): void
    {
        $type = (string) get_post_type($post_id);
        foreach ($fields as $field => $value) {
            $def = self::field((string) $field, $type);
            if ($def === null) {
                continue;
            }
            $stored = (string) ($def['type'] ?? '') === 'true_false' ? ($value === 'true' ? 1 : 0) : $value;
            update_field((string) $def['key'], $stored, $post_id);
        }
    }
}
