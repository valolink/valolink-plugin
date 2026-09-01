<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

use Valolink\Plugin\Modules\Accesslink\Seo\SeoAdapterFactory;
use Valolink\Plugin\Settings;

/**
 * Builds the self-description an agent reads first.
 *
 * Deliberately generated rather than a static document: it reports the site's
 * *actual* allowed post types, whether writes are currently switched on, and
 * the real caps. A hand-written doc would drift from the configuration and an
 * agent would confidently do the wrong thing.
 *
 * Markdown rather than JSON because the whole point is that it goes straight
 * into a prompt. Kept to a few kilobytes for the same reason — the site
 * instructions and the notes section are both hard-bounded so it cannot grow
 * without limit as an operator writes more or agents leave more behind.
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

    public function build(): string
    {
        $base  = rest_url(AccesslinkModule::REST_NAMESPACE);
        $base  = rtrim($base, '/');
        $site  = get_bloginfo('name');
        $types = implode(', ', $this->service->allowed_post_types());
        $applier = new PostApplier();
        $fields = implode(', ', $applier->allowed_fields());
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
        $md[] = "  Returns id, title, slug, status, modified_gmt, link, content_chars and a short excerpt.";
        $md[] = "- `GET {$base}/content/{id}` — one post with full `post_content`, plus";
        $md[] = '  `pending_changes`: ids of proposals already queued against it. If that list is';
        $md[] = '  not empty, someone has already proposed an edit here — read those before adding another.';
        $md[] = '';
        $md[] = "- `GET {$base}/taxonomies` — category and tag slugs you may assign.";
        $md[] = "- `GET {$base}/media` — images available for `featured_media`. Query: `search`, `limit`.";
        $md[] = '';
        $md[] = 'Read the post before you propose an update. You need its current content to';
        $md[] = 'write a sensible replacement, and the staleness check below compares against it.';
        $md[] = '';
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
        $md[] = '## Checking on your proposals';
        $md[] = '';
        $md[] = "- `GET {$base}/changes?status=pending` — the queue. Also `applied`, `rejected`, `stale`, `failed`.";
        $md[] = "- `GET {$base}/changes/{id}` — one proposal.";
        $md[] = '';
        $md[] = 'Statuses mean: `pending` waiting on a human · `applied` live on the site ·';
        $md[] = '`rejected` a human declined it · `stale` the post changed before approval, so it';
        $md[] = 'was not applied · `failed` WordPress refused the write, see `error`.';
        $md[] = '';
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
        $md[] = '## Rules';
        $md[] = '';
        $md[] = "- Post types you may touch: `{$types}`.";
        $md[] = "- Fields you may set: `{$fields}`. Anything else is silently dropped.";
        $md[] = "- A `create` may request status: `{$statuses}`.";
        $md[] = '- You cannot delete or trash anything. There is no such action.';
        $md[] = '- You cannot approve or reject — those need a logged-in human. Trying returns 401.';
        $md[] = '- An update records what the post looked like when you proposed. If anyone edits';
        $md[] = '  it before approval, your change is parked as `stale` and nothing is overwritten.';
        $md[] = '  Re-read the post and propose again against the current version.';
        $md[] = '- Always send `idempotency_key`. Retrying with the same key returns your original';
        $md[] = '  proposal instead of filing a duplicate.';
        $md[] = '- Identify yourself with an `X-Accesslink-Agent: <name>` header.';
        $md[] = '- Post content larger than ' . ContentReader::CONTENT_MAX_CHARS
            . ' characters comes back truncated, flagged with `truncated: true`.';
        $md[] = '  Do not propose a replacement built from truncated content.';
        $md[] = '';

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
}
