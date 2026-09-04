# Accesslink — Agent Change Proposal API

## Context

Accesslink lets an authorised agent **propose** content changes to a WordPress site. It never applies them. Every proposal lands in a queue that a human reviews under **Valolink → Accesslink**, and only an approval by a logged-in user who can publish causes anything to change on the site.

**Communication model:** pull-shaped, like the EngineLink module — the agent calls the site, the site never calls out.

**Authentication:** `Authorization: Bearer <key>`, with a key generated on the Accesslink screen. This is **not** the EngineLink module's key and must not be set to the same value. The EngineLink key rides along on a six-hourly inventory poll and only exposes read-only facts; this one can put content on a client's site.

**The propose key cannot approve.** `/approve` and `/reject` are gated on the `publish_posts` capability, not on the key. A leaked agent key can fill the queue with junk — annoying, reviewable, revocable — but cannot publish anything. This asymmetry is the whole point of the module; don't collapse it for convenience.

**Kill switch:** the *Accept new changes* toggle. When off, propose returns `503` while the queue stays readable and reviewable.

---

## Lifecycle

```
agent                          site                         human
  │                              │                            │
  ├─ POST /changes ─────────────►│                            │
  │                              ├─ create: draft the post    │
  │                              ├─ update: hash the target   │
  │                              ├─ row status = pending ─────►│
  │                              │                            ├─ reviews diff
  │                              │◄─── approve ───────────────┤
  │                              ├─ re-hash, compare          │
  │                              ├─ applied │ stale │ failed  │
  │◄─ GET /changes/{id} ─────────┤                            │
```

A change ends in exactly one of: `applied`, `rejected`, `failed`, `stale`.

A rejection may carry `review_note` — the reviewer saying why, entered next to the Reject button and returned to the agent on `GET /changes/{id}`. Without it a rejection teaches nothing and the same proposal comes back; it is the only feedback channel the loop has.

**Notification.** Queuing a change emails the addresses configured on the Accesslink screen (the site admin address by default), at most one message per fifteen minutes however many changes arrive, and a wp-admin notice appears regardless in case mail is unreliable. Built on `wp_mail()` alone — the Email module routes it through Resend when enabled, WordPress falls back to PHP mail when it isn't. A failing mailer is logged and swallowed: the change is already stored by then, and losing it because SMTP is down would be far worse than a missed email.

`stale` is the interesting one. An update proposal records a hash of the fields it intends to touch plus the post's modification time. That hash is recomputed at approval; if it disagrees, somebody edited the post in the meantime and the proposal is answering a stale question. The change is parked rather than applied, and nothing is overwritten. Re-propose against the current version.

## Create vs update

They behave differently on purpose.

- **create** — the post is written immediately as a `draft`. A draft is not publicly reachable, so nothing is exposed, but the operator can preview it in the real theme and open it in the real editor. Approving is then just a status flip; the payload is not re-applied later. Rejecting moves the draft to trash (recoverable).
- **update** — the live post is never touched until approval. The proposed values sit in the queue row and are applied with `wp_update_post` on approval.

Note that create therefore *does* write to the database before any human sees it. That write is inert and invisible to visitors, but it is a write — which is why the module ships disabled, needs its own key, and has a kill switch.

---

## What the reviewer sees

Approval is the only gate, so every action has to be reviewable at the scope it operates on. A whole-document diff of a nested GenerateBlocks page is unreadable, which is the reason blocks are addressable in the first place.

| Action | Diff | Preview |
|---|---|---|
| `create` | — (draft exists; preview it in the real theme) | WordPress draft preview |
| `update` | field by field, resolved through `PostApplier::current_value()` | proposed post columns swapped into the main query |
| `update_text`, `update_block` | that block's HTML, before and after | proposed page |
| `insert_block` | the markup being added, plus the block outline | proposed page |
| `delete_block` | the block being removed, plus the block outline | proposed page |
| `move_block` | the block outline, before and after | proposed page |

The **block outline** is the tree as indented `name — first words` lines. Paths are deliberately left out: an insert or delete renumbers every later sibling, so including them would mark the rest of the document as changed and bury the line that actually moved.

For `update_text` the diff shows the block as it will *end up*, not the submitted `text`. The wrapper appearing byte-identical on both sides is the reviewer's evidence that the edit cannot invalidate the block, and showing the payload alone would hide exactly that.

