<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Modules\Accesslink\Translation\TranslationAdapterFactory;

/**
 * Describes GeneratePress Elements.
 *
 * Elements are ordinary posts of the `gp_elements` type, so everything else in
 * Accesslink already reads and edits them once the operator allows that post
 * type. What is *not* ordinary is what they mean: an Element is site furniture
 * — a hero, a footer, a script injected into wp_head — and its behaviour lives
 * entirely in `_generate_*` postmeta rather than in its content.
 *
 * Without that meta surfaced, an agent reading a site sees a pile of untitled
 * pages and cannot tell that the footer CTA is an Element rather than part of
 * each page, which is exactly the mistake that leads to editing twenty pages
 * instead of one.
 */
final class ElementReader
{
    public const POST_TYPE = 'gp_elements';

    /** GeneratePress stores these per Element; all are optional. */
    private const META = [
        'element_type'  => '_generate_element_type',
        'block_type'    => '_generate_block_type',
        'hook'          => '_generate_hook',
        'custom_hook'   => '_generate_custom_hook',
        'hook_priority' => '_generate_hook_priority',
    ];

    private const CONDITION_META = [
        'display' => '_generate_element_display_conditions',
        'exclude' => '_generate_element_exclude_conditions',
        'user'    => '_generate_element_user_conditions',
    ];

    /**
     * What an agent may set on a `gp_elements` post, named exactly as list()
     * returns them so a read can be echoed back as a write.
     */
    public const WRITABLE = [
        'element_type', 'block_type', 'hook', 'custom_hook', 'hook_priority',
        'display_conditions', 'exclude_conditions', 'user_conditions',
    ];

    public const CONDITION_FIELDS = ['display_conditions', 'exclude_conditions', 'user_conditions'];

    public const ELEMENT_TYPES = ['block', 'hook', 'layout'];

    /** GeneratePress's own list for a Block Element; `hook` is the one that needs a hook name too. */
    public const BLOCK_TYPES = [
        'hook', 'site-header', 'page-hero', 'content-template', 'post-meta-template', 'loop-template',
        'site-footer', 'right-sidebar', 'left-sidebar', 'post-navigation', 'archive-navigation',
        'search-modal', 'comments-template', 'menu-bar-items',
    ];

    /** Used when GP Premium's own helper is not reachable; the common theme and core hooks. */
    private const FALLBACK_HOOKS = [
        'wp_head', 'wp_body_open', 'wp_footer',
        'generate_before_header', 'generate_after_header', 'generate_before_header_content',
        'generate_after_header_content', 'generate_before_primary_menu', 'generate_after_primary_menu',
        'generate_before_content', 'generate_after_content', 'generate_before_main_content',
        'generate_after_main_content', 'generate_before_entry_title', 'generate_after_entry_title',
        'generate_before_entry_content', 'generate_after_entry_content', 'generate_before_footer',
        'generate_after_footer', 'generate_before_footer_content', 'generate_after_footer_content',
        'generate_sidebars', 'generate_before_right_sidebar_content', 'generate_after_right_sidebar_content',
        'generate_before_left_sidebar_content', 'generate_after_left_sidebar_content',
    ];

    public static function available(): bool
    {
        return post_type_exists(self::POST_TYPE);
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        if (!self::available()) {
            return ['available' => false, 'elements' => []];
        }

        $tr = TranslationAdapterFactory::detect();
        $posts = get_posts([
            'post_type'   => self::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => 200,
            'orderby'     => 'title',
            'order'       => 'ASC',
            // Elements are per-language when a multilingual plugin manages them,
            // and the default query would silently show only the current one.
            'lang'        => '',
        ]);

        $out = [];
        foreach ($posts as $post) {
            $out[] = $this->describe($post, $tr->available());
        }

        return [
            'available'   => true,
            'total'       => count($out),
            'multilingual' => $tr->available() && $tr->is_translated_type(self::POST_TYPE),
            'elements'    => $out,
        ];
    }

