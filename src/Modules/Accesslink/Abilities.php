<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Accesslink as WordPress abilities (the Abilities API, WordPress 6.9+), so
 * an agent that speaks MCP through WordPress' MCP adapter — or any client of
 * /wp-abilities/v1 — can read the guide and the design system, compile a
 * section, read a page, propose a change and follow it, without an Accesslink
 * key: it runs as the logged-in WordPress user (an application password), and
 * only for Editors and above.
 *
 * Each ability is a thin pass-through to the REST handler behind the matching
 * Accesslink route, so the two surfaces cannot drift. A proposal still only
 * lands in the review queue; nothing is applied without a human, and the
 * writes kill switch applies here too. requested_by is "wp:<login>" plus the
 * agent's own label when it gives one.
 */
final class Abilities
{
    public const CATEGORY = 'valolink-accesslink';

    public function __construct(
        private readonly AccesslinkModule $module,
        private readonly AccesslinkAuth $auth,
    ) {
    }

    public function register(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        add_action('wp_abilities_api_categories_init', [$this, 'register_category']);
        add_action('wp_abilities_api_init', [$this, 'register_abilities']);
    }

    public function register_category(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label'       => 'Accesslink',
            'description' => 'Read this site and propose changes for a human to review (Valolink Accesslink).',
        ]);
    }

    public function register_abilities(): void
    {
        $read = ['readonly' => true, 'destructive' => false, 'idempotent' => true];

        $this->ability('valolink/accesslink-guide', 'Accesslink guide', 'How to work on this site through Accesslink: rules, what can be proposed, and sections (proposing, blocks, styles, menus…). Read it first; ask for a section by name for the detail.', [
            'type' => 'object',
            'properties' => ['section' => ['type' => 'string', 'description' => 'A section name from the index, e.g. "styles".']],
            'additionalProperties' => false,
        ], ['type' => 'object'], $read, fn (array $in) => $this->module->handle_guide($this->request('GET', ['section' => $in['section'] ?? null])));

        $this->ability('valolink/design-system', 'Design system', 'The design tokens, GenerateBlocks global styles and block patterns (with their slots) that content on this site is built from.', [
            'type' => 'object',
            'properties' => ['usage' => ['type' => 'boolean', 'description' => 'Also count the posts using each style.']],
            'additionalProperties' => false,
        ], ['type' => 'object'], $read, fn (array $in) => $this->module->handle_styles($this->request('GET', ['usage' => !empty($in['usage'])])));

        $this->ability('valolink/read-content', 'Read a page or post', 'One post or page as Accesslink reads it: fields, format, content, pending changes.', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['id'],
            'additionalProperties' => false,
        ], ['type' => 'object'], $read, fn (array $in) => $this->module->handle_content_get($this->request('GET', ['id' => (int) $in['id']])));

        // Path-addressed edits — update_text, insert_block, translations — need
        // the paths, and the REST route for them takes only the Accesslink
        // key. Block pages and Avada (Fusion) pages alike.
        $this->ability('valolink/read-structure', 'Read a page\'s blocks or elements', 'The page\'s blocks — or, on an Avada page, its Fusion elements — as addressable paths with their text, for update_text, insert_block, delete_block, move_block and create_translation.', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['id'],
            'additionalProperties' => false,
        ], ['type' => 'object'], $read, fn (array $in) => $this->module->handle_content_blocks($this->request('GET', ['id' => (int) $in['id']])));

        if (AvadaLayouts::available()) {
            $this->ability('valolink/avada-layouts', 'Avada Layouts', 'Which header, page title bar, content and footer each Avada Layout gives the pages its conditions match, and every Layout Section with where it is used.', [
                'type' => 'object',
                'properties' => new \stdClass(),
                'additionalProperties' => false,
            ], ['type' => 'object'], $read, fn (array $in) => $this->module->handle_layouts());
        }

        $this->ability('valolink/compile-section', 'Compile a section', 'Turn a short section description (element, heading, paragraph, text, button, list, image, pattern nodes) into block markup styled with the site\'s global styles. Writes nothing.', [
            'type' => 'object',
            'properties' => [
                'tree' => ['type' => ['object', 'array'], 'description' => 'A node or a list of nodes; see the guide\'s styles section.'],
                'seed' => ['type' => 'string'],
            ],
            'required' => ['tree'],
            'additionalProperties' => false,
        ], ['type' => 'object'], $read, fn (array $in) => $this->module->handle_compile($this->request('POST', [], $in)));

        $this->ability('valolink/propose-change', 'Propose a change', 'File a change for a human to review — the same body as POST /changes (action, post_id or target, fields or markup, note). Nothing is applied until someone approves it.', [
            'type' => 'object',
            'properties' => ['action' => ['type' => 'string']],
            'required' => ['action'],
            'additionalProperties' => true,
        ], ['type' => 'object'], ['readonly' => false, 'destructive' => false, 'idempotent' => false], fn (array $in) => $this->propose($in), true);

        $this->ability('valolink/change-status', 'Check a proposal', 'One proposed change: its status (pending, applied, rejected, stale, failed) and the reviewer\'s note.', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['id'],
            'additionalProperties' => false,
        ], ['type' => 'object'], $read, fn (array $in) => $this->module->handle_get($this->request('GET', ['id' => (int) $in['id']])));
    }

    // -------------------------------------------------------------------------

    private function ability(string $name, string $label, string $description, array $input, array $output, array $annotations, callable $run, bool $writes = false): void
    {
        wp_register_ability($name, [
            'label'               => $label,
            'description'         => $description,
            'category'            => self::CATEGORY,
            'input_schema'        => $input,
            'output_schema'       => $output,
            'execute_callback'    => function ($input = null) use ($run) {
                $result = $run(is_array($input) ? $input : []);

                return $result instanceof \WP_REST_Response ? $result->get_data() : $result;
            },
            'permission_callback' => fn () => $this->allowed($writes),
            // public is 7.1's switch for every channel; 6.9/7.0 know only
            // show_in_rest, and WordPress' MCP adapter reads its own mcp.public.
            'meta'                => ['public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => $annotations],
        ]);
    }

    /**
     * An Editor or above: Accesslink reads drafts and private content, which a
     * Contributor's edit_posts does not cover. Writes also need the site's
     * writes switch; approving stays in wp-admin.
     */
    private function allowed(bool $writes): bool|\WP_Error
    {
        if (!current_user_can('edit_others_posts')) {
            return new \WP_Error('forbidden', 'Accesslink abilities need a user who can edit other people\'s posts (Editor or above).', ['status' => 403]);
        }
        if ($writes && !$this->auth->writes_enabled()) {
            return new \WP_Error('writes_disabled', 'Accesslink writes are switched off for this site.', ['status' => 503]);
        }

        return true;
    }

    private function propose(array $input): mixed
    {
        $user = wp_get_current_user();
        $agent = isset($input['agent']) ? sanitize_text_field((string) $input['agent']) : '';
        unset($input['agent']);
        $request = $this->request('POST', [], $input);
        $request->set_header('x-accesslink-agent', trim('wp:' . $user->user_login . ' ' . $agent));

        return $this->module->handle_propose($request);
    }

    private function request(string $method, array $params, ?array $body = null): \WP_REST_Request
    {
        $request = new \WP_REST_Request($method);
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $request->set_param($key, $value);
            }
        }
        if ($body !== null) {
            $request->set_header('content-type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }

        return $request;
    }
}