One implementation note, because getting it wrong is silent: approval, the review diff and the front-end preview all resolve through `ChangeService::proposed_content()`. Three call sites deriving the proposed document separately is how a reviewer ends up approving something other than what they were shown.

---

## Endpoints

Base: `/wp-json/accesslink/v1`

### GET /guide

**Start here.** An agent holding only the base URL and a key needs nothing else. Returns a generated markdown briefing plus the machine-readable limits — prose for the model, structure for the code calling on its behalf.

```json
{
  "guide": "# Accesslink — Valolink\n\n…",
  "chars": 4385,
  "writes_enabled": true,
  "allowed_post_types": ["post", "page"],
  "allowed_fields": ["post_title", "post_content", "post_excerpt"],
  "allowed_statuses": ["draft", "pending", "publish", "private"],
  "limits": { "content_list_max": 50, "content_max_chars": 60000, "note_max_chars": 800, "notes_kept": 40 }
}
```

Generated rather than static, so it reports the site's *actual* configuration — a hand-written doc would drift and an agent would confidently do the wrong thing. It states the propose-then-approve model first, includes the operator's site instructions, tells the agent when writes are switched off, and appends notes left by previous agents.

Budget: base is ~3.5 kB, and each conditional section adds to it — the translation section and the Elements section bring a multilingual GeneratePress site to ~13 kB. Site instructions cap at 4000 characters and the notes section at 3000, so the worst case is now roughly 20 kB (~5k tokens). That is large for something described as "read this first", and it grows every time a capability is added. If it keeps growing, the split to make is a short always-on core plus `GET /guide?section=translations`, rather than trimming the prose that stops agents making expensive mistakes.

Both `actions` and `capabilities` are derived from the code that enforces them, not hand-listed. They were hand-listed once and drifted within a single day: `create_translation` shipped while `actions` still advertised seven, so an agent branching on that field could not discover the feature at all. The rule the module states about the prose — generated, so it cannot disagree with the site — applies to the structured fields or it means nothing.

### GET /content · GET /content/{id}

The read half. Core's `/wp/v2` can't be reached with an Accesslink key, can't see drafts, and returns far more per post than an agent wants to pay for.

`GET /content` — query `search`, `post_type`, `status`, `limit` (max 50, default 20). Returns `id`, `post_type`, `status`, `title`, `slug`, `modified_gmt`, `link`, `content_chars` and a 280-character plain-text excerpt.

`GET /content/{id}` — adds full `post_content`, `post_excerpt`, a `truncated` flag past 60000 characters, and `pending_changes`: ids of proposals already queued against that post. A non-empty list means someone has already proposed an edit; stacking another is how you earn a `stale` rejection.

Scope is the same `allowed_post_types` list that governs proposing, so a CPT outside it returns `403` on read as well as on write. Password-protected posts are never exposed. Visible statuses are `publish`, `draft`, `pending`, `private`, `future` — trash and auto-drafts never appear.

Note this widens the key beyond propose-only: it can read unpublished content. That is required for the workflow (an agent must be able to see the draft it proposed) but it is a real broadening of what a leaked key exposes.

### GET /taxonomies · GET /media

Lookups for the two fields whose valid values an agent cannot guess.

`GET /taxonomies` returns the category and tag slugs it may assign. `GET /media` returns images available for `featured_media` (query `search`, `limit`), with id, title, alt text and dimensions.

Both exist because Accesslink refuses to create terms and cannot upload files — so without them the only discovery route is to guess and read the error.

### GET /notes · POST /notes

A scratchpad agents leave for whoever works the site next — terminology a client insists on, sections to avoid, conventions worked out the hard way. Not session state.

`POST` takes `{"text": "…"}`, capped at 800 characters, newest 40 kept, oldest evicted. Notes are not queued for approval: they are invisible to visitors and bounded, so gating them would only make the memory useless. They are visible and deletable in wp-admin, because a wrong note quietly steers every future agent.

There is deliberately **no delete over the API**. Curating what agents tell each other is the operator's job.

Writing a note respects the kill switch (`503` when writes are off); reading does not.

### GET /content/{id}/blocks

The block tree, flattened into addressable paths. Paths are dot-joined child indices — `0.1` is the second child of the first top-level block.

