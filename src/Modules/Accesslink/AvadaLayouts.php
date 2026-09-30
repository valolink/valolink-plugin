<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Avada's Layout Builder: which header, page title bar, content and footer a
 * page gets.
 *
 * A Layout (`fusion_tb_layout`) is a set of conditions plus, per area, the
 * Layout Section (`fusion_tb_section`) to render there. The Global Layout —
 * an option, not a post — fills every area a matching Layout leaves empty.
 * Among Layouts whose conditions match, the last in Avada's order wins.
 * A Layout stores all of this as slashed JSON in its post_content.
 *
 * This is to Avada what ElementReader is to GeneratePress: without it an
 * agent editing a page cannot tell that the header it sees is a section used
 * on forty pages, or that the page's own content is not rendered at all
 * because its Layout replaces the content area with a section that has no
 * Post Content element.
 *
 * Agents may change an existing Layout's conditions and section assignments,
 * and give a section its area. They may not create a Layout: Avada loads
 * Layouts with post_status "any" and never looks at the status when matching,
 * so a draft Layout would apply on the live site the moment it was drafted.
 * The Global Layout is read-only here.
 */
final class AvadaLayouts
{
    public const LAYOUT_TYPE   = 'fusion_tb_layout';
    public const SECTION_TYPE  = FusionSchema::SECTION_POST_TYPE;
    public const AREA_TAXONOMY = 'fusion_tb_category';
    public const AREAS         = ['header', 'page_title_bar', 'content', 'footer'];

    public const LAYOUT_FIELDS  = ['layout_conditions', 'layout_sections'];
    public const SECTION_FIELDS = ['section_area'];

    private const DEFAULT_OPTION = 'fusion_tb_layout_default';

    /** Elements that render the viewed post's own content inside a content section. */
    public const POST_CONTENT_ELEMENTS = ['fusion_tb_content', 'fusion_tb_woo_tabs'];

    /** Rules with no object, by the kind of view they describe. */
    private const FIXED_RULES = [
        'front_page'         => ['singular', 'Front Page'],
        'not_found'          => ['singular', '404 Page'],
        'woo_order_received' => ['singular', 'WooCommerce Thank You Page'],
        'search_results'     => ['archives', 'Search Results'],
        'all_archives'       => ['archives', 'All Archives Pages'],
        'date_archive'       => ['archives', 'All Date Pages'],
        'author_archive'     => ['archives', 'All Author Pages'],
    ];

    public static function available(): bool
    {
        return class_exists('Fusion_Template_Builder') && post_type_exists(self::LAYOUT_TYPE);
    }

    public static function is_field(string $field): bool
    {
        return in_array($field, self::LAYOUT_FIELDS, true) || in_array($field, self::SECTION_FIELDS, true);
    }

    /** @return array<int, string> the areas this Avada knows, add-ons included */
    public static function areas(): array
    {
        if (class_exists('Fusion_Template_Builder') && method_exists('Fusion_Template_Builder', 'get_instance')) {
            $types = \Fusion_Template_Builder::get_instance()->get_template_terms();
            if (is_array($types) && $types !== []) {
                return array_map('strval', array_keys($types));
            }
        }

        return self::AREAS;
    }

    // -------------------------------------------------------------------------
    // Listing
    // -------------------------------------------------------------------------

