<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Modules\Accesslink\Seo\SeoAdapterFactory;
use Valolink\Plugin\Modules\Accesslink\Translation\TranslationAdapterFactory;
use Valolink\Plugin\Settings;

/**
 * Builds the self-description an agent reads first.
 *
 * Deliberately generated rather than a static document: it reports the site's
 * *actual* allowed post types, whether writes are currently switched on, and
 * the real caps. A hand-written doc would drift from the configuration and an
 * agent would confidently do the wrong thing.
 *
 * Split into a short always-on core plus sections fetched on demand, because
 * the single document reached ~13 kB once translations and Elements were
 * documented, and every future capability would have added to what an agent
 * pays for on a site that cannot use it. A section that does not apply here is
 * not merely hidden, it is absent from the index — so "what can I do on this
 * site" is answered by the shape of the response rather than by prose the agent
 * has to read and discount.
 *
 * Markdown rather than JSON because the whole point is that it goes straight
 * into a prompt.
 */
final class GuideBuilder
{
    public const INSTRUCTIONS_MAX_CHARS = 4000;
    private const NOTES_CHAR_BUDGET     = 3000;

    public function __construct(
        private readonly Settings $settings,
        private readonly ChangeService $service,
        private readonly AgentNotes $notes,
        private readonly AccesslinkAuth $auth,
    ) {}

    public function instructions(): string
    {
        return (string) $this->settings->get_module_setting(
            AccesslinkModule::MODULE_ID,
            'instructions',
            '',
        );
    }

    // -------------------------------------------------------------------------
    // Sections
    // -------------------------------------------------------------------------

    /**
     * The sections available on this site, in reading order.
     *
     * @return array<string, array{label: string, summary: string}>
     */
    public function sections(): array
    {
        $all = [
            'proposing' => [
                'label'   => 'Proposing a change',
                'summary' => 'How to file one, the fields you may set, and what happens next.',
                'when'    => true,
            ],
            'blocks' => [
                'label'   => 'Editing a block-based page',
                'summary' => 'Addressing one block instead of rewriting a page, and adding, removing or moving blocks.',
                'when'    => $this->uses_blocks(),
            ],
            'translations' => [
                'label'   => 'Translations',
                'summary' => 'Reading what exists in which language, and proposing a translation.',
                'when'    => TranslationAdapterFactory::detect()->available(),
            ],
            'elements' => [
                'label'   => 'GeneratePress Elements',
                'summary' => 'Site furniture — headers, footers, injected scripts — and how they differ from pages.',
                'when'    => $this->uses_elements(),
            ],
            'menus' => [
                'label'   => 'Navigation menus',
                'summary' => 'Reading a menu as a tree and proposing a new one.',
                'when'    => $this->service->menus_enabled(),
            ],
            'queue' => [
                'label'   => 'Checking on your proposals',
                'summary' => 'Statuses, and reading why something was rejected.',
                'when'    => true,
            ],
            'notes' => [
                'label'   => 'Notes for future agents',
                'summary' => 'The shared scratchpad on this site.',
                'when'    => true,
            ],
        ];

        $out = [];
        foreach ($all as $name => $meta) {
            if ($meta['when']) {
                unset($meta['when']);
                $out[$name] = $meta;
            }
        }

        return $out;
    }

    /** Null when the section does not exist or does not apply to this site. */
    public function section(string $name): ?string
    {
        if (!array_key_exists($name, $this->sections())) {
            return null;
        }

        $lines = match ($name) {
            'proposing'    => $this->section_proposing(),
            'blocks'       => $this->section_blocks(),
            'translations' => $this->section_translations(),
            'elements'     => $this->section_elements(),
            'menus'        => $this->section_menus(),
            'queue'        => $this->section_queue(),
            'notes'        => $this->section_notes(),
            default        => [],
        };

        return implode("\n", $lines);
    }