```json
{ "id": 2324, "has_blocks": true, "total": 36, "truncated": false,
  "blocks": [
    {"path":"0","name":"generateblocks/container","depth":0,"has_inner_blocks":true,"editable":false,"html":"…","text":""},
    {"path":"0.0.1","name":"generateblocks/headline","depth":2,"has_inner_blocks":false,"editable":true,"html":"<h1 …>Verkkokaupat</h1>","text":"Verkkokaupat"}
  ] }
```

Only `editable: true` blocks — leaves with their own HTML — can be changed. Capped at 400 blocks.

### Composing a page — insert_block · delete_block · move_block

Editing text is not enough to write a page, so three structural actions sit alongside `update_text`:

| Action | Payload |
|---|---|
| `insert_block` | `target_id`, `path`, `position` (`before`/`after`), `markup` |
| `delete_block` | `target_id`, `path` |
| `move_block` | `target_id`, `path`, `target_path`, `position` |

Insert places the new block as a **sibling** of the one at `path`. Sibling-only is deliberate: inserting *inside* an arbitrary block raises the question of where among its children and text it goes, and for a leaf there is no sensible answer. Both `move_block` paths are given as they appear in the current document — removing the source shifts later siblings, and that adjustment is handled internally rather than asked of the agent.

`markup` must parse to exactly one block whose type is **registered on this site**. A block from a plugin that isn't installed is refused by name, which is how plugin differences surface: as a narrower set of possibilities, never as a failure.

Maintaining `innerContent` is the subtle part. A parent block stores its children as an array of literal HTML interleaved with `null` markers, one per child in order; adding or removing a child without adding or removing the matching `null` renders the children in the wrong places. That bookkeeping is in `BlockReader`.

Staleness for these covers the **whole document**, not one block, because paths are positional: if anything moves before approval, "after the second paragraph" no longer means what the agent meant.

### GET /languages · GET /content/{id}/translations · action `create_translation`

Present when the site runs a multilingual plugin Accesslink can drive. Polylang 3.7+ is supported through its `pll_*` API; WPML is detected and reported unwritable rather than half-supported. `GET /guide` names the resolved adapter.

`GET /languages` returns the configured languages, default first. `GET /content/{id}/translations` returns the post's translation group, the languages it is `missing`, and an `outdated` flag per translation — a timestamp comparison against the source, since Polylang tracks no such thing itself. It flags "worth re-reading", not "definitely wrong".

Translating does **not** mean writing `post_content`. Read `/content/{id}/blocks`, take each editable block's `text_html` (its inner HTML, untruncated — `text` is only a 200-character preview), replace the words, and send them back keyed by path:

```json
{
  "action": "create_translation",
  "target_id": 525,
  "lang": "en",
  "title": "Home",
  "slug": "home",
  "texts": { "0.0.1": "Websites <mark class=\"…\">that focus on what matters</mark>" }
}
```

The document is cloned from the source and only those leaves are replaced, so the translation inherits every wrapper, class and attribute, and its block-name sequence matches by construction. Blocks left out keep the source language.

**Only words may change.** The markup skeleton — every tag and block delimiter with its attributes, text removed — is compared between source and result, and a mismatch is refused. That is a tighter guarantee than sanitising and, unlike sanitising, it does no damage: `ContentSanitizer` is built for content an agent authored, and running it over a clone of the site's own markup stripped the `style` off 13 `<mark>` highlights and parts of 21 inline SVG icons on this project's own front page. Because the skeleton check makes markup injection impossible, `replace_text_at`'s inline-only rule is relaxed for this action alone — the SVG in the replacement is the SVG that was already there.

`texts` is optional. Omitting it clones the source verbatim, which is what a language-neutral post needs — a GeneratePress Element that only injects CSS has nothing to translate but still needs a counterpart in the target language to run there at all.

The source's postmeta is copied to the translation, minus the edit lock. This is not a nicety: a GeneratePress Element's type, hook and display conditions all live in `_generate_*` meta, so without it a translated Element is an inert draft that never renders. The same applies to per-page layout meta — a translation created without it renders its page title and full-width setting differently from the original. SEO meta is copied too, so a fresh translation starts with the *source language's* title and description; propose an `update` with `seo_title` / `seo_description` against the translation to correct that.

The draft is created and linked immediately, as `create` does; approval sets its status, defaulting to the source's rather than to `publish`, so translating a draft does not publish it. The staleness gate hashes the **source**: if the original changes before approval the proposal goes `stale`, because it is no longer a translation of what is published.