    public function list(): array
    {
        $layouts = [];
        foreach ($this->ordered_layouts() as $i => $layout) {
            $data = self::decode((string) $layout->post_content);
            $layouts[] = [
                'id'         => $layout->ID,
                'title'      => $layout->post_title,
                'status'     => $layout->post_status,
                'order'      => $i + 1,
                'conditions' => $this->conditions_out($data['conditions']),
                'sections'   => $this->describe_sections($data['template_terms']),
            ];
        }

        $used = [];
        $global = self::decode((string) get_option(self::DEFAULT_OPTION, ''));
        foreach ($global['template_terms'] as $area => $id) {
            $used[(int) $id][] = 'global:' . $area;
        }
        foreach ($layouts as $layout) {
            foreach ($layout['sections'] as $area => $section) {
                $used[(int) $section['id']][] = $layout['id'] . ':' . $area;
            }
        }

        $sections = [];
        foreach (get_posts(['post_type' => self::SECTION_TYPE, 'post_status' => ['publish', 'draft', 'pending', 'private'], 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC']) as $section) {
            $row = [
                'id'      => $section->ID,
                'title'   => $section->post_title,
                'status'  => $section->post_status,
                'area'    => self::area_of($section->ID),
                'used_by' => $used[$section->ID] ?? [],
            ];
            if ($row['area'] === 'content') {
                $row['shows_post_content'] = self::shows_post_content((string) $section->post_content);
            }
            $sections[] = $row;
        }

        return [
            'areas'    => self::areas(),
            'global'   => ['sections' => $this->describe_sections($global['template_terms'])],
            'layouts'  => $layouts,
            'sections' => $sections,
        ];
    }

    /**
     * The Layout a single post gets and what each area renders, resolved the
     * way Avada resolves it on the front end. Archive rules never match a
     * single post; the 404, search and thank-you rules never match a post.
     */
    public function for_post(\WP_Post $post): array
    {
        $chosen = null;
        foreach ($this->ordered_layouts() as $layout) {
            $conditions = $this->conditions_out(self::decode((string) $layout->post_content)['conditions']);
            $excluded = false;
            $included = false;
            foreach ($conditions as $condition) {
                if (!$this->matches($condition, $post)) {
                    continue;
                }
                if ($condition['mode'] === 'exclude') {
                    $excluded = true;
                } else {
                    $included = true;
                }
            }
            // The last match in Avada's order wins, as on the front end.
            if ($included && !$excluded) {
                $chosen = $layout;
            }
        }

        $effective = [];
        $global = self::decode((string) get_option(self::DEFAULT_OPTION, ''))['template_terms'];
        foreach ($global as $area => $id) {
            $section = $this->live_section((int) $id, (string) $area, 'global', $post);
            if ($section !== null) {
                $effective[$area] = $section + ['from' => 'global'];
            }
        }
        if ($chosen !== null) {
            foreach (self::decode((string) $chosen->post_content)['template_terms'] as $area => $id) {
                $section = $this->live_section((int) $id, (string) $area, $chosen->ID, $post);
                if ($section !== null) {
                    $effective[$area] = $section + ['from' => 'layout'];
                }
            }
        }

        $content = $effective['content'] ?? null;

        return [
            'layout'             => $chosen === null ? null : ['id' => $chosen->ID, 'title' => $chosen->post_title],
            'sections'           => $effective,
            // False means this post's own content is not on its page: an edit
            // to it changes nothing a visitor sees.
            'post_content_shown' => $content === null
                || self::shows_post_content((string) get_post_field('post_content', (int) $content['id'])),
        ];
    }

    // -------------------------------------------------------------------------
    // Fields
    // -------------------------------------------------------------------------

    /**
     * A field's current value as readable lines, the form the staleness hash
     * and the review diff both use.
     */
    public function read_field(int $post_id, string $field): string
    {
        if ($field === 'section_area') {
            return self::area_of($post_id);
        }
        $data = self::decode((string) get_post_field('post_content', $post_id));

        return $field === 'layout_conditions'
            ? $this->format_conditions($this->conditions_out($data['conditions']))
            : $this->format_sections(array_map('intval', $data['template_terms']));
    }

    /** A proposed value in read_field()'s form, for the review diff. */
    public function format_proposed(int $post_id, string $field, mixed $value): string
    {
        $normalised = $this->normalise_fields((string) get_post_type($post_id), [$field => $value], $post_id);
        if (is_wp_error($normalised)) {
            return '(invalid: ' . $normalised->get_error_message() . ')';
        }

        return match ($field) {
            'layout_conditions' => $this->format_conditions($this->with_labels($normalised[$field])),
            'layout_sections'   => $this->format_sections($normalised[$field]),
            default             => (string) $normalised[$field],
        };
    }

    /**
     * Check and coerce layout and section fields. `layout_sections` comes back
     * as the whole map after the change: areas not named keep their section.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function normalise_fields(string $post_type, array $fields, int $post_id = 0): array|\WP_Error
    {
        $out = [];
        $layout_fields = array_intersect_key($fields, array_flip(self::LAYOUT_FIELDS));
        if ($layout_fields !== [] && $post_type !== self::LAYOUT_TYPE) {
            return new \WP_Error('layout_fields_unsupported', sprintf('%s apply only to %s.', implode(', ', array_keys($layout_fields)), self::LAYOUT_TYPE), ['status' => 400]);
        }
        if (array_key_exists('section_area', $fields) && $post_type !== self::SECTION_TYPE) {
            return new \WP_Error('layout_fields_unsupported', 'section_area applies only to ' . self::SECTION_TYPE . '.', ['status' => 400]);
        }

        if (array_key_exists('layout_conditions', $fields)) {
            $conditions = $this->normalise_conditions($fields['layout_conditions']);
            if (is_wp_error($conditions)) {
                return $conditions;
            }
            $out['layout_conditions'] = $conditions;
        }

        if (array_key_exists('layout_sections', $fields)) {
            $sections = $this->normalise_sections($fields['layout_sections'], $post_id);
            if (is_wp_error($sections)) {
                return $sections;
            }
            $out['layout_sections'] = $sections;
        }

        if (array_key_exists('section_area', $fields)) {
            $area = sanitize_key((string) $fields['section_area']);
            if (!in_array($area, self::areas(), true)) {
                return new \WP_Error('bad_section_area', 'section_area must be one of: ' . implode(', ', self::areas()) . '.', ['status' => 400]);
            }
            if ($post_id > 0 && self::area_of($post_id) !== '' && self::area_of($post_id) !== $area) {
                $users = array_filter(
                    $this->list()['sections'],
                    static fn (array $s): bool => $s['id'] === $post_id && $s['used_by'] !== [],
                );
                if ($users !== []) {
                    return new \WP_Error(
                        'section_in_use',
                        'That section is assigned in a Layout, so moving it to another area would leave the Layout pointing at the wrong kind of section. Unassign it first.',
                        ['status' => 409],
                    );
                }
            }
            $out['section_area'] = $area;
        }

        return $out;
    }

    /** @param array<string, mixed> $fields already normalised */
    public function write_fields(int $post_id, array $fields): true|\WP_Error
    {
        if (array_key_exists('section_area', $fields)) {
            $set = wp_set_object_terms($post_id, [(string) $fields['section_area']], self::AREA_TAXONOMY);
            if (is_wp_error($set)) {
                return $set;
            }
        }

        if (!array_key_exists('layout_conditions', $fields) && !array_key_exists('layout_sections', $fields)) {
            return true;
        }
        if (!self::available()) {
            return new \WP_Error('layouts_unavailable', 'Avada\'s Layout Builder is not active.');
        }

        $data = self::decode((string) get_post_field('post_content', $post_id));
        if (array_key_exists('layout_conditions', $fields)) {
            $data['conditions'] = $this->to_storage((array) $fields['layout_conditions']);
        }
        if (array_key_exists('layout_sections', $fields)) {
            $data['template_terms'] = array_map('strval', (array) $fields['layout_sections']);
        }

        // Avada's own writer, so the stored JSON is exactly what its screen
        // would save, and its caches are reset the way its screen resets them.
        PostApplier::without_kses(static fn () => \Fusion_Template_Builder::update_layout_content($post_id, $data));

        return true;
    }

    // -------------------------------------------------------------------------
    // Conditions
    // -------------------------------------------------------------------------

    /**
     * An agent's conditions, checked against what exists here and put in one
     * canonical shape: `{rule, object?, mode}`.
     *
     * @return array<int, array{rule: string, object?: int, mode: string}>|\WP_Error
     */
    public function normalise_conditions(mixed $raw): array|\WP_Error
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return new \WP_Error('bad_conditions', 'layout_conditions must be a list of {"rule": "...", "object": id, "mode": "include|exclude"}.', ['status' => 400]);
        }

        $out = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                return new \WP_Error('bad_conditions', 'Each condition is an object: {"rule": "specific_page", "object": 123}.', ['status' => 400]);
            }
            $rule = sanitize_key((string) ($entry['rule'] ?? ''));
            $mode = (string) ($entry['mode'] ?? 'include');
            if (!in_array($mode, ['include', 'exclude'], true)) {
                return new \WP_Error('bad_conditions', 'mode must be "include" or "exclude".', ['status' => 400]);
            }
            $object = isset($entry['object']) && $entry['object'] !== '' && $entry['object'] !== null ? (int) $entry['object'] : null;