    /** Core plus every applicable section — what `build()` always returned. */
    public function build(): string
    {
        $parts = [$this->core()];
        foreach (array_keys($this->sections()) as $name) {
            $parts[] = (string) $this->section($name);
        }

        return implode("\n", $parts);
    }

    // -------------------------------------------------------------------------
    // Core
    // -------------------------------------------------------------------------

    public function core(): string
    {
        $base     = $this->base();
        $site     = get_bloginfo('name');
        $types    = implode(', ', $this->service->allowed_post_types());
        $applier  = new PostApplier();
        $fields   = implode(', ', $applier->allowed_fields());
        $statuses = implode(', ', PostApplier::ALLOWED_STATUSES);

        $md = [];
        $md[] = "# Accesslink — {$site}";
        $md[] = '';
        $md[] = "Base URL: `{$base}`";
        $md[] = 'Every request needs `Authorization: Bearer <your key>`.';
        $md[] = '';
        $md[] = '## Read this first';
        $md[] = '';
        $md[] = 'Nothing you send here is applied to the site. Every change you propose goes';
        $md[] = 'into a queue that a person reviews and approves by hand. You are making a';
        $md[] = 'suggestion, not an edit. Write the `note` field on every proposal as if you';
        $md[] = 'were explaining your reasoning to the colleague who has to decide — that note';
        $md[] = 'is the main thing they see next to the diff.';
        $md[] = '';
        $md[] = $this->auth->writes_enabled()
            ? 'Proposals are currently being **accepted**.'
            : 'Proposals are currently **switched off** on this site — `POST /changes` will'
                . ' return 503. You can still read content and the queue.';
        $md[] = '';

        $instructions = trim($this->instructions());
        if ($instructions !== '') {
            $md[] = '## Site instructions';
            $md[] = '';
            $md[] = 'Set by the site owner. These take precedence over your own defaults.';
            $md[] = '';
            $md[] = mb_substr($instructions, 0, self::INSTRUCTIONS_MAX_CHARS);
            $md[] = '';
        }

        $md[] = '## Reading content';
        $md[] = '';
        $md[] = "- `GET {$base}/content` — list. Query: `search`, `post_type`, `status`, `limit`"
            . ' (max ' . ContentReader::LIST_MAX . ', default ' . ContentReader::LIST_DEFAULT . ').';
        $md[] = "- `GET {$base}/content/{id}` — one post with full `post_content`, plus";
        $md[] = '  `pending_changes`: ids of proposals already queued against it. If that list is';
        $md[] = '  not empty, read those before adding another.';
        $md[] = "- `GET {$base}/taxonomies` — category and tag slugs you may assign.";
        $md[] = "- `GET {$base}/media` — images available for `featured_media`.";
        $md[] = '';
        $md[] = 'Read the post before you propose an update. You need its current content to';
        $md[] = 'write a sensible replacement, and the staleness check compares against it.';
        $md[] = '';

        $md[] = '## Rules';
        $md[] = '';
        $md[] = "- Post types you may touch: `{$types}`.";
        $md[] = "- Fields you may set: `{$fields}`. Anything else is silently dropped.";
        $md[] = "- A `create` may request status: `{$statuses}`. `post_status` may also be set on";
        $md[] = '  an update, so you can propose publishing a draft or unpublishing a page.';
        $md[] = '- You cannot delete or trash anything. There is no such action.';
        $md[] = '- You cannot approve or reject — those need a logged-in human. Trying returns 401.';
        $md[] = '- A change records what the post looked like when you proposed. If anyone edits';
        $md[] = '  it before approval, your change is parked as `stale` and nothing is overwritten.';
        $md[] = '  Re-read the post and propose again against the current version.';
        $md[] = '- Always send `idempotency_key`. Retrying with the same key returns your original';
        $md[] = '  proposal instead of filing a duplicate.';
        $md[] = '- Identify yourself with an `X-Accesslink-Agent: <name>` header.';
        $md[] = '- Post content larger than ' . ContentReader::CONTENT_MAX_CHARS
            . ' characters comes back truncated, flagged with `truncated: true`.';
        $md[] = '  Do not propose a replacement built from truncated content.';
        $md[] = '';

        $md[] = '## The rest of this guide';
        $md[] = '';
        $md[] = 'Fetch a section when you need it: `GET ' . $base . '/guide?section=<name>`.';
        $md[] = 'Only sections that apply to this site are listed — if something is missing here,';
        $md[] = 'this site does not support it and you should not propose it.';
        $md[] = '';
        foreach ($this->sections() as $name => $meta) {
            $md[] = sprintf('- `%s` — %s', $name, $meta['summary']);
        }
        $md[] = '';

        $unavailable = $this->unavailable();
        if ($unavailable !== []) {
            $md[] = '## Not available on this site';
            $md[] = '';
            $md[] = 'Not broken, just absent. Do not propose these:';
            $md[] = '';
            foreach ($unavailable as $line) {
                $md[] = '- ' . $line;
            }
            $md[] = '';
        }

        $notes = $this->notes->for_guide(self::NOTES_CHAR_BUDGET);
        if ($notes !== []) {
            $md[] = '## Notes left by previous agents';
            $md[] = '';
            foreach ($notes as $note) {
                $who = $note['author'] !== null ? $note['author'] : 'unknown';
                $md[] = sprintf('- *(%s, %s)* %s', $note['created_at'], $who, $note['text']);
            }
            $md[] = '';
        }

        return implode("\n", $md);
    }

