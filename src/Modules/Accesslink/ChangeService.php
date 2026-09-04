<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Modules\Accesslink\Seo\SeoAdapterFactory;
use Valolink\Plugin\Modules\Accesslink\Translation\TranslationAdapterFactory;
use Valolink\Plugin\Modules\Logging\EventLogger;
use Valolink\Plugin\Settings;

/**
 * The single implementation of propose / approve / reject.
 *
 * Both entry points — the REST routes and the wp-admin queue screen — come
 * through here, so approval behaves identically whichever drives it. That is
 * the seam that lets EngineLink aggregate queues across sites later without
 * any of this logic having to move.
 */
final class ChangeService
{
    /** Only a user who could publish the change by hand may approve it. */
    public const APPROVE_CAP = 'publish_posts';

    public function __construct(
        private readonly Settings $settings,
        private readonly ChangeRepository $repo,
        private readonly PostApplier $applier,
    ) {}

    /** @return array<int, string> */
    public function allowed_post_types(): array
    {
        $configured = $this->settings->get_module_setting(
            AccesslinkModule::MODULE_ID,
            'allowed_post_types',
            ['post', 'page'],
        );

        return is_array($configured) && $configured !== [] ? array_values($configured) : ['post', 'page'];
    }

    // -------------------------------------------------------------------------
    // Propose
    // -------------------------------------------------------------------------

    public function propose(array $input, ?string $requested_by): array|\WP_Error
    {
        $action = (string) ($input['action'] ?? '');
        if (!in_array($action, ChangeRepository::ACTIONS, true)) {
            return new \WP_Error(
                'bad_action',
                'action must be one of: ' . implode(', ', ChangeRepository::ACTIONS) . '.',
                ['status' => 400],
            );
        }

        $idempotency_key = isset($input['idempotency_key'])
            ? sanitize_text_field((string) $input['idempotency_key'])
            : null;

        // Agents retry. A repeated key returns the original row untouched
        // rather than filing a duplicate proposal.
        if ($idempotency_key !== null && $idempotency_key !== '') {
            $existing = $this->repo->find_by_idempotency_key($idempotency_key);
            if ($existing !== null) {
                return $existing;
            }
        }

        $note_early = isset($input['note']) ? sanitize_textarea_field((string) $input['note']) : null;

        // Block edits carry a path rather than a field map.
        if (in_array($action, [ChangeRepository::ACTION_UPDATE_BLOCK, ChangeRepository::ACTION_UPDATE_TEXT], true)) {
            return $this->announce(
                $this->propose_update_block($action, $input, $note_early, $requested_by, $idempotency_key),
            );
        }

        if (in_array($action, ChangeRepository::STRUCTURAL_ACTIONS, true)) {
            return $this->announce(
                $this->propose_structural($action, $input, $note_early, $requested_by, $idempotency_key),
            );
        }

        if ($action === ChangeRepository::ACTION_CREATE_TRANSLATION) {
            return $this->announce(
                $this->propose_create_translation($input, $note_early, $requested_by, $idempotency_key),
            );
        }

        if ($action === ChangeRepository::ACTION_UPDATE_MENU) {
            return $this->announce(
                $this->propose_update_menu($input, $note_early, $requested_by, $idempotency_key),
            );
        }

        if ($action === ChangeRepository::ACTION_SET_LANGUAGE) {
            return $this->announce(
                $this->propose_set_language($input, $note_early, $requested_by, $idempotency_key),
            );
        }

        $fields = $this->sanitize_fields($input['fields'] ?? []);
        if ($fields === []) {
            return new \WP_Error(
                'no_fields',
                'fields must contain at least one of: ' . implode(', ', $this->applier->allowed_fields()),
                ['status' => 400],
            );
        }

        $note = isset($input['note']) ? sanitize_textarea_field((string) $input['note']) : null;

        return $this->announce(
            $action === ChangeRepository::ACTION_CREATE
                ? $this->propose_create($input, $fields, $note, $requested_by, $idempotency_key)
                : $this->propose_update($input, $fields, $note, $requested_by, $idempotency_key),
        );
    }

    private function propose_create(
        array $input,
        array $fields,
        ?string $note,
        ?string $requested_by,
        ?string $idempotency_key,
    ): array|\WP_Error {
        $post_type = sanitize_key((string) ($input['post_type'] ?? 'post'));
        if (!in_array($post_type, $this->allowed_post_types(), true)) {
            return new \WP_Error('bad_post_type', 'post_type not permitted on this site.', ['status' => 400]);
        }

        $requested_status = sanitize_key((string) ($input['status'] ?? 'publish'));
        if (!in_array($requested_status, PostApplier::ALLOWED_STATUSES, true)) {
            return new \WP_Error('bad_status', 'Unsupported target status.', ['status' => 400]);
        }

        $invalid = $this->applier->validate($post_type, $fields);
        if (is_wp_error($invalid)) {
            return $invalid;
        }

        $draft_id = $this->applier->create_draft($fields, $post_type);
        if (is_wp_error($draft_id)) {
            return $draft_id;
        }

        $id = $this->repo->insert([
            'action'          => ChangeRepository::ACTION_CREATE,
            'target_id'       => $draft_id,
            'post_type'       => $post_type,
            'payload'         => ['fields' => $fields, 'requested_status' => $requested_status],
            'summary'         => $this->summarize($fields['post_title'] ?? '(untitled)'),
            'note'            => $note,
            'requested_by'    => $requested_by,
            'idempotency_key' => $idempotency_key,
        ]);

        $this->audit('accesslink_proposed', [
            'change_id' => $id,
            'action'    => 'create',
            'post_id'   => $draft_id,
            'by'        => $requested_by,
        ]);

        return $this->repo->find($id) ?? [];
    }

