<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Writes GenerateBlocks Pro global styles and design tokens the way its own
 * Styles dashboard does, for approved style proposals.
 *
 * A class style is a `gblocks_styles` post: selector, style data, compiled CSS
 * and category in meta, cascade position in menu_order. Tokens live on the
 * managed `:root` record and go only through GenerateBlocks_Pro_Styles_Root::save()
 * — its checksummed write, which validates the registry, keeps snapshots and
 * rebuilds the stylesheet. After a class style is written the stylesheet is
 * rebuilt here: GenerateBlocks' own save hook skips a request without a user
 * who may edit the post, which is what an approval from the CLI is.
 */
final class StyleApplier
{
    /** Agents may own class selectors only; :root and element/id styles stay human. */
    private const CLASS_SELECTOR = '/^\.[a-zA-Z_][a-zA-Z0-9_-]*$/';
    private const TOKEN_NAME     = '/^--[a-zA-Z_][a-zA-Z0-9_-]*$/';
    private const TOKEN_TYPES    = ['color', 'unit', 'text'];
    public const MAX_TOKENS      = 100;

    public function __construct(private readonly StyleReader $reader = new StyleReader())
    {
    }

    /** @return true|\WP_Error */
    public function validate_style(string $selector, mixed $styles, string $category): bool|\WP_Error
    {
        if (!StyleReader::available()) {
            return new \WP_Error('styles_unavailable', 'This site has no GenerateBlocks Pro global styles.', ['status' => 409]);
        }
        if (!preg_match(self::CLASS_SELECTOR, $selector)) {
            return new \WP_Error(
                'invalid_selector',
                'selector must be one class, e.g. ".kl-section--stone". Element, id and :root styles are not proposable; '
                    . 'tokens go through set_tokens.',
                ['status' => 400],
            );
        }
        if (!is_array($styles) || $styles === []) {
            return new \WP_Error(
                'invalid_styles',
                'styles must be a GenerateBlocks style object: camelCase properties, "&:hover"-style nested selectors '
                    . 'and "@media (…)" keys. See GET /styles for the existing ones.',
                ['status' => 400],
            );
        }
        $issues = StyleCompiler::validate($styles);
        if ($issues !== []) {
            return new \WP_Error('invalid_styles', implode(' ', $issues), ['status' => 400, 'issues' => $issues]);
        }
        if (mb_strlen($category) > 100) {
            return new \WP_Error('invalid_category', 'category is at most 100 characters.', ['status' => 400]);
        }

        return true;
    }

    /**
     * Create or update the style that owns the selector.
     *
     * @return int|\WP_Error the style's post id
     */
    public function apply_style(string $selector, array $styles, string $category): int|\WP_Error
    {
        $valid = $this->validate_style($selector, $styles, $category);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $css = StyleCompiler::compile($selector, $styles);
        $existing = $this->reader->find($selector);

        if ($existing !== null) {
            $id = $existing['id'];
            update_post_meta($id, 'gb_style_data', $styles);
            update_post_meta($id, 'gb_style_css', $css);
            update_post_meta($id, 'gb_style_category', $category);
            if (get_post_status($id) !== 'publish') {
                wp_update_post(['ID' => $id, 'post_status' => 'publish']);
            }
        } else {
            $id = wp_insert_post([
                'post_type'   => StyleReader::POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => $selector,
                'menu_order'  => $this->next_menu_order(),
                'meta_input'  => [
                    'gb_style_selector'    => $selector,
                    'gb_style_data'        => $styles,
                    'gb_style_css'         => $css,
                    'gb_style_targets'     => [],
                    'gb_style_targets_css' => '',
                    'gb_style_category'    => $category,
                ],
            ], true);
            if (is_wp_error($id)) {
                return $id;
            }
        }
        clean_post_cache((int) $id);
        self::rebuild();

        return (int) $id;
    }