            $kind = $this->classify($rule, $object);
            if (is_wp_error($kind)) {
                return $kind;
            }

            $key = $object === null ? $rule : $rule . '|' . $object;
            if (isset($seen[$key])) {
                if ($seen[$key] !== $mode) {
                    return new \WP_Error('bad_conditions', sprintf('%s is both included and excluded.', $key), ['status' => 400]);
                }
                continue;
            }
            $seen[$key] = $mode;
            $out[] = ['rule' => $rule] + ($object === null ? [] : ['object' => $object]) + ['mode' => $mode];
        }

        return $out;
    }

    /**
     * What kind of view a rule describes, and whether its object exists.
     * Mirrors the list Avada's condition picker offers.
     *
     * @return array{0: string, 1: string}|\WP_Error type ('singular'|'archives') and label
     */
    private function classify(string $rule, ?int $object): array|\WP_Error
    {
        $refuse = static fn (string $msg): \WP_Error => new \WP_Error('bad_conditions', $msg, ['status' => 400]);

        if (isset(self::FIXED_RULES[$rule])) {
            return $object === null ? self::FIXED_RULES[$rule] : $refuse(sprintf('%s takes no object.', $rule));
        }

        foreach (['specific_', 'children_of_', 'singular_'] as $prefix) {
            if (!str_starts_with($rule, $prefix)) {
                continue;
            }
            $type = substr($rule, strlen($prefix));
            $pto = get_post_type_object($type);
            if ($pto === null) {
                return $refuse(sprintf('%s: there is no post type "%s" here.', $rule, $type));
            }
            if ($prefix === 'singular_') {
                return $object === null ? ['singular', 'All ' . $pto->label] : $refuse(sprintf('%s takes no object; use specific_%s for one post.', $rule, $type));
            }
            if ($prefix === 'children_of_' && !is_post_type_hierarchical($type)) {
                return $refuse(sprintf('%s: %s has no parent pages.', $rule, $type));
            }
            $post = $object === null ? null : get_post($object);
            if (!$post instanceof \WP_Post || $post->post_type !== $type || $post->post_status === 'trash') {
                return $refuse(sprintf('%s needs "object": the id of an existing %s.', $rule, $type));
            }

            return ['singular', $post->post_title];
        }

        if ($rule === 'author_archive_') {
            $user = $object === null ? false : get_userdata($object);

            return $user ? ['archives', $user->display_name] : $refuse('author_archive_ needs "object": a user id.');
        }

        foreach (['taxonomy_of_', 'archive_of_'] as $prefix) {
            if (!str_starts_with($rule, $prefix)) {
                continue;
            }
            $name = substr($rule, strlen($prefix));
            if ($prefix === 'archive_of_' && $object === null) {
                $pto = get_post_type_object($name);

                return $pto !== null ? ['archives', $pto->label . ' Archive Types'] : $refuse(sprintf('%s: there is no post type "%s" here. For one term\'s archive give its id as "object".', $rule, $name));
            }
            if (!taxonomy_exists($name)) {
                return $refuse(sprintf('%s: there is no taxonomy "%s" here.', $rule, $name));
            }
            $term = $object === null ? null : get_term($object, $name);
            if (!$term instanceof \WP_Term) {
                return $refuse(sprintf('%s needs "object": the id of an existing %s term.', $rule, $name));
            }

            return ['archives', $term->name];
        }

        $taxonomy = get_taxonomy($rule);
        if ($taxonomy !== false) {
            return $object === null ? ['archives', 'All ' . $taxonomy->label] : $refuse(sprintf('%s means all its archives and takes no object; use archive_of_%s for one term.', $rule, $rule));
        }

        return $refuse(sprintf(
            'Unknown rule "%s". Rules: front_page, singular_<post type>, specific_<post type> + object, children_of_<post type> + object, '
                . 'taxonomy_of_<taxonomy> + object (posts with that term), archive_of_<post type>, archive_of_<taxonomy> + object, '
                . '<taxonomy>, all_archives, search_results, not_found, date_archive, author_archive, author_archive_ + object.',
            $rule,
        ));
    }

    /** Stored conditions in the canonical shape, with Avada's labels. */
    private function conditions_out(array $stored): array
    {
        $out = [];
        foreach ($stored as $key => $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $type  = (string) ($condition['type'] ?? '');
            $value = (string) ($condition[$type] ?? $key);
            $row   = isset($condition['parent'])
                ? ['rule' => (string) $condition['parent'], 'object' => (int) (explode('|', $value)[1] ?? 0)]
                : ['rule' => $value];
            $out[] = $row + ['mode' => (string) ($condition['mode'] ?? 'include'), 'label' => (string) ($condition['label'] ?? '')];
        }

        return $out;
    }

    private function with_labels(array $canonical): array
    {
        return array_map(function (array $c): array {
            $kind = $this->classify($c['rule'], $c['object'] ?? null);

            return $c + ['label' => is_wp_error($kind) ? '' : $kind[1]];
        }, $canonical);
    }

    /** Canonical conditions in the keyed form Avada stores. */
    private function to_storage(array $canonical): array
    {
        $out = [];
        foreach ($canonical as $c) {
            $object = $c['object'] ?? null;
            $kind = $this->classify((string) $c['rule'], $object);
            if (is_wp_error($kind)) {
                continue;
            }
            $key = $object === null ? $c['rule'] : $c['rule'] . '|' . $object;
            $row = ['label' => $kind[1], 'type' => $kind[0], 'mode' => $c['mode'], $kind[0] => $key];
            if ($object !== null) {
                $row['parent'] = $c['rule'];
            }
            $out[$key] = $row;
        }

        return $out;
    }

    /** Whether one canonical condition describes this single post. */
    private function matches(array $c, \WP_Post $post): bool
    {
        $rule = (string) $c['rule'];
        $object = (int) ($c['object'] ?? 0);

        if ($rule === 'front_page') {
            return get_option('show_on_front') === 'page' && (int) get_option('page_on_front') === $post->ID;
        }
        if (str_starts_with($rule, 'singular_')) {
            return $post->post_type === substr($rule, 9);
        }
        if (str_starts_with($rule, 'specific_')) {
            return $post->ID === $object;
        }
        if (str_starts_with($rule, 'children_of_')) {
            return in_array($object, array_map('intval', get_post_ancestors($post)), true);
        }
        if (str_starts_with($rule, 'taxonomy_of_')) {
            return has_term($object, substr($rule, 12), $post);
        }

        return false;
    }

    private function format_conditions(array $conditions): string
    {
        $lines = array_map(
            static fn (array $c): string => sprintf(
                '%-7s  %s%s%s',
                $c['mode'],
                $c['rule'],
                isset($c['object']) ? ' ' . $c['object'] : '',
                ($c['label'] ?? '') !== '' ? '  (' . $c['label'] . ')' : '',
            ),
            $conditions,
        );
        sort($lines);

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Sections
    // -------------------------------------------------------------------------

    /** @return array<string, int>|\WP_Error the whole area map after the change */
    private function normalise_sections(mixed $raw, int $layout_id): array|\WP_Error
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw) || array_is_list($raw) && $raw !== []) {
            return new \WP_Error('bad_sections', 'layout_sections maps areas to section ids: {"header": 123, "footer": 0}. 0 removes an area\'s section.', ['status' => 400]);
        }

        $map = $layout_id > 0
            ? array_map('intval', self::decode((string) get_post_field('post_content', $layout_id))['template_terms'])
            : [];
        foreach ($raw as $area => $id) {
            $area = (string) $area;
            if (!in_array($area, self::areas(), true)) {
                return new \WP_Error('bad_sections', sprintf('"%s" is not an area. Areas: %s.', $area, implode(', ', self::areas())), ['status' => 400]);
            }
            $id = (int) $id;
            if ($id === 0) {
                unset($map[$area]);
                continue;
            }
            $section = get_post($id);
            if (!$section instanceof \WP_Post || $section->post_type !== self::SECTION_TYPE || $section->post_status === 'trash') {
                return new \WP_Error('bad_sections', sprintf('%d is not a Layout Section — see GET /layouts for the sections.', $id), ['status' => 400]);
            }
            $its_area = self::area_of($id);
            if ($its_area !== $area) {
                return new \WP_Error('bad_sections', sprintf('Section %d is a %s section, not %s.', $id, $its_area !== '' ? $its_area : 'unassigned', $area), ['status' => 400]);
            }
            $map[$area] = $id;
        }
        ksort($map);

        return $map;
    }

    /** @param array<string, int> $map */
    private function format_sections(array $map): string
    {
        $lines = [];
        foreach (self::areas() as $area) {
            $id = (int) ($map[$area] ?? 0);
            $lines[] = sprintf(
                '%-15s %s',
                $area,
                $id > 0 ? $id . '  ' . get_the_title($id) . (get_post_status($id) === 'publish' ? '' : ' [' . get_post_status($id) . ']') : '—',
            );
        }

        return implode("\n", $lines);
    }

    /** @return array<string, array{id: int, title: string, status: string}> */
    private function describe_sections(array $terms): array
    {
        $out = [];
        foreach ($terms as $area => $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[(string) $area] = ['id' => $id, 'title' => (string) get_the_title($id), 'status' => (string) get_post_status($id)];
            }
        }

        return $out;
    }

    /**
     * A section as the front end would use it for $post: after Avada's own id
     * filter, published only.
     *
     * Avada's Polylang integration maps the id with pll_get_post($id), which
     * reads the request's current language — and a REST request has none, so
     * the filter answers false. On the front end the page's language is
     * current, so the mapping is done here in the post's own language, with
     * the same outcome: the section's translation, or no section at all when
     * it has none in that language.
     */
    private function live_section(int $id, string $area, int|string $layout_id, \WP_Post $post): ?array
    {
        $lang = function_exists('pll_get_post_language') ? (string) pll_get_post_language($post->ID) : '';
        $id = $lang !== '' && function_exists('pll_get_post')
            ? (int) pll_get_post($id, $lang)
            : (int) apply_filters('fusion_layout_section_id', $id, $area, $layout_id);
        $section = $id > 0 ? get_post($id) : null;
        if (!$section instanceof \WP_Post || $section->post_status !== 'publish') {
            return null;
        }

        return ['id' => $section->ID, 'title' => $section->post_title];
    }

    public static function area_of(int $section_id): string
    {
        $terms = wp_get_object_terms($section_id, self::AREA_TAXONOMY, ['fields' => 'slugs']);

        return is_array($terms) && $terms !== [] ? (string) $terms[0] : '';
    }

    public static function shows_post_content(string $section_content): bool
    {
        foreach (self::POST_CONTENT_ELEMENTS as $element) {
            if (preg_match('/\[' . preg_quote($element, '/') . '(?![\w-])/', $section_content)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------

    /**
     * Layouts in the order Avada evaluates them: its saved order first, then
     * any it has not ordered yet, newest first, as its own query returns them.
     *
     * @return array<int, \WP_Post>
     */
    private function ordered_layouts(): array
    {
        $posts = get_posts([
            'post_type'   => self::LAYOUT_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
        $by_id = [];
        foreach ($posts as $post) {
            $by_id[$post->ID] = $post;
        }

        $settings = get_option('fusion_builder_settings', []);
        $order = is_array($settings) ? (string) ($settings['awb_layout_order'] ?? '') : '';
        $out = [];
        foreach (array_filter(explode(',', $order), static fn (string $id): bool => ctype_digit($id)) as $id) {
            if (isset($by_id[(int) $id])) {
                $out[] = $by_id[(int) $id];
                unset($by_id[(int) $id]);
            }
        }

        return array_merge($out, array_values($by_id));
    }

    /** @return array{conditions: array, template_terms: array} */
    private static function decode(string $stored): array
    {
        $data = json_decode(wp_unslash($stored), true);
        if (!is_array($data)) {
            $data = json_decode($stored, true);
        }

        return [
            'conditions'     => is_array($data['conditions'] ?? null) ? $data['conditions'] : [],
            'template_terms' => is_array($data['template_terms'] ?? null) ? array_filter($data['template_terms']) : [],
        ];
    }
}