    public function get(int $id): array|\WP_Error
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== self::POST_TYPE) {
            return new \WP_Error('not_found', 'No such element.', ['status' => 404]);
        }

        $tr = TranslationAdapterFactory::detect();
        $described = $this->describe($post, $tr->available());
        $described['content_chars'] = strlen((string) $post->post_content);

        return $described;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(\WP_Post $post, bool $multilingual): array
    {
        $row = [
            'id'     => $post->ID,
            'title'  => $post->post_title,
            'status' => $post->post_status,
        ];

        foreach (self::META as $key => $meta_key) {
            $value = get_post_meta($post->ID, $meta_key, true);
            if ($value !== '' && $value !== null) {
                $row[$key] = is_scalar($value) ? $value : $value;
            }
        }

        foreach (self::CONDITION_META as $key => $meta_key) {
            $value = get_post_meta($post->ID, $meta_key, true);
            if (!empty($value)) {
                $row[$key . '_conditions'] = $value;
            }
        }

        if ($multilingual) {
            $tr = TranslationAdapterFactory::detect();
            $row['language']     = $tr->language_of($post->ID) ?: null;
            $row['translations'] = $tr->translations($post->ID);
        }

        return $row;
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Hook names GeneratePress will accept. Its own helper is authoritative
     * when GP Premium is loaded; the fallback covers a site where the helper
     * class is renamed or the plugin is momentarily off.
     *
     * @return array<int, string>
     */
    public static function hooks(): array
    {
        $names = [];
        if (class_exists('\GeneratePress_Elements_Helper')
            && method_exists('\GeneratePress_Elements_Helper', 'get_available_hooks')) {
            try {
                // GP Premium returns a list of groups, each {group: label, hooks:
                // {hook_name: label, …}}. Older shapes were {label: {hook: label}};
                // both are walked, and anything that is not a plain hook name is
                // dropped rather than trusted.
                foreach ((array) \GeneratePress_Elements_Helper::get_available_hooks() as $key => $group) {
                    $hooks = is_array($group) && isset($group['hooks']) && is_array($group['hooks'])
                        ? $group['hooks']
                        : (is_array($group) ? $group : [$key => $group]);
                    foreach ($hooks as $hook_key => $hook_value) {
                        if (is_array($hook_value)) {
                            continue;
                        }
                        $names[] = is_string($hook_key) ? $hook_key : (string) $hook_value;
                    }
                }
            } catch (\Throwable $e) {
                $names = [];
            }
        }
        $names = array_values(array_unique(array_filter(
            array_map('strval', $names),
            static fn (string $h): bool => (bool) preg_match('/^[a-z][a-z0-9_]*$/', $h),
        )));

        return $names !== [] ? $names : self::FALLBACK_HOOKS;
    }

    /**
     * The current value of a writable field as a string, so the staleness hash
     * and the review diff read it the same way. Condition lists are JSON.
     */
    public function read_field(int $post_id, string $field): string
    {
        if (isset(self::META[$field])) {
            $value = get_post_meta($post_id, self::META[$field], true);

            return is_scalar($value) ? (string) $value : '';
        }

        $map = ['display_conditions' => 'display', 'exclude_conditions' => 'exclude', 'user_conditions' => 'user'];
        if (isset($map[$field])) {
            $value = get_post_meta($post_id, self::CONDITION_META[$map[$field]], true);
            $normalised = self::normalise_conditions($field, $value);

            return is_wp_error($normalised) || $normalised === [] ? '' : (string) wp_json_encode($normalised);
        }

        return '';
    }

    /**
     * Coerce and check an agent's element fields. Returns the canonical
     * values to store, or the reason they cannot be.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|\WP_Error
     */
    public function normalise_fields(array $fields): array|\WP_Error
    {
        $out = [];

        if (array_key_exists('element_type', $fields)) {
            $value = sanitize_key((string) $fields['element_type']);
            if (!in_array($value, self::ELEMENT_TYPES, true)) {
                return new \WP_Error('bad_element_type', 'element_type must be one of: ' . implode(', ', self::ELEMENT_TYPES) . '.', ['status' => 400]);
            }
            $out['element_type'] = $value;
        }

        if (array_key_exists('block_type', $fields)) {
            $value = sanitize_key((string) $fields['block_type']);
            if (!in_array($value, self::BLOCK_TYPES, true)) {
                return new \WP_Error('bad_block_type', 'block_type must be one of: ' . implode(', ', self::BLOCK_TYPES) . '.', ['status' => 400]);
            }
            $out['block_type'] = $value;
        }

        if (array_key_exists('hook', $fields)) {
            $value = sanitize_key((string) $fields['hook']);
            if ($value !== 'custom' && !in_array($value, self::hooks(), true)) {
                return new \WP_Error(
                    'bad_hook',
                    'hook must be "custom" (with custom_hook) or one of: ' . implode(', ', self::hooks()) . '.',
                    ['status' => 400],
                );
            }
            $out['hook'] = $value;
        }

        if (array_key_exists('custom_hook', $fields)) {
            $value = sanitize_key((string) $fields['custom_hook']);
            if ($value !== '' && !preg_match('/^[a-z0-9_]+$/', $value)) {
                return new \WP_Error('bad_custom_hook', 'custom_hook must be a plain hook name.', ['status' => 400]);
            }
            $out['custom_hook'] = $value;
        }

        if (array_key_exists('hook_priority', $fields)) {
            $out['hook_priority'] = (string) (int) $fields['hook_priority'];
        }

        foreach (self::CONDITION_FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $normalised = self::normalise_conditions($field, $fields[$field]);
            if (is_wp_error($normalised)) {
                return $normalised;
            }
            $out[$field] = $normalised;
        }

        return $out;
    }

    /**
     * Display and exclude conditions are lists of {rule, object}; user
     * conditions are a list of rule strings. The rule grammar is GeneratePress's
     * (`general:site`, `post:page`, `post:taxonomy:category`); only its shape is
     * checked here, because the full rule list depends on the site's post types
     * and taxonomies and the editor is where a wrong one shows up immediately.
     *
     * @return array<int, mixed>|\WP_Error
     */
    public static function normalise_conditions(string $field, mixed $raw): array|\WP_Error
    {
        if ($raw === '' || $raw === null) {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [$raw];
        }
        if (!is_array($raw)) {
            return new \WP_Error('bad_conditions', $field . ' must be a list.', ['status' => 400]);
        }

        $out = [];
        foreach ($raw as $entry) {
            if ($field === 'user_conditions') {
                $rule = sanitize_text_field(is_array($entry) ? (string) ($entry['rule'] ?? '') : (string) $entry);
                if (!preg_match('/^[a-z0-9_]+(:[a-z0-9_-]+)*$/', $rule)) {
                    return new \WP_Error('bad_conditions', $field . ' entries must look like "general:logged_in".', ['status' => 400]);
                }
                $out[] = $rule;
                continue;
            }

            if (!is_array($entry)) {
                return new \WP_Error('bad_conditions', $field . ' entries must be {"rule": "...", "object": "..."}.', ['status' => 400]);
            }
            $rule   = sanitize_text_field((string) ($entry['rule'] ?? ''));
            $object = $entry['object'] ?? '';
            if (!preg_match('/^[a-z0-9_]+(:[a-z0-9_-]+)*$/', $rule)) {
                return new \WP_Error('bad_conditions', $field . ' rule must look like "post:page" or "general:site".', ['status' => 400]);
            }
            if (!is_scalar($object)) {
                return new \WP_Error('bad_conditions', $field . ' object must be an id or empty.', ['status' => 400]);
            }
            $out[] = ['rule' => $rule, 'object' => (string) $object];
        }

        return $out;
    }

    /** @param array<string, mixed> $fields already normalised */
    public function write_fields(int $post_id, array $fields): void
    {
        foreach (self::META as $field => $key) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = (string) $fields[$field];
            if ($value === '') {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, $value);
            }
        }

        $map = ['display_conditions' => 'display', 'exclude_conditions' => 'exclude', 'user_conditions' => 'user'];
        foreach ($map as $field => $group) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = (array) $fields[$field];
            if ($value === []) {
                delete_post_meta($post_id, self::CONDITION_META[$group]);
            } else {
                update_post_meta($post_id, self::CONDITION_META[$group], $value);
            }
        }
    }
}