Not covered, and worth saying plainly because a translated page is not a translated site: **menus** are per-language in Polylang and are not touched, **theme and plugin strings** are not touched, and **internal links keep pointing at source-language pages**. Term translations (`pll_save_term_translations`) are not wired up either, so a translated post cannot yet be categorised.

### GET /elements

GeneratePress Elements are ordinary posts of the `gp_elements` type, so once an operator adds that type to *Allowed post types* every existing read and write applies to them unchanged. What is not ordinary is what they mean: an Element is site furniture — a hero, a footer, a script injected into `wp_head` — and its behaviour lives in `_generate_*` postmeta, not in its content.

`GET /elements` surfaces that meta: `element_type` (`block` / `hook` / `layout`), `block_type`, `hook` and priority, and the display, exclude and user conditions. It also reports `editable`, so an agent learns whether proposing against Elements is permitted here without first having a proposal refused. Without this an agent sees a pile of oddly-named pages and cannot tell that the footer CTA is one Element rather than something repeated on forty pages.

The review screen flags any change to a `gp_elements` post, because a diff of an Element looks exactly like a diff of a page while approving it changes every page the Element renders on.

**Elements and languages.** When a multilingual plugin manages `gp_elements` — Polylang does by default — each Element belongs to one language and only runs on pages of that language. A site whose Elements are all in the source language will render a translated page with no header, no footer and none of the CSS or tracking those Elements inject. The fix is an Element per language, which is what `create_translation` is for: text-bearing Elements get translated, and the ones that only inject CSS or a script are created with no `texts` at all, existing purely so they run in the other language too.

### POST /validate

Dry-runs block checks without filing anything. Send `{content}` to check markup outright, or `{target_id, path, text|html}` to test an edit against a real post.

```json
{ "ok": false,
  "issues": ["<div> is not inline formatting. Block text may contain only: a, b, strong…"],
  "pre_existing": ["<svg> inside core/paragraph: …"] }
```

`issues` is what *your edit* would introduce; `pre_existing` is what was already wrong on that post. They are separated deliberately — validating the whole document would make any page with an old problem uneditable, and an agent fixing a typo is not responsible for markup someone pasted in two years ago.

**What can and cannot be checked.** Gutenberg decides block validity by re-running each block type's JavaScript `save()` against the stored attributes and comparing the result to the saved HTML. Those functions exist only in JS, so **PHP cannot reproduce that verdict** — content can round-trip through `parse_blocks()`/`serialize_blocks()` perfectly and still be rejected by the editor. What this endpoint does check:

- the markup round-trips through the block parser (catches malformed delimiters and broken attribute JSON)
- every block name is registered on this site
- rich-text regions contain only inline formatting, which is the mistake that actually happens — an `<svg>` inside a `core/paragraph` can never match what its `save()` emits

The rich-text check is skipped for blocks whose content selector is class-based (`.gb-text`), because there is no reliable way to tell which element is the legitimate wrapper, and guessing produced false positives on valid GenerateBlocks pages.

### POST /changes

Files a proposal. Returns `201` with the created row.

For a block edit, use `action: "update_block"` with a `path` and replacement `html` instead of `fields`:

```json
{
  "action": "update_block",
  "target_id": 2324,
  "path": "0.0.1",
  "html": "<h1 class=\"gb-headline\">Uusi otsikko</h1>",
  "note": "Shorter headline for the hero.",
  "idempotency_key": "…"
}
```

**Prefer `action: "update_text"`**, which takes a `text` instead of `html` and replaces only what is inside the block's wrapper element, leaving the wrapper and its classes byte-identical. Since the wrapper is what `save()` would regenerate, not touching it removes the main way an edit turns a block invalid. `text` may contain only inline formatting; a `<div>` or `<svg>` is refused at propose time.

`update_block` remains for cases where the whole block HTML genuinely must change — it is the sharper tool and correspondingly easier to cut yourself on.

This is the right way to edit a block-based page. Regenerating a whole `post_content` is how an agent destroys one: on a GenerateBlocks site the copy sits four or five levels inside container wrappers whose delimiters carry JSON attributes, and rewriting the raw string reformats that JSON, drops attributes or mis-nests wrappers — after which the editor shows "this block contains unexpected or invalid content" and the diff is too large for a reviewer to catch it.