    private function propose_update(
        array $input,
        array $fields,
        ?string $note,
        ?string $requested_by,
        ?string $idempotency_key,
    ): array|\WP_Error {
        $target_id = (int) ($input['target_id'] ?? 0);
        if ($target_id <= 0) {
            return new \WP_Error('no_target', 'target_id is required for an update.', ['status' => 400]);
        }

        $post = get_post($target_id);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('not_found', 'No post with that id.', ['status' => 404]);
        }

        if (!in_array($post->post_type, $this->allowed_post_types(), true)) {
            return new \WP_Error('bad_post_type', 'post_type not permitted on this site.', ['status' => 400]);
        }

        $invalid = $this->applier->validate($post->post_type, $fields);
        if (is_wp_error($invalid)) {
            return $invalid;
        }

        $id = $this->repo->insert([
            'action'          => ChangeRepository::ACTION_UPDATE,
            'target_id'       => $target_id,
            'post_type'       => $post->post_type,
            'base_hash'       => $this->applier->hash($target_id, array_keys($fields)),
            'payload'         => ['fields' => $fields],
            'summary'         => $this->summarize($post->post_title),
            'note'            => $note,
            'requested_by'    => $requested_by,
            'idempotency_key' => $idempotency_key,
        ]);

        $this->audit('accesslink_proposed', [
            'change_id' => $id,
            'action'    => 'update',
            'post_id'   => $target_id,
            'by'        => $requested_by,
        ]);