    // -------------------------------------------------------------------------
    // Section bodies
    // -------------------------------------------------------------------------

    /** @return array<int, string> */
    private function section_proposing(): array
    {
        $base    = $this->base();
        $applier = new PostApplier();

        $md = [];
        $md[] = '## Proposing a change';
        $md[] = '';
        $md[] = "`POST {$base}/changes`";
        $md[] = '';
        $md[] = 'Update an existing post:';
        $md[] = '';
        $md[] = '```json';
        $md[] = '{';
        $md[] = '  "action": "update",';
        $md[] = '  "target_id": 123,';
        $md[] = '  "fields": { "post_content": "<p>New body.</p>" },';
        $md[] = '  "note": "Why you are proposing this.",';
        $md[] = '  "idempotency_key": "unique-string-per-proposal"';
        $md[] = '}';
        $md[] = '```';
        $md[] = '';
        $md[] = 'Create a new one:';
        $md[] = '';
        $md[] = '```json';
        $md[] = '{';
        $md[] = '  "action": "create",';
        $md[] = '  "post_type": "post",';
        $md[] = '  "status": "publish",';
        $md[] = '  "fields": { "post_title": "Title", "post_content": "<p>Body.</p>" },';
        $md[] = '  "note": "Why this post should exist.",';
        $md[] = '  "idempotency_key": "unique-string-per-proposal"';
        $md[] = '}';
        $md[] = '```';
        $md[] = '';
        $md[] = '### Fields beyond the post body';
        $md[] = '';

        $seo = $applier->seo();
        if ($seo->can_write()) {
            $md[] = sprintf('SEO is handled by **%s** on this site, but you do not need to care —', $seo->label());
            $md[] = 'use the normalised names and Accesslink maps them:';
            $md[] = '';
            $md[] = '- `seo_title` — aim for about ' . (SeoAdapterFactory::RECOMMENDED['seo_title'] ?? 60)
                . ' characters; longer gets truncated in results.';
            $md[] = '- `seo_description` — aim for about ' . (SeoAdapterFactory::RECOMMENDED['seo_description'] ?? 155)
                . ' characters.';
            $md[] = '- `focus_keyword`';
            $md[] = '';
            $md[] = 'Sending an empty string clears the value and lets the plugin fall back to its';
            $md[] = 'global template, which is usually what you want rather than a blank tag.';
            $md[] = '';
            $md[] = 'Existing values often contain the plugin\'s own template variables, like';
            $md[] = '`%sep%` or `%sitename%`, which expand when the page renders. Preserve them';
            $md[] = 'unless you have a reason not to — replacing them with literal text hardcodes';
            $md[] = 'something the site owner configured globally.';
        } else {
            $md[] = sprintf('SEO fields are **not available** on this site (%s).', $seo->label());
        }

        $md[] = '';
        $md[] = '- `categories`, `tags` — arrays of existing term slugs, e.g. `["palvelut"]`.';
        $md[] = "  Read what exists from `GET {$base}/taxonomies`. Accesslink will **not** create new";
        $md[] = '  terms; an unknown slug is refused with the list of valid ones. This is on purpose —';
        $md[] = '  inventing near-duplicate terms quietly wrecks a taxonomy.';
        $md[] = "- `featured_media` — an attachment id. Browse with `GET {$base}/media`. Send `0` to clear.";
        $md[] = '  Uploading is not possible; you can only pick an image already in the library.';
        $md[] = '';
        $md[] = 'A `create` is drafted immediately so a human can preview it in the real theme.';
        $md[] = 'The draft is not publicly reachable; approving is what publishes it. An `update`';
        $md[] = 'does not touch the live post at all until approved.';
        $md[] = '';

        return $md;
    }