Only the addressed block's own HTML is replaced; attributes, children and every sibling are re-serialised untouched from the parsed tree — with one correction that had to be made explicit. `parse_blocks()` decodes delimiter JSON with `json_decode($json, true)`, which cannot tell an empty object from an empty array, so `serialize_blocks()` wrote `{"styles":{}}` back out as `{"styles":[]}`: an attribute quietly rewritten on blocks the edit never touched. A text or single-block replacement never changes a delimiter, so when the delimiter count is unchanged `BlockReader` restores the originals verbatim. Structural actions do change that count and are still exposed to it. Editing a block that contains other blocks is refused — go to the leaf holding the text. After replacement the document is re-parsed and the block-name sequence compared to before; if it differs the change is refused rather than saved.

Staleness for a block edit hashes that block alone, so unrelated edits elsewhere on the page don't invalidate it while a change to this block does.

```json
{
  "action": "update",
  "target_id": 42,
  "fields": {
    "post_title": "Uusi otsikko",
    "post_content": "<p>Korjattu kappale.</p>"
  },
  "note": "Fixed the outdated price in paragraph 2.",
  "idempotency_key": "sidelink-2026-08-31-a7f3"
}
```

For a new post:

```json
{
  "action": "create",
  "post_type": "post",
  "status": "publish",
  "fields": {
    "post_title": "Syysale 2026",
    "post_content": "<p>…</p>",
    "post_excerpt": "Lyhyt kuvaus"
  },
  "note": "Drafted from the campaign brief."
}
```

| Field | Notes |
|---|---|
| `action` | `create` or `update`. Required. |
| `target_id` | Required for `update`. |
| `post_type` | `create` only. Must be in the site's allowed list (default `post`, `page`). |
| `status` | `create` only — the status to move to on approval. Default `publish`. |
| `fields` | See the field table below. Anything unrecognised is dropped. |
| `note` | Free text shown to the reviewer. Use it — a reviewer deciding on a diff alone has to reverse-engineer intent. |
| `idempotency_key` | Optional but recommended. A repeat returns the original row instead of filing a duplicate. |

Send `X-Accesslink-Agent: <name>` to identify the caller in the queue and the audit log.

#### Fields

| Field | Notes |
|---|---|
| `post_title`, `post_content`, `post_excerpt` | Post columns. |
| `seo_title`, `seo_description`, `focus_keyword` | Normalised across SEO plugins — see below. Empty string clears the value so the plugin's global template takes over. |
| `categories`, `tags` | Arrays of **existing** term slugs. Unknown slugs are refused with the valid list; Accesslink never creates terms, because an agent inventing near-duplicates quietly wrecks a taxonomy. Refused outright on post types without that taxonomy. |
| `featured_media` | Attachment id, must be an image. `0` clears it. Uploading is not possible. |
| `post_status` | `draft`, `pending`, `publish`, `private`. Settable on an update as well as a create, so unpublishing and publishing can be proposed. |

Everything is validated at **propose** time, not just at apply, so an agent finds out immediately and the reviewer's queue doesn't fill with proposals that were never applicable. Apply re-checks anyway, since the site can move underneath a queued change.

#### SEO across plugins

The agent uses normalised names; Accesslink maps them onto whatever the site runs. Rank Math (`rank_math_*`) and Yoast (`_yoast_wpseo_*`) are supported. All in One SEO and SEOPress are *detected but not writable* — they keep data outside postmeta, so the fields are reported unavailable rather than written somewhere that does nothing. `GET /guide` reports the resolved adapter as `seo_plugin`.

Scope is three fields on purpose. `noindex` is excluded: an agent proposing to deindex a page is a decision with delayed, silent, severe consequences that a reviewer skimming a diff would not weigh correctly.

Existing values frequently contain plugin template variables (`%sep%`, `%sitename%`) that expand at render time. The guide tells agents to preserve them.

Errors: `400` bad action/fields/post type · `401` bad key · `404` no such target · `503` writes switched off.

### GET /changes

`?status=pending&limit=50`. Same key. Readable even when writes are off, so an agent can see what happened to what it filed.

```json
{
  "changes": [
    {
      "id": 12,
      "status": "pending",
      "action": "update",
      "entity_type": "post",
      "post_type": "post",
      "target_id": 42,
      "summary": "Etusivu",
      "note": "Fixed the outdated price in paragraph 2.",
      "requested_by": "sidelink",
      "created_at": "2026-08-31 09:12:44",
      "reviewed_at": null,
      "error": null,
      "edit_link": "https://example.fi/wp-admin/post.php?post=42&action=edit"
    }
  ]
}
```