    /**
     * Rows of a set_tokens proposal, normalised; or why not.
     *
     * Each row: name (required), value (required), and for a new token type
     * (color|unit|text), label, category and scope (camelCase CSS properties
     * whose pickers offer it). Rows for existing tokens keep whatever they omit.
     *
     * @return list<array>|\WP_Error
     */
    public function validate_tokens(mixed $rows): array|\WP_Error
    {
        if (!StyleReader::tokens_available()) {
            return new \WP_Error('tokens_unavailable', 'This site has no GenerateBlocks Pro design tokens (needs GB Pro 2.8+).', ['status' => 409]);
        }
        if (!is_array($rows) || $rows === [] || !array_is_list($rows)) {
            return new \WP_Error('invalid_tokens', 'tokens must be a list of {name, value, …} rows.', ['status' => 400]);
        }
        if (count($rows) > self::MAX_TOKENS) {
            return new \WP_Error('invalid_tokens', sprintf('At most %d tokens per proposal.', self::MAX_TOKENS), ['status' => 400]);
        }
        $known = [];
        foreach ($this->reader->tokens() as $token) {
            $known[$token['name']] = $token;
        }
        $out = [];
        $issues = [];
        foreach ($rows as $i => $row) {
            $name = (string) ($row['name'] ?? '');
            $value = trim((string) ($row['value'] ?? ''));
            if (!preg_match(self::TOKEN_NAME, $name)) {
                $issues[] = sprintf('Row %d: name must look like --space-md.', $i);
                continue;
            }
            if (!StyleCompiler::safe_value($value)) {
                $issues[] = sprintf('%s: the value is empty or not allowed.', $name);
                continue;
            }
            $type = (string) ($row['type'] ?? ($known[$name]['type'] ?? ''));
            if ($type === 'unregistered' || $type === '') {
                $type = str_starts_with($value, '#') || str_starts_with($value, 'rgb') || str_starts_with($value, 'hsl') ? 'color' : 'unit';
            }
            if (!in_array($type, self::TOKEN_TYPES, true)) {
                $issues[] = sprintf('%s: type must be color, unit or text.', $name);
                continue;
            }
            $scope = $row['scope'] ?? ($known[$name]['scope'] ?? []);
            if (!is_array($scope) || array_filter($scope, static fn ($s): bool => !is_string($s) || !preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $s))) {
                $issues[] = sprintf('%s: scope must be a list of camelCase CSS properties.', $name);
                continue;
            }
            $out[] = [
                'name'     => $name,
                'value'    => $value,
                'type'     => $type,
                'label'    => sanitize_text_field((string) ($row['label'] ?? ($known[$name]['label'] ?? ''))),
                'category' => sanitize_text_field((string) ($row['category'] ?? ($known[$name]['category'] ?? ''))),
                'scope'    => array_values($scope),
            ];
        }
        if ($issues !== []) {
            return new \WP_Error('invalid_tokens', implode(' ', $issues), ['status' => 400, 'issues' => $issues]);
        }

        return $out;
    }

    /**
     * Add or change the listed tokens; every other token stays as it is.
     *
     * @return true|\WP_Error
     */
    public function apply_tokens(array $rows): bool|\WP_Error
    {
        $rows = $this->validate_tokens($rows);
        if (is_wp_error($rows)) {
            return $rows;
        }
        if (\GenerateBlocks_Pro_Styles_Root::get_post() === null) {
            $created = \GenerateBlocks_Pro_Styles_Root::create_for_dashboard();
            if (is_wp_error($created)) {
                return $created;
            }
        }
        $state = \GenerateBlocks_Pro_Styles_Root::get_state();
        $styles = is_array($state['styles'] ?? null) ? $state['styles'] : [];
        $registry = array_values((array) ($state['tokens'] ?? []));
        $index = [];
        foreach ($registry as $i => $token) {
            $index[(string) ($token['name'] ?? '')] = $i;
        }
        foreach ($rows as $row) {
            $styles[$row['name']] = $row['value'];
            $entry = [
                'name'     => $row['name'],
                'type'     => $row['type'],
                'label'    => $row['label'],
                'category' => $row['category'],
                'scope'    => $row['scope'],
            ];
            if (isset($index[$row['name']])) {
                $registry[$index[$row['name']]] = array_merge($registry[$index[$row['name']]], $entry);
            } else {
                $registry[] = ['id' => wp_generate_uuid4()] + $entry;
            }
        }
        $css = StyleCompiler::compile(':root', $styles);
        $result = \GenerateBlocks_Pro_Styles_Root::save($styles, $css, (string) $state['checksum'], $registry);
        if (is_wp_error($result)) {
            return $result;
        }
        self::rebuild();

        return true;
    }

    /** GenerateBlocks' stylesheet (option cache and file) from what is stored now. */
    public static function rebuild(): void
    {
        if (class_exists('GenerateBlocks_Pro_Enqueue_Styles')) {
            \GenerateBlocks_Pro_Enqueue_Styles::get_instance()->build_css();
        }
    }

    private function next_menu_order(): int
    {
        global $wpdb;

        return 1 + (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(menu_order) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            StyleReader::POST_TYPE,
        ));
    }
}