    /** @return array<int, string> */
    private function section_blocks(): array
    {
        $base = $this->base();

        $md = [];
        $md[] = '## Editing a block-based page';
        $md[] = '';
        $md[] = "- `GET {$base}/content/{id}/blocks` — the block tree, flattened into addressable paths.";
        $md[] = '';
        $md[] = 'If a page is built from blocks, **do not** rewrite its whole `post_content`. The';
        $md[] = 'copy usually sits several levels inside container blocks whose delimiters carry';
        $md[] = 'JSON attributes; regenerating the string reformats that JSON or mis-nests a';
        $md[] = 'wrapper, and the page then shows "this block contains unexpected or invalid';
        $md[] = 'content" in the editor. Address one block instead:';
        $md[] = '';
        $md[] = '```json';
        $md[] = '{';
        $md[] = '  "action": "update_block",';
        $md[] = '  "target_id": 123,';
        $md[] = '  "path": "0.0.1",';
        $md[] = '  "html": "<h2 class=\"...\">Uusi otsikko</h2>",';
        $md[] = '  "note": "Why.",';
        $md[] = '  "idempotency_key": "unique-string-per-proposal"';
        $md[] = '}';
        $md[] = '```';
        $md[] = '';
        $md[] = '**Prefer `update_text`.** It replaces only the words inside the block\'s';
        $md[] = 'wrapper element, leaving the wrapper and its classes byte-identical:';
        $md[] = '';
        $md[] = '```json';
        $md[] = '{';
        $md[] = '  "action": "update_text",';
        $md[] = '  "target_id": 123,';
        $md[] = '  "path": "0.0.1",';
        $md[] = '  "text": "Uusi otsikko <strong>korostuksella</strong>",';
        $md[] = '  "note": "Why.",';
        $md[] = '  "idempotency_key": "unique-string-per-proposal"';
        $md[] = '}';
        $md[] = '```';
        $md[] = '';
        $md[] = 'Why this matters: the editor decides a block is valid by re-running the';
        $md[] = 'block\'s own JavaScript against its stored attributes and comparing the result';
        $md[] = 'to the saved HTML. Change the wrapper and that comparison can fail, giving';
        $md[] = '"This block contains unexpected or invalid content" — which no server-side';
        $md[] = 'check can predict, because that code is JavaScript. Leave the wrapper alone';
        $md[] = 'and there is nothing to disagree about.';
        $md[] = '';
        $md[] = 'Block text may contain only inline formatting: `'
            . implode('`, `', array_slice(BlockValidator::INLINE_TAGS, 0, 12)) . '`…';
        $md[] = 'No `<div>`, no `<svg>`, no block-level elements — those make the block invalid.';
        $md[] = '';

        if ($this->uses_generateblocks()) {
            $md[] = '### GenerateBlocks on this site';
            $md[] = '';
            $md[] = 'This site builds pages with GenerateBlocks, which nests deeply: the text you';
            $md[] = 'want is often four or five containers down, and every wrapper carries';
            $md[] = '`gb-`-prefixed classes tying it to styles stored elsewhere. Two consequences:';
            $md[] = '';
            $md[] = '- `update_text` is not merely preferred here, it is close to mandatory. Losing';
            $md[] = '  a `gb-text` or `gb-headline` class strips the block\'s styling even though';
            $md[] = '  the markup still looks reasonable.';
            $md[] = '- Some blocks mark their editable region by class rather than by element. The';
            $md[] = '  inline-formatting check is skipped for those, so `POST /validate` will not';
            $md[] = '  catch as much as it does elsewhere — read the block back after proposing.';
            $md[] = '';
        }

        $md[] = "- `POST {$base}/validate` with `{target_id, path, text}` dry-runs an edit and";
        $md[] = '  reports what it would break, without queueing anything. Use it when unsure.';
        $md[] = '  It separates `issues` (what your edit introduces) from `pre_existing`';
        $md[] = '  (already wrong on that post, not your responsibility).';
        $md[] = '';
        $md[] = 'Only blocks marked `editable` — leaves with their own HTML — can be changed.';
        $md[] = 'Editing a block that contains other blocks is refused; go to the leaf holding';
        $md[] = 'the text.';
        $md[] = '';
        $md[] = '### Adding, removing and moving blocks';
        $md[] = '';
        $md[] = 'Editing text is not enough to write a page. Three more actions, all taking';
        $md[] = 'a `path` from the block listing:';
        $md[] = '';
        $md[] = '- `insert_block` — `path`, `position` (`before`/`after`), `markup` (one block\'s';
        $md[] = '  full markup, delimiters included). Inserts as a *sibling* of the block at';
        $md[] = '  `path`. Copy the shape of a block already on the page rather than inventing';
        $md[] = '  markup; if the block type is not installed here the proposal is refused.';
        $md[] = '- `delete_block` — `path`.';
        $md[] = '- `move_block` — `path`, `target_path`, `position`. Both paths as they appear';
        $md[] = '  in the current document; the shift from removing the source is handled for you.';
        $md[] = '';
        $md[] = 'These reorder the document, so their staleness check covers the whole page, not';
        $md[] = 'one block: if anything moves before approval, "after the second paragraph" no';
        $md[] = 'longer means what you meant, and the change is parked as `stale`.';
        $md[] = '';

        return $md;
    }