### GET /changes/{id}

One row, same shape.

### POST /changes/{id}/approve · POST /changes/{id}/reject

Capability-gated (`publish_posts`), **not** key-gated. With no logged-in user these return `403` — that is intended, not a bug. Remote approval from EngineLink will need its own approver credential, which is deliberately not built yet.

---

## Content filtering

`post_content` is stored raw and filtered at apply time, mirroring WordPress's own rule: if the approving user has `unfiltered_html` (an administrator on a single site does), the content is applied as-is, exactly as if they had pasted it into the editor. Otherwise it goes through `wp_kses_post()`.

One subtlety, because it silently defeated the above for a while: WordPress adds `wp_filter_post_kses` to `content_save_pre` whenever the current user lacks `unfiltered_html`, and a propose-time request has **no user at all**. So every `create` had its content filtered a second time on save, by core's kses rather than by `ContentSanitizer` — which stripped every inline `<svg>` back out, exactly what `ContentSanitizer` exists to prevent. Every save path now runs through `PostApplier::without_kses()`, which lifts those filters and restores them, making `filter_content()` the single authority on what survives instead of the first of two filters where the second quietly won. Verified adversarially: `<script>`, `onclick`, `onerror`, `javascript:` URLs and external `<use>` references are all still removed.

Filtering does **not** go through `wp_kses_post()` directly. That call preserves block delimiters fine — they are HTML comments and modern kses keeps them — but it has no allowlist for inline SVG, and on a GeneratePress/GenerateBlocks site SVG icons are everywhere. Measured on this project's own front page, plain `wp_kses_post()` stripped 42 `<svg>`, 82 `<path>` and every `<g>`/`<circle>`/`<defs>`/`<mask>`, costing 14% of the document; across all 16 block posts it lost 7.9%.

`ContentSanitizer` is therefore `post` plus a conservative SVG subset, which brings the loss to 0.5% while still removing `on*` handlers, `<script>` inside SVG, `<use>` (external document references) and `javascript:` protocols. `<style>` is deliberately not allowed, which accounts for most of the remaining 0.5%.

---

## Degrading rather than failing

Sites differ, and absence of a plugin narrows what Accesslink offers instead of breaking it:

- **No SEO plugin, or an unwritable one** — the SEO fields drop out of `allowed_fields` entirely and a proposal naming them is refused with the reason. `GET /guide` reports the resolved adapter and lists what is unavailable on this site.
- **A post type without a taxonomy** — refused by name rather than written where nothing renders it.
- **A block type whose plugin isn't installed** — `insert_block` refuses it by name.
- **Logging module off** — audit calls are wrapped and swallowed; nothing depends on it.
- **Queue table never installed** — every endpoint answers `503 accesslink_unavailable` instead of leaking SQL errors.

`GET /guide` returns a `capabilities` object so an agent can branch on what a site supports rather than discovering absence through a failed proposal.

## Not built yet

Named here so nobody assumes otherwise:

- **WooCommerce products.** Products are a CPT, but price/stock/SKU live in postmeta *and* in Woo's `wp_wc_product_meta_lookup` table. Writing them through `wp_update_post` desynchronises the two. A `ProductApplier` going through `wc_get_product()` setters and `save()` slots in beside `PostApplier`; the queue, auth, staleness and review UI all work unchanged.
- **GeneratePress Elements.** Reading them (type, hook, and display conditions resolved to which posts they apply to) so an agent can tell that the footer CTA is an Element rather than a page.
- **Menus.** `nav_menu_item` posts as a tree. Blocked less by the write than by the review: a text diff of a menu is meaningless, so the queue screen needs a tree diff before approving one is honest.
- **Custom fields / ACF.** Common on agency sites, entirely absent here.
- **Reading back a proposal's payload.** `GET /changes/{id}` returns metadata, not the proposed content, so an agent cannot inspect what a *different* agent queued — only that something is queued.
- **Per-key scoping.** One key per site; the only granularity is the site-wide `allowed_post_types` list.
- **Remote approval from EngineLink.** The service layer is already the single implementation behind both entry points, so aggregating queues across sites is additive — but it needs an approver credential that is not the propose key.
- **Per-key scoping.** One key per site, all-or-nothing across the allowed post types.