        return $this->repo->find($id) ?? [];
    }

    private function propose_update_block(
        string $action,
        array $input,
        ?string $note,
        ?string $requested_by,
        ?string $idempotency_key,
    ): array|\WP_Error {
        $target_id = (int) ($input['target_id'] ?? 0);
        if ($target_id <= 0) {
            return new \WP_Error('no_target', 'target_id is required.', ['status' => 400]);
        }

        $post = get_post($target_id);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('not_found', 'No post with that id.', ['status' => 404]);
        }
        if (!in_array($post->post_type, $this->allowed_post_types(), true)) {
            return new \WP_Error('bad_post_type', 'post_type not permitted on this site.', ['status' => 400]);
        }

        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return new \WP_Error('no_path', 'path is required — see GET /content/{id}/blocks.', ['status' => 400]);
        }
        $is_text = $action === ChangeRepository::ACTION_UPDATE_TEXT;
        $key = $is_text ? 'text' : 'html';
        if (!array_key_exists($key, $input)) {
            return new \WP_Error('no_' . $key, sprintf('%s is required.', $key), ['status' => 400]);
        }
        $html = (string) $input[$key];

        $reader = new BlockReader();
        $block = $reader->get_at((string) $post->post_content, $path);
        if ($block === null) {
            return new \WP_Error('block_not_found', sprintf('No block at path %s.', $path), ['status' => 404]);
        }

        // Dry-run the replacement now rather than at approval, so a structural
        // failure reaches the agent instead of the reviewer's queue.
        $trial = $is_text
            ? $reader->replace_text_at((string) $post->post_content, $path, $html)
            : $reader->replace_at((string) $post->post_content, $path, $html);
        if (is_wp_error($trial)) {
            $trial->add_data(['status' => 400], $trial->get_error_code());

            return $trial;
        }

        // Cheap block-validity checks on the result. These cannot prove
        // Gutenberg will accept it — that verdict lives in JavaScript — but
        // they catch the mistakes an agent actually makes.
        $issues = (new BlockValidator())->check_diff((string) $post->post_content, $trial);
        if ($issues !== []) {
            return new \WP_Error(
                'invalid_block_markup',
                implode(' ', $issues),
                ['status' => 400, 'issues' => $issues],
            );
        }

        $id = $this->repo->insert([
            'action'          => $action,
            'target_id'       => $target_id,
            'post_type'       => $post->post_type,
            'base_hash'       => $this->block_hash($post, $path),
            'payload'         => [
                'path'          => $path,
                'html'          => $html,
                'block_name'    => $block['name'],
                'previous_html' => $block['html'],
            ],
            'summary'         => $this->summarize($post->post_title . ' — ' . $block['name']),
            'note'            => $note,
            'requested_by'    => $requested_by,
            'idempotency_key' => $idempotency_key,
        ]);

        $this->audit('accesslink_proposed', [
            'change_id' => $id,
            'action'    => $action,
            'post_id'   => $target_id,
            'path'      => $path,
            'by'        => $requested_by,
        ]);

        return $this->repo->find($id) ?? [];
    }

    /**
     * Adding, removing or moving a block.
     *
     * Staleness here hashes the whole document rather than one block: paths are
     * positional, so any structural change elsewhere means "after the second
     * paragraph" no longer refers to what the agent meant.
     */
    private function propose_structural(
        string $action,
        array $input,
        ?string $note,
        ?string $requested_by,
        ?string $idempotency_key,
    ): array|\WP_Error {
        $target_id = (int) ($input['target_id'] ?? 0);
        $post = $target_id > 0 ? get_post($target_id) : null;
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('not_found', 'No post with that id.', ['status' => 404]);
        }
        if (!in_array($post->post_type, $this->allowed_post_types(), true)) {
            return new \WP_Error('bad_post_type', 'post_type not permitted on this site.', ['status' => 400]);
        }

        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return new \WP_Error('no_path', 'path is required — see GET /content/{id}/blocks.', ['status' => 400]);
        }

        $reader = new BlockReader();
        $content = (string) $post->post_content;
        $payload = ['path' => $path];

        switch ($action) {
            case ChangeRepository::ACTION_INSERT_BLOCK:
                $markup = (string) ($input['markup'] ?? '');
                if (trim($markup) === '') {
                    return new \WP_Error('no_markup', 'markup is required.', ['status' => 400]);
                }
                $position = (string) ($input['position'] ?? 'after');
                $trial = $reader->insert_block($content, $path, $position, $markup);
                $payload += ['markup' => $markup, 'position' => $position];
                break;

            case ChangeRepository::ACTION_DELETE_BLOCK:
                $trial = $reader->delete_block($content, $path);
                break;

            default:
                $target_path = trim((string) ($input['target_path'] ?? ''));
                if ($target_path === '') {
                    return new \WP_Error('no_target_path', 'target_path is required.', ['status' => 400]);
                }
                $position = (string) ($input['position'] ?? 'after');
                $trial = $reader->move_block($content, $path, $target_path, $position);
                $payload += ['target_path' => $target_path, 'position' => $position];
                break;
        }

        if (is_wp_error($trial)) {
            $trial->add_data(['status' => 400], $trial->get_error_code());

            return $trial;
        }

        $issues = (new BlockValidator())->check_diff($content, $trial);
        if ($issues !== []) {
            return new \WP_Error('invalid_block_markup', implode(' ', $issues), ['status' => 400, 'issues' => $issues]);
        }

        $block = $reader->get_at($content, $path);
        $id = $this->repo->insert([
            'action'          => $action,
            'target_id'       => $target_id,
            'post_type'       => $post->post_type,
            'base_hash'       => $this->content_hash($post),
            'payload'         => $payload,
            'summary'         => $this->summarize($post->post_title . ' — ' . ($block['name'] ?? $path)),
            'note'            => $note,
            'requested_by'    => $requested_by,
            'idempotency_key' => $idempotency_key,
        ]);

        $this->audit('accesslink_proposed', [
            'change_id' => $id,
            'action'    => $action,
            'post_id'   => $target_id,
            'path'      => $path,
            'by'        => $requested_by,
        ]);

        return $this->repo->find($id) ?? [];
    }

    /** Menus are site structure rather than content, so they are opt-in per site. */
    public function menus_enabled(): bool
    {
        return (bool) $this->settings->get_module_setting(
            AccesslinkModule::MODULE_ID,
            'allow_menu_edits',
            false,
        );
    }

    /**
     * Replace a menu's whole item tree.
     *
     * Whole-tree rather than per-item because that is the only form in which a
     * menu is reviewable: "relabel two, repoint one, nest the last" is several
     * proposals to hold in your head, where one tree is a single before-and-
     * after to read. It also makes staleness honest — the thing being replaced
     * is the menu, so the thing hashed is the menu.
     */
    private function propose_update_menu(
        array $input,
        ?string $note,
        ?string $requested_by,
        ?string $idempotency_key,
    ): array|\WP_Error {
        if (!$this->menus_enabled()) {
            return new \WP_Error(
                'menus_disabled',
                'Menu editing is switched off for this site. An operator can enable it under '
                    . 'Valolink → Accesslink.',
                ['status' => 403],
            );
        }

        $menu_id = (int) ($input['menu_id'] ?? 0);
        $menu = $menu_id > 0 ? wp_get_nav_menu_object($menu_id) : false;
        if (!$menu) {
            return new \WP_Error('not_found', 'No menu with that id — see GET /menus.', ['status' => 404]);
        }

        $items = $input['items'] ?? null;
        if (!is_array($items)) {
            return new \WP_Error(
                'no_items',
                'items must be the menu\'s full item tree. Read GET /menus/{id}, change what you need, '
                    . 'and send the whole thing back — anything you leave out is removed.',
                ['status' => 400],
            );
        }
        if ($items === []) {
            return new \WP_Error(
                'empty_menu',
                'That would remove every item. Emptying a menu through this API is not supported; '
                    . 'if it is really intended, a human can do it in wp-admin.',
                ['status' => 400],
            );
        }

        $valid = (new MenuApplier())->validate($menu_id, $items, $this->allowed_post_types());
        if (is_wp_error($valid)) {
            return $valid;
        }

        $reader = new MenuReader();
        $id = $this->repo->insert([
            'action'          => ChangeRepository::ACTION_UPDATE_MENU,
            'entity_type'     => 'menu',
            'target_id'       => $menu_id,
            'post_type'       => 'nav_menu',
            'base_hash'       => $reader->hash($menu_id),
            'payload'         => ['items' => $items, 'menu_name' => $menu->name],
            'summary'         => $this->summarize(sprintf('Menu: %s', $menu->name)),
            'note'            => $note,
            'requested_by'    => $requested_by,
            'idempotency_key' => $idempotency_key,
        ]);

        $this->audit('accesslink_proposed', [
            'change_id' => $id,
            'action'    => ChangeRepository::ACTION_UPDATE_MENU,
            'menu_id'   => $menu_id,
            'by'        => $requested_by,
        ]);

        return $this->repo->find($id) ?? [];
    }

    /**
     * Give a post a language it does not have.
     *
     * Exists because without it the translation flow dead-ends: content that
     * predates the multilingual plugin has no language, `create_translation`
     * refuses it, and there was no way to fix that except in wp-admin — so an
     * agent asked to translate such a site could not even start.
     *
     * Deliberately only fills a gap. Changing a language a post already has
     * would silently tear it out of its translation group, which is a data
     * decision rather than a content one and belongs with a human.
     */
    private function propose_set_language(
        array $input,
        ?string $note,
        ?string $requested_by,
        ?string $idempotency_key,
    ): array|\WP_Error {
        $tr = TranslationAdapterFactory::detect();
        if (!$tr->available()) {
            return new \WP_Error(
                'translation_unavailable',
                sprintf('No writable multilingual plugin on this site (detected: %s).', $tr->plugin()),
                ['status' => 400],
            );
        }

        $target_id = (int) ($input['target_id'] ?? 0);
        $post = $target_id > 0 ? get_post($target_id) : null;
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('not_found', 'No post with that id.', ['status' => 404]);
        }
        if (!in_array($post->post_type, $this->allowed_post_types(), true)) {
            return new \WP_Error('bad_post_type', 'post_type not permitted on this site.', ['status' => 400]);
        }
        if (!$tr->is_translated_type($post->post_type)) {
            return new \WP_Error(
                'type_not_translated',
                sprintf('%s is not a translated post type on this site.', $post->post_type),
                ['status' => 400],
            );
        }

        $languages = array_column($tr->languages(), 'slug');
        $lang = sanitize_key((string) ($input['lang'] ?? ''));
        if ($lang === '' || !in_array($lang, $languages, true)) {
            return new \WP_Error(
                'bad_language',
                sprintf('lang must be one of: %s.', implode(', ', $languages)),
                ['status' => 400],
            );
        }

        $current = $tr->language_of($target_id);
        if ($current !== '') {
            return new \WP_Error(
                'already_has_language',
                sprintf(
                    'That post is already in %s. Changing an assigned language would remove it from its '
                        . 'translation group, so it is left to a human.',
                    $current,
                ),
                ['status' => 409],
            );
        }

        $id = $this->repo->insert([
            'action'          => ChangeRepository::ACTION_SET_LANGUAGE,
            'target_id'       => $target_id,
            'post_type'       => $post->post_type,
            // Nothing about the content matters here, only that the post still
            // has no language when a human gets to it.
            'base_hash'       => hash('sha256', ''),
            'payload'         => ['lang' => $lang],
            'summary'         => $this->summarize(sprintf('%s → language %s', $post->post_title, $lang)),
            'note'            => $note,
            'requested_by'    => $requested_by,
            'idempotency_key' => $idempotency_key,
        ]);

        $this->audit('accesslink_proposed', [
            'change_id' => $id,
            'action'    => ChangeRepository::ACTION_SET_LANGUAGE,
            'post_id'   => $target_id,
            'lang'      => $lang,
            'by'        => $requested_by,
        ]);

        return $this->repo->find($id) ?? [];
    }

    /**
     * A translation of an existing post, as a draft linked into its group.
     *
     * The agent never sends `post_content`. It sends a map of block path =>
     * translated text, and the document is cloned from the source with only
     * those leaves replaced. That is the whole point: regenerating the markup
     * of a GenerateBlocks page is how an agent destroys one, and a translation
     * is the case where it would be most tempting — the structure is supposed
     * to be identical, so there is no reason to rebuild it. Cloning also means
     * the translation inherits every wrapper, class and attribute for free, and
     * the block-name sequence is guaranteed to match by construction.
     */
    private function propose_create_translation(
        array $input,
        ?string $note,
        ?string $requested_by,
        ?string $idempotency_key,
    ): array|\WP_Error {
        $tr = TranslationAdapterFactory::detect();
        if (!$tr->available()) {
            return new \WP_Error(
                'translation_unavailable',
                sprintf('No writable multilingual plugin on this site (detected: %s).', $tr->plugin()),
                ['status' => 400],
            );
        }

        $source_id = (int) ($input['target_id'] ?? 0);
        $source = $source_id > 0 ? get_post($source_id) : null;
        if (!$source instanceof \WP_Post) {
            return new \WP_Error('not_found', 'No post with that id.', ['status' => 404]);
        }
        if (!in_array($source->post_type, $this->allowed_post_types(), true)) {
            return new \WP_Error('bad_post_type', 'post_type not permitted on this site.', ['status' => 400]);
        }
        if (!$tr->is_translated_type($source->post_type)) {
            return new \WP_Error(
                'type_not_translated',
                sprintf('%s is not a translated post type on this site.', $source->post_type),
                ['status' => 400],
            );
        }

        $languages = array_column($tr->languages(), 'slug');
        $lang = sanitize_key((string) ($input['lang'] ?? ''));
        if ($lang === '' || !in_array($lang, $languages, true)) {
            return new \WP_Error(
                'bad_language',
                sprintf('lang must be one of: %s.', implode(', ', $languages)),
                ['status' => 400],
            );
        }

        $source_lang = $tr->language_of($source_id);
        if ($source_lang === '') {
            return new \WP_Error(
                'source_has_no_language',
                'The source post has no language assigned, so nothing can be linked to it. '
                    . 'Assign one in wp-admin first.',
                ['status' => 409],
            );
        }
        if ($source_lang === $lang) {
            return new \WP_Error('same_language', 'The source is already in that language.', ['status' => 400]);
        }

        $group = $tr->translations($source_id);
        if (isset($group[$lang])) {
            return new \WP_Error(
                'already_translated',
                sprintf(
                    'Already translated into %s (post %d). Propose an update against that post instead.',
                    $lang,
                    $group[$lang],
                ),
                ['status' => 409],
            );
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            return new \WP_Error('no_title', 'title is required.', ['status' => 400]);
        }

        // Optional: a language-neutral post — a GeneratePress Element that only
        // injects CSS or a tracking script, say — needs a counterpart in the
        // target language to run there at all, but nothing in it to translate.
        $texts = $input['texts'] ?? [];
        if (!is_array($texts)) {
            return new \WP_Error(
                'bad_texts',
                'texts must map block paths to translated text — see GET /content/{id}/blocks for the paths.',
                ['status' => 400],
            );
        }

        // replace_text_at only ever swaps a wrapper's inner HTML, so the tree
        // never reshapes and every path stays valid across the whole loop.
        $reader  = new BlockReader();
        $content = (string) $source->post_content;
        foreach ($texts as $path => $text) {
            $path = (string) $path;
            if ($reader->get_at($content, $path) === null) {
                return new \WP_Error(
                    'block_not_found',
                    sprintf('No block at path %s.', $path),
                    ['status' => 404],
                );
            }

            // inline_only off: the replacement is the source block's own inner
            // HTML with the words swapped, so it legitimately carries the icons
            // and anchors that were already there. The skeleton comparison
            // below is what proves nothing else changed.
            $replaced = $reader->replace_text_at($content, $path, (string) $text, false);
            if (is_wp_error($replaced)) {
                $replaced->add_data(['status' => 400, 'path' => $path], $replaced->get_error_code());

                return $replaced;
            }
            $content = $replaced;
        }

        // A translation may change words and nothing else. Comparing the markup
        // skeleton — every tag with its attributes, in order, text removed —
        // enforces that far more tightly than a sanitiser could, and without a
        // sanitiser's damage: ContentSanitizer would strip the style attribute
        // off the <mark> highlights and parts of the inline SVG icons, because
        // it is built for content an agent authored, not for a clone of the
        // site's own markup. Anything an agent might inject is a tag or an
        // attribute, so any injection shows up here as a mismatch.
        if (self::markup_skeleton((string) $source->post_content) !== self::markup_skeleton($content)) {
            return new \WP_Error(
                'markup_changed',
                'A translation may only change text, not markup. Send the source block\'s own HTML with '
                    . 'the words replaced — tags, attributes and inline SVG must come back unchanged.',
                ['status' => 400],
            );
        }

        $issues = (new BlockValidator())->check_diff((string) $source->post_content, $content);
        if ($issues !== []) {
            return new \WP_Error('invalid_block_markup', implode(' ', $issues), ['status' => 400, 'issues' => $issues]);
        }

        $postarr = [
            'post_type'    => $source->post_type,
            'post_status'  => 'draft',
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => sanitize_textarea_field((string) ($input['excerpt'] ?? '')),
        ];
        if (!empty($input['slug'])) {
            $postarr['post_name'] = sanitize_title((string) $input['slug']);
        }

        // WordPress runs post_content through kses on save whenever the current
        // user lacks unfiltered_html — and an Accesslink request has no user at
        // all. That strips precisely what ContentSanitizer exists to preserve:
        // on this site's front page it removed all 21 inline SVG icons and every
        // <mark> style attribute, silently, after every check above had passed.
        // The content is provably the site's own markup by the skeleton check,
        // so the filters come off for the insert and go straight back on.
        $kses_was_on = has_filter('content_save_pre', 'wp_filter_post_kses');
        if ($kses_was_on) {
            kses_remove_filters();
        }

        $new_id = $tr->insert(wp_slash($postarr), $lang, $group + [$source_lang => $source_id]);

        if ($kses_was_on) {
            kses_init_filters();
        }

        if (is_wp_error($new_id)) {
            $new_id->add_data(['status' => 500], $new_id->get_error_code());

            return $new_id;
        }

        // Carry the source's postmeta over. Without this a translated
        // GeneratePress Element is inert: its type, hook, priority and display
        // conditions all live in _generate_* meta, so the copy would sit there
        // as an unreferenced draft that never renders anywhere. Polylang's own
        // admin does the same thing when you create a translation.
        self::copy_meta($source_id, (int) $new_id);

        // Verify rather than trust: anything else hooked into the save path can
        // rewrite content too, and a half-mangled translation left in the queue
        // is worse than none. Deleted outright rather than trashed — nobody has
        // reviewed it, so there is nothing to recover.
        $stored = get_post((int) $new_id);
        if (!$stored instanceof \WP_Post
            || self::markup_skeleton((string) $stored->post_content) !== self::markup_skeleton($content)) {
            wp_delete_post((int) $new_id, true);

            return new \WP_Error(
                'content_altered_on_save',
                'The site altered the translation while saving it, so it no longer matches the source '
                    . 'markup. The draft was removed rather than queued.',
                ['status' => 500],
            );
        }

        // Status the translation should reach on approval. Defaults to whatever
        // the source is: a translation of a published page is normally meant to
        // be published, and defaulting to `publish` regardless would quietly
        // publish the translation of a draft.
        $requested_status = (string) ($input['status'] ?? $source->post_status);

        $id = $this->repo->insert([
            'action'          => ChangeRepository::ACTION_CREATE_TRANSLATION,
            'target_id'       => (int) $new_id,
            'post_type'       => $source->post_type,
            // Hashes the *source*: if the original moves before approval the
            // translation is answering a stale question, exactly as an update
            // would be.
            'base_hash'       => $this->content_hash($source),
            'payload'         => [
                'lang'             => $lang,
                'source_id'        => $source_id,
                'source_lang'      => $source_lang,
                'translated_paths' => array_keys($texts),
                'requested_status' => $requested_status,
            ],
            'summary'         => $this->summarize(sprintf('%s → %s: %s', $source->post_title, $lang, $title)),
            'note'            => $note,
            'requested_by'    => $requested_by,
            'idempotency_key' => $idempotency_key,
        ]);

        $this->audit('accesslink_proposed', [
            'change_id' => $id,
            'action'    => ChangeRepository::ACTION_CREATE_TRANSLATION,
            'post_id'   => (int) $new_id,
            'source_id' => $source_id,
            'lang'      => $lang,
            'by'        => $requested_by,
        ]);

        return $this->repo->find($id) ?? [];
    }

    /**
     * Single funnel for "a change was just queued", so a future action type
     * cannot silently skip telling anyone. Never allowed to affect the result:
     * the change is already safely stored by this point.
     */
    private function announce(array|\WP_Error $result): array|\WP_Error
    {
        if (!is_wp_error($result) && ($result['status'] ?? '') === ChangeRepository::STATUS_PENDING) {
            (new ChangeNotifier($this->settings))->notify_queued($result);
        }

        return $result;
    }

    /** Whole-document digest, for changes whose meaning depends on structure. */
    private function content_hash(\WP_Post $post): string
    {
        return hash('sha256', $post->post_modified_gmt . '|' . $post->post_content);
    }

    /**
     * Staleness for a block edit covers the block itself, not the whole post —
     * so unrelated edits elsewhere on the page don't needlessly invalidate it,
     * while a change to this block (or its disappearance) does.
     */
    private function block_hash(\WP_Post $post, string $path): string
    {
        $block = (new BlockReader())->get_at((string) $post->post_content, $path);

        return hash('sha256', (string) wp_json_encode([
            'path' => $path,
            'name' => $block['name'] ?? null,
            'html' => $block['html'] ?? null,
        ]));
    }

    // -------------------------------------------------------------------------
    // Approve / reject
    // -------------------------------------------------------------------------

    public function approve(int $id): array|\WP_Error
    {
        if (!current_user_can(self::APPROVE_CAP)) {
            return new \WP_Error('forbidden', 'You cannot approve changes.', ['status' => 403]);
        }

        $change = $this->repo->find($id);
        if ($change === null) {
            return new \WP_Error('not_found', 'No such change.', ['status' => 404]);
        }
        if ($change['status'] !== ChangeRepository::STATUS_PENDING) {
            return new \WP_Error('not_pending', 'That change is no longer pending.', ['status' => 409]);
        }

        $target_id = (int) $change['target_id'];
        $fields    = $change['payload']['fields'] ?? [];

        if (in_array($change['action'], ChangeRepository::STRUCTURAL_ACTIONS, true)) {
            $post = get_post($target_id);
            if (!$post instanceof \WP_Post) {
                return $this->fail($id, 'Target post no longer exists.');
            }
            if (!hash_equals((string) $change['base_hash'], $this->content_hash($post))) {
                return $this->mark_stale(
                    $id,
                    $target_id,
                    'The page changed after this was proposed, so the block positions no longer mean the same thing. Re-read it and propose again.',
                );
            }

            $content = $this->proposed_content($change, $post);
            if (is_wp_error($content)) {
                return $this->fail($id, $content->get_error_message());
            }

            $result = $this->applier->apply_raw_content($target_id, (string) $content);
        } elseif (in_array($change['action'], [ChangeRepository::ACTION_UPDATE_BLOCK, ChangeRepository::ACTION_UPDATE_TEXT], true)) {
            $post = get_post($target_id);
            if (!$post instanceof \WP_Post) {
                return $this->fail($id, 'Target post no longer exists.');
            }

            $path = (string) ($change['payload']['path'] ?? '');
            if (!hash_equals((string) $change['base_hash'], $this->block_hash($post, $path))) {
                $this->repo->update($id, [
                    'status'      => ChangeRepository::STATUS_STALE,
                    'reviewed_by' => get_current_user_id() ?: null,
                    'reviewed_at' => current_time('mysql', true),
                    'error'       => 'That block changed after this was proposed; re-read it and propose again.',
                ]);
                $this->audit('accesslink_stale', ['change_id' => $id, 'post_id' => $target_id], 'warning');

                return $this->repo->find($id) ?? [];
            }

            $content = $this->proposed_content($change, $post);
            if (is_wp_error($content)) {
                return $this->fail($id, $content->get_error_message());
            }

            $result = $this->applier->apply_raw_content($target_id, (string) $content);
        } elseif ($change['action'] === ChangeRepository::ACTION_UPDATE) {
            // Staleness gate. Someone editing the post between proposal and
            // approval must not have their work silently overwritten.
            $current = $this->applier->hash($target_id, array_keys($fields));
            if ($current === '' ) {
                return $this->fail($id, 'Target post no longer exists.');
            }
            if (!hash_equals((string) $change['base_hash'], $current)) {
                $this->repo->update($id, [
                    'status'      => ChangeRepository::STATUS_STALE,
                    'reviewed_by' => get_current_user_id() ?: null,
                    'reviewed_at' => current_time('mysql', true),
                    'error'       => 'Post changed after this was proposed; re-propose against the current version.',
                ]);
                $this->audit('accesslink_stale', ['change_id' => $id, 'post_id' => $target_id], 'warning');

                return $this->repo->find($id) ?? [];
            }

            $result = $this->applier->apply_update($target_id, $fields);
        } elseif ($change['action'] === ChangeRepository::ACTION_UPDATE_MENU) {
            if (!$this->menus_enabled()) {
                return $this->fail($id, 'Menu editing was switched off after this was proposed.');
            }
            if (!wp_get_nav_menu_object($target_id)) {
                return $this->fail($id, 'That menu no longer exists.');
            }

            $reader = new MenuReader();
            if (!hash_equals((string) $change['base_hash'], $reader->hash($target_id))) {
                return $this->mark_stale(
                    $id,
                    $target_id,
                    'The menu changed after this was proposed, so applying it would undo that. '
                        . 'Re-read the menu and propose again.',
                );
            }

            $applier = new MenuApplier();
            $valid = $applier->validate($target_id, (array) ($change['payload']['items'] ?? []), $this->allowed_post_types());
            if (is_wp_error($valid)) {
                return $this->fail($id, $valid->get_error_message());
            }

            $result = $applier->apply($target_id, (array) ($change['payload']['items'] ?? []));
        } elseif ($change['action'] === ChangeRepository::ACTION_SET_LANGUAGE) {
            $tr = TranslationAdapterFactory::detect();
            if (!get_post($target_id) instanceof \WP_Post) {
                return $this->fail($id, 'Target post no longer exists.');
            }
            // Someone may have assigned one in wp-admin in the meantime; that is
            // the whole staleness question for this action.
            $current = $tr->language_of($target_id);
            if ($current !== '') {
                return $this->mark_stale(
                    $id,
                    $target_id,
                    sprintf('That post was given the language %s after this was proposed.', $current),
                );
            }

            $result = $tr->set_language($target_id, (string) ($change['payload']['lang'] ?? ''));
        } elseif ($change['action'] === ChangeRepository::ACTION_CREATE_TRANSLATION) {
            // The staleness gate points at the *source*, not the draft: the
            // draft is inert and nobody else edits it, but if the original moved
            // the translation is no longer a translation of what is published.
            $source_id = (int) ($change['payload']['source_id'] ?? 0);
            $source = $source_id > 0 ? get_post($source_id) : null;
            if (!$source instanceof \WP_Post) {
                return $this->fail($id, 'The source post no longer exists.');
            }
            if (!hash_equals((string) $change['base_hash'], $this->content_hash($source))) {
                return $this->mark_stale(
                    $id,
                    $target_id,
                    'The source page changed after this translation was drafted, so the two no longer match. '
                        . 'Re-read the source and propose again.',
                );
            }

            $result = $this->applier->set_status(
                $target_id,
                (string) ($change['payload']['requested_status'] ?? 'publish'),
            );
        } else {
            $status = (string) ($change['payload']['requested_status'] ?? 'publish');
            $result = $this->applier->set_status($target_id, $status);
        }

        if (is_wp_error($result)) {
            return $this->fail($id, $result->get_error_message());
        }

        $this->repo->update($id, [
            'status'      => ChangeRepository::STATUS_APPLIED,
            'reviewed_by' => get_current_user_id() ?: null,
            'reviewed_at' => current_time('mysql', true),
            'error'       => null,
        ]);

        $this->audit('accesslink_applied', [
            'change_id' => $id,
            'action'    => $change['action'],
            'post_id'   => $target_id,
        ]);

        return $this->repo->find($id) ?? [];
    }

    /**
     * The whole `post_content` a block-level or structural change would produce,
     * computed against the post exactly as it stands right now.
     *
     * Single implementation behind approval, the review diff and the front-end
     * preview. Those three disagreeing is precisely how a reviewer ends up
     * approving something other than what they were shown, so they share this
     * instead of each re-deriving it — the same reason the field diff resolves
     * through PostApplier::current_value().
     *
     * Returns null for actions that never rewrite post_content (`create`,
     * `update`), and a WP_Error when the document has moved far enough that the
     * change no longer addresses anything.
     */
    public function proposed_content(array $change, \WP_Post $post): string|\WP_Error|null
    {
        $reader  = new BlockReader();
        $payload = $change['payload'] ?? [];
        $content = (string) $post->post_content;
        $path    = (string) ($payload['path'] ?? '');

        return match ($change['action']) {
            // For update_text the payload's `html` is the block's *inner* text,
            // not its markup — so it has to go through replace_text_at, which
            // leaves the wrapper element byte-identical. Sending it to
            // replace_at would swallow the wrapper and invalidate the block.
            ChangeRepository::ACTION_UPDATE_TEXT => $reader->replace_text_at(
                $content,
                $path,
                (string) ($payload['html'] ?? ''),
            ),
            ChangeRepository::ACTION_UPDATE_BLOCK => $reader->replace_at(
                $content,
                $path,
                (string) ($payload['html'] ?? ''),
            ),
            ChangeRepository::ACTION_INSERT_BLOCK => $reader->insert_block(
                $content,
                $path,
                (string) ($payload['position'] ?? 'after'),
                (string) ($payload['markup'] ?? ''),
            ),
            ChangeRepository::ACTION_DELETE_BLOCK => $reader->delete_block($content, $path),
            ChangeRepository::ACTION_MOVE_BLOCK => $reader->move_block(
                $content,
                $path,
                (string) ($payload['target_path'] ?? ''),
                (string) ($payload['position'] ?? 'after'),
            ),
            default => null,
        };
    }

    /**
     * Postmeta that must not follow a post into its translation.
     *
     * Edit locks belong to a session, and Polylang keys its own state off the
     * taxonomies rather than meta, so everything else is behaviour the copy
     * needs in order to act like the original.
     */
    private const META_NOT_COPIED = ['_edit_lock', '_edit_last'];

    private static function copy_meta(int $from, int $to): void
    {
        foreach (get_post_meta($from) as $key => $values) {
            if (in_array($key, self::META_NOT_COPIED, true)) {
                continue;
            }
            delete_post_meta($to, $key);
            foreach ((array) $values as $value) {
                // get_post_meta returns raw serialised strings; add_post_meta
                // re-serialises, so a value has to be unserialised once first
                // or arrays end up double-encoded.
                add_post_meta($to, $key, maybe_unserialize($value));
            }
        }
    }

    /**
     * Every tag and block delimiter in order, with attributes, text removed.
     *
     * Two documents with the same skeleton differ only in their words. Block
     * delimiters are HTML comments and are captured too, so a change to block
     * attributes shows up as readily as a changed element.
     */
    private static function markup_skeleton(string $html): string
    {
        preg_match_all('/<[^>]*>/', $html, $matches);

        return implode('', $matches[0]);
    }

    private function mark_stale(int $id, int $target_id, string $message): array
    {
        $this->repo->update($id, [
            'status'      => ChangeRepository::STATUS_STALE,
            'reviewed_by' => get_current_user_id() ?: null,
            'reviewed_at' => current_time('mysql', true),
            'error'       => $message,
        ]);
        $this->audit('accesslink_stale', ['change_id' => $id, 'post_id' => $target_id], 'warning');

        return $this->repo->find($id) ?? [];
    }

    public function reject(int $id, ?string $reason = null): array|\WP_Error
    {
        if (!current_user_can(self::APPROVE_CAP)) {
            return new \WP_Error('forbidden', 'You cannot reject changes.', ['status' => 403]);
        }

        $change = $this->repo->find($id);
        if ($change === null) {
            return new \WP_Error('not_found', 'No such change.', ['status' => 404]);
        }
        if ($change['status'] !== ChangeRepository::STATUS_PENDING) {
            return new \WP_Error('not_pending', 'That change is no longer pending.', ['status' => 409]);
        }

        // A rejected create leaves an orphan draft behind, so bin it. Trash
        // rather than delete — recoverable if the rejection was a mis-click.
        // A translation draft is the same shape and leaves a half-linked
        // translation group if it stays.
        if (in_array($change['action'], ChangeRepository::DRAFT_ACTIONS, true) && $change['target_id']) {
            wp_trash_post((int) $change['target_id']);
        }

        // The reason is the agent's only feedback channel. Without it a
        // rejection teaches nothing and the same proposal comes back.
        $this->repo->update($id, [
            'status'      => ChangeRepository::STATUS_REJECTED,
            'reviewed_by' => get_current_user_id() ?: null,
            'reviewed_at' => current_time('mysql', true),
            'review_note' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
        ]);

        $this->audit('accesslink_rejected', [
            'change_id' => $id,
            'post_id'   => $change['target_id'],
        ]);

        return $this->repo->find($id) ?? [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Keep only the fields an agent may set on this site, sanitising each by
     * its kind. Four kinds now live side by side — post columns, SEO strings,
     * taxonomy slug lists and an attachment id — so the switch is explicit
     * rather than a single cast.
     */
    private function sanitize_fields(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $allowed = $this->applier->allowed_fields();
        $out = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $raw)) {
                continue;
            }
            $value = $raw[$field];

            if ($field === 'post_content') {
                // Markup is kept — PostApplier decides how far to filter it at
                // apply time, mirroring WP's own unfiltered_html rule.
                $out[$field] = (string) $value;
                continue;
            }

            if (in_array($field, SeoAdapterFactory::FIELDS, true)) {
                $clean = sanitize_text_field((string) $value);
                $max = SeoAdapterFactory::MAX[$field] ?? 500;
                $out[$field] = mb_substr($clean, 0, $max);
                continue;
            }

            if (isset(PostApplier::TERM_FIELDS[$field])) {
                // Accepts slugs or names; PostApplier resolves them and refuses
                // anything that doesn't already exist.
                $terms = is_array($value) ? $value : [$value];
                $out[$field] = array_values(array_filter(array_map(
                    static fn ($t): string => sanitize_text_field((string) $t),
                    $terms,
                )));
                continue;
            }

            if ($field === PostApplier::MEDIA_FIELD) {
                $out[$field] = (int) $value;
                continue;
            }

            $out[$field] = sanitize_text_field((string) $value);
        }

        return $out;
    }

    private function summarize(string $title): string
    {
        $title = wp_strip_all_tags($title);
        return mb_substr($title !== '' ? $title : '(untitled)', 0, 200);
    }

    private function fail(int $id, string $message): \WP_Error
    {
        $this->repo->update($id, [
            'status'      => ChangeRepository::STATUS_FAILED,
            'reviewed_by' => get_current_user_id() ?: null,
            'reviewed_at' => current_time('mysql', true),
            'error'       => $message,
        ]);
        $this->audit('accesslink_failed', ['change_id' => $id, 'error' => $message], 'error');

        return new \WP_Error('apply_failed', $message, ['status' => 500]);
    }

    /**
     * Best-effort audit. The Logging module may be switched off, in which case
     * its table is absent and the insert quietly fails — per the plugin rule
     * that no module hard-depends on Module D being on.
     */
    private function audit(string $event, array $context, string $level = 'info'): void
    {
        if (!class_exists(EventLogger::class)) {
            return;
        }
        try {
            EventLogger::log($event, $context, $level);
        } catch (\Throwable $e) {
            error_log('[valolink-plugin] accesslink audit failed: ' . $e->getMessage());
        }
    }
}