    /** @return array<int, string> */
    private function section_translations(): array
    {
        $base = $this->base();
        $tr   = TranslationAdapterFactory::detect();

        $langs = [];
        foreach ($tr->languages() as $language) {
            $langs[] = $language['slug'] . ($language['is_default'] ? ' (default)' : '');
        }

        $md = [];
        $md[] = '## Translations';
        $md[] = '';
        $md[] = 'This site is multilingual (' . $tr->plugin() . '). Languages: ' . implode(', ', $langs) . '.';
        $md[] = '';
        $md[] = "- `GET {$base}/languages` — the list above, with names and locales.";
        $md[] = "- `GET {$base}/content/{id}/translations` — which languages a post exists in,";
        $md[] = '  which are `missing`, and which are `outdated` (older than the post they translate).';
        $md[] = '';
        $md[] = 'To translate a post, do **not** write `post_content` yourself. Read';
        $md[] = "`GET {$base}/content/{id}/blocks` and take the `text_html` of each block marked";
        $md[] = '`editable` — that is the block\'s inner HTML, untruncated, where `text` is only a';
        $md[] = 'short preview. Replace the words in it and send it back keyed by `path`:';
        $md[] = '';
        $md[] = '```json';
        $md[] = '{';
        $md[] = '  "action": "create_translation",';
        $md[] = '  "target_id": 123,';
        $md[] = '  "lang": "' . ($tr->languages()[1]['slug'] ?? 'en') . '",';
        $md[] = '  "title": "Translated title",';
        $md[] = '  "slug": "translated-title",';
        $md[] = '  "texts": { "0.0.1": "Translated heading", "0.0.2": "Translated paragraph." },';
        $md[] = '  "note": "Why you are proposing this."';
        $md[] = '}';
        $md[] = '```';
        $md[] = '';
        $md[] = 'The page is cloned from the source and only those leaves are replaced, so the';
        $md[] = 'translation keeps the original layout exactly. **Change words only.** Every tag,';
        $md[] = 'attribute and inline SVG must come back exactly as you received it; a proposal';
        $md[] = 'whose markup differs from the source is refused. The reliable way to do that is';
        $md[] = 'to replace the text nodes and copy everything else through untouched, rather';
        $md[] = 'than retyping the HTML. Blocks you leave out keep the source language — send';
        $md[] = 'every editable block you want translated. The draft is created and linked';
        $md[] = 'immediately; approving it sets its status. If the source page changes before a';
        $md[] = 'human approves, the proposal goes `stale` and you should read the source again.';
        $md[] = '';
        $md[] = '`texts` is optional. Leave it out to clone a post verbatim into another';
        $md[] = 'language — what a page or element with no visible text, such as one that only';
        $md[] = 'injects CSS or a tracking script, needs in order to exist there at all.';
        $md[] = '';
        $md[] = 'Two things to expect:';
        $md[] = '';
        $md[] = '- **The source must already have a language.** If it does not, the proposal is';
        $md[] = '  refused with `source_has_no_language`. Propose `set_language` first — content';
        $md[] = '  predating the multilingual plugin often has none:';
        $md[] = '';
        $md[] = '  ```json';
        $md[] = '  { "action": "set_language", "target_id": 123, "lang": "'
            . ($tr->default_language() ?: 'fi') . '", "note": "Why." }';
        $md[] = '  ```';
        $md[] = '';
        $md[] = '  It only fills a gap. A post that already has a language is refused, because';
        $md[] = '  changing one would pull it out of its translation group — a data decision, not';
        $md[] = '  a content one, so it stays with a human.';
        $md[] = '- **Postmeta is copied from the source**, which is what makes the translation';
        $md[] = '  behave like the original — but it means SEO fields arrive still holding the';
        $md[] = '  source language\'s title and description. Propose an `update` with';
        $md[] = '  `seo_title` / `seo_description` against the new post to correct them.';
        $md[] = '';
        $md[] = 'A translation made before that copying existed can be missing the settings that';
        $md[] = 'make it behave like its original — rendering a page title the source hides, or';
        $md[] = 'losing a full-width layout. `sync_translation_meta` with `{target_id}` copies';
        $md[] = 'across only what is **absent**; a value already there is never overwritten, so it';
        $md[] = 'cannot undo the English SEO title you just corrected. It is refused when there is';
        $md[] = 'nothing missing.';
        $md[] = '';
        $md[] = 'A translated page is not a translated site. Navigation menus are not editable';
        $md[] = 'through this API at all, and internal links keep pointing at source-language';
        $md[] = 'pages until those pages are themselves translated. Say so rather than implying';
        $md[] = 'the job is finished.';
        $md[] = '';

        if ($this->uses_elements() && $tr->is_translated_type(ElementReader::POST_TYPE)) {
            $md[] = 'Elements are per-language here too — see the `elements` section, because a';
            $md[] = 'page translated without them renders with no header and no footer.';
            $md[] = '';
        }

        return $md;
    }

    /** @return array<int, string> */
    private function section_elements(): array
    {
        $base = $this->base();

        $md = [];
        $md[] = '## GeneratePress Elements';
        $md[] = '';
        $md[] = "- `GET {$base}/elements` — every Element with what it actually does:";
        $md[] = '  `element_type` (block, hook, layout), the `hook` it runs on, and its display,';
        $md[] = '  exclude and user conditions.';
        $md[] = '';
        $md[] = 'Elements are site furniture, not pages — a hero, a footer, a script injected';
        $md[] = 'into the head. They are `' . ElementReader::POST_TYPE . '` posts, so you read and';
        $md[] = 'propose against them exactly as you would a page. Check this list before';
        $md[] = 'concluding that something appearing on every page has to be edited on every';
        $md[] = 'page: if it is an Element, one proposal changes it everywhere.';
        $md[] = '';
        $md[] = 'That cuts both ways — editing one affects every page it renders on, so say in';
        $md[] = 'your `note` what you expect it to affect. The reviewer is shown the same warning.';
        $md[] = '';

        if (TranslationAdapterFactory::detect()->is_translated_type(ElementReader::POST_TYPE)) {
            $md[] = 'Elements are per-language on this site: one belongs to a single language and';
            $md[] = 'runs only on pages of that language. A page translated without its Elements';
            $md[] = 'renders with no header, no footer and none of the CSS they inject. Translate';
            $md[] = 'the ones carrying text, and clone the rest with `create_translation` and no';
            $md[] = '`texts` so they run in the other language too.';
            $md[] = '';
        }

        return $md;
    }

    /** @return array<int, string> */
    private function section_menus(): array
    {
        $base = $this->base();

        $md = [];
        $md[] = '## Navigation menus';
        $md[] = '';
        $md[] = "- `GET {$base}/menus` — every menu, the theme locations it occupies and, on a";
        $md[] = '  multilingual site, its language.';
        $md[] = "- `GET {$base}/menus/{id}` — one menu as a nested tree.";
        $md[] = '';
        $md[] = 'A menu item is not a page. It has its own `label`, which overrides the target\'s';
        $md[] = 'title, and a target that is either a post (`type: post_type` with an `object_id`)';
        $md[] = 'or a plain link (`type: custom` with a `url`).';
        $md[] = '';
        $md[] = 'Propose by sending the **whole tree back**, changed:';
        $md[] = '';
        $md[] = '```json';
        $md[] = '{';
        $md[] = '  "action": "update_menu",';
        $md[] = '  "menu_id": 29,';
        $md[] = '  "items": [';
        $md[] = '    { "id": 310, "label": "Websites", "type": "post_type", "object": "page", "object_id": 4345 },';
        $md[] = '    { "label": "Contact", "type": "custom", "url": "https://example.fi/contact/" }';
        $md[] = '  ],';
        $md[] = '  "note": "Why."';
        $md[] = '}';
        $md[] = '```';
        $md[] = '';
        $md[] = 'Read the menu, change what you need, send all of it back. **Anything you leave';
        $md[] = 'out is deleted** — that is what makes the whole thing reviewable as one';
        $md[] = 'before-and-after, but it does mean a partial payload quietly empties a menu.';
        $md[] = 'Keep `id` on items that already exist so they are updated rather than replaced;';
        $md[] = 'omit it to add one. Nest with `children`.';
        $md[] = '';
        $md[] = 'If anyone touches the menu between your proposal and approval, the change is';
        $md[] = 'parked as `stale` rather than reverting their work.';
        $md[] = '';

        if (TranslationAdapterFactory::detect()->available()) {
            $md[] = 'On this site menus are per-language, and a language\'s menu is a separate menu';
            $md[] = 'assigned to the same theme location. The common job is repointing: when a page';
            $md[] = 'gains a translation, the corresponding item in that language\'s menu should stop';
            $md[] = 'being a `custom` link to the original and become a `post_type` item pointing at';
            $md[] = 'the translated post.';
            $md[] = '';
            $md[] = 'Creating a menu, and assigning one to a theme location, are not possible here —';
            $md[] = 'ask the operator.';
            $md[] = '';
        }

        return $md;
    }

    /** @return array<int, string> */
    private function section_queue(): array
    {
        $base = $this->base();

        $md = [];
        $md[] = '## Checking on your proposals';
        $md[] = '';
        $md[] = "- `GET {$base}/changes?status=pending` — the queue. Also `applied`, `rejected`, `stale`, `failed`.";
        $md[] = "- `GET {$base}/changes/{id}` — one proposal.";
        $md[] = '';
        $md[] = 'Statuses mean: `pending` waiting on a human · `applied` live on the site ·';
        $md[] = '`rejected` a human declined it · `stale` the post changed before approval, so it';
        $md[] = 'was not applied · `failed` WordPress refused the write, see `error`.';
        $md[] = '';
        $md[] = 'A rejection may carry `review_note` — the reviewer explaining why. Read it';
        $md[] = 'before proposing anything similar; re-filing the same idea after it has been';
        $md[] = 'turned down wastes their attention, which is the scarce resource here.';
        $md[] = '';

        return $md;
    }

    /** @return array<int, string> */
    private function section_notes(): array
    {
        $base = $this->base();

        $md = [];
        $md[] = '## Notes for future agents';
        $md[] = '';
        $md[] = "- `GET {$base}/notes` — read what previous agents left.";
        $md[] = "- `POST {$base}/notes` with `{\"text\": \"...\"}` — leave one.";
        $md[] = '';
        $md[] = 'Use these for durable facts about this specific site: terminology the client';
        $md[] = 'insists on, sections not to touch, conventions you had to work out. Not for';
        $md[] = 'session state or task lists. Notes are capped at ' . AgentNotes::MAX_CHARS
            . ' characters and the newest ' . AgentNotes::MAX_NOTES . ' are kept.';
        $md[] = '';
        $md[] = 'The notes themselves are in the core guide, so you have already read them.';
        $md[] = '';

        return $md;
    }

    // -------------------------------------------------------------------------
    // Site detection
    // -------------------------------------------------------------------------

    /**
     * Whether block advice is worth sending at all.
     *
     * A site pinned to the classic editor has no block tree to address, and the
     * whole section would be noise an agent still pays for.
     */
    private function uses_blocks(): bool
    {
        if (!function_exists('use_block_editor_for_post_type')) {
            return true;
        }

        foreach ($this->service->allowed_post_types() as $type) {
            if (use_block_editor_for_post_type($type)) {
                return true;
            }
        }

        return false;
    }

    private function uses_generateblocks(): bool
    {
        return defined('GENERATEBLOCKS_VERSION')
            || class_exists('GenerateBlocks_Block')
            || \WP_Block_Type_Registry::get_instance()->is_registered('generateblocks/container');
    }

    private function uses_elements(): bool
    {
        return ElementReader::available()
            && in_array(ElementReader::POST_TYPE, $this->service->allowed_post_types(), true);
    }

    /** @return array<int, string> */
    private function unavailable(): array
    {
        $out = [];

        $seo = (new PostApplier())->seo();
        if (!$seo->can_write()) {
            $out[] = sprintf('SEO fields (%s)', $seo->label());
        }
        if (!TranslationAdapterFactory::detect()->available()) {
            $out[] = 'Translations — no multilingual plugin Accesslink can drive';
        }
        if (!$this->service->menus_enabled()) {
            $out[] = 'Navigation menus — editing them is switched off for this site';
        }
        $out[] = 'Creating menus, assigning theme locations, media uploads, and deleting anything';

        return $out;
    }

    private function base(): string
    {
        return rtrim(rest_url(AccesslinkModule::REST_NAMESPACE), '/');
    }
}
