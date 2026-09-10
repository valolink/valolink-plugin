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

Rejecting a `create` or `create_translation` trashes the draft it made, and a rejected translation is also **unlinked from its translation group** first. Without that, rejecting was a one-way door: the trashed draft still held the language, so re-proposing the same translation was refused as `already_translated`, pointing the agent at a post in the trash. Proposing now also ignores group members whose post is trashed or gone, so translations rejected before this still do not block a retry.

Changes that are not post saves purge the page cache explicitly (`CacheCleaner`). Post edits need no help — a caching plugin hooks `save_post` and clears the right pages, confirmed by probe against a warmed cache. A menu is not a post, so nothing fires, and the change would be real but invisible until something else happened to clear the cache.

A rejection may carry `review_note` — the reviewer saying why, entered next to the Reject button and returned to the agent on `GET /changes/{id}`. Without it a rejection teaches nothing and the same proposal comes back; it is the only feedback channel the loop has.

**Notification.** Queuing a change emails the addresses configured on the Accesslink screen (the site admin address by default), at most one message per fifteen minutes however many changes arrive, and a wp-admin notice appears regardless in case mail is unreliable. Built on `wp_mail()` alone — the Email module routes it through Resend when enabled, WordPress falls back to PHP mail when it isn't. A failing mailer is logged and swallowed: the change is already stored by then, and losing it because SMTP is down would be far worse than a missed email.

`stale` is the interesting one. An update proposal records a hash of the current values of the fields it intends to touch. That hash is recomputed at approval; if it disagrees, somebody changed one of those values in the meantime and the proposal is answering a stale question. The change is parked rather than applied, and nothing is overwritten. Re-propose against the current version.

The hash deliberately does **not** include the post's modification time any more. It used to, and then two proposals touching disjoint fields on one post could never both apply: approving the first bumped the stamp and parked the second as stale, though nothing it was replacing had changed. Block and structural changes still hash the whole document, because paths are positional.

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
| `create_translation` | — (draft exists; the card names source, source language and target language) | WordPress draft preview, plus a link to the original |
| `set_language` | a statement of the language being assigned; nothing about the content changes | — |
| `sync_translation_meta` | the missing keys and the values they will take | — |
| `update_menu` | both sides as an indented `label → target` tree | — (the menu, not a page) |

The **block outline** is the tree as indented `name — first words` lines. Paths are deliberately left out: an insert or delete renumbers every later sibling, so including them would mark the rest of the document as changed and bury the line that actually moved. It is folded by default — on a GenerateBlocks page it runs to a hundred lines, and the block diff above it already shows the line that changed.

The queue lists pending changes **oldest first**. Approving reloads the page at the top, so the next change to review has to be the top one; and proposals filed against the same document frequently only make sense in the order the agent filed them. `GET /changes` keeps newest first, since an agent polling it wants to see what just happened.

For `update_text` the diff shows the block as it will *end up*, not the submitted `text`. The wrapper appearing byte-identical on both sides is the reviewer's evidence that the edit cannot invalidate the block, and showing the payload alone would hide exactly that.

One implementation note, because getting it wrong is silent: approval, the review diff and the front-end preview all resolve through `ChangeService::proposed_content()`. Three call sites deriving the proposed document separately is how a reviewer ends up approving something other than what they were shown.

---

## Endpoints

Base: `/wp-json/accesslink/v1`

### GET /guide

**Start here.** An agent holding only the base URL and a key needs nothing else. Returns a generated markdown briefing plus the machine-readable limits — prose for the model, structure for the code calling on its behalf.

The briefing is a short **core** — the propose-then-approve model, the operator's site instructions, reading content, the rules, and notes left by previous agents — plus an index of **sections** fetched on demand with `GET /guide?section=<name>`. `?section=all` returns everything, as the single document used to.

Sections appear only when they apply: `translations` needs a multilingual plugin Accesslink can drive, `elements` needs GeneratePress Elements *and* the operator to have allowed the post type, `blocks` disappears on a site pinned to the classic editor, and the GenerateBlocks-specific advice inside `blocks` appears only where GenerateBlocks is active. That is the point of the split as much as the size is: a section that cannot apply is *absent from the index*, so "what can I do here" is answered by the shape of the response rather than by prose the agent has to read and then discount.

Sizes on this project's own site: core 3.9 kB, sections 0.5–3.7 kB. The single document it replaced had reached 13.6 kB and would have grown with every capability, including on sites that could not use them.

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

Budget: the core is ~1 kB of fixed prose plus the operator's site instructions (capped at 4000 characters) and the agent notes (capped at 3000), so ~8 kB worst case. Sections are fetched only when needed and none exceeds 4 kB.

Both `actions` and `capabilities` are derived from the code that enforces them, not hand-listed. They were hand-listed once and drifted within a single day: `create_translation` shipped while `actions` still advertised seven, so an agent branching on that field could not discover the feature at all. The rule the module states about the prose — generated, so it cannot disagree with the site — applies to the structured fields or it means nothing.

### GET /content · GET /content/{id}

The read half. Core's `/wp/v2` can't be reached with an Accesslink key, can't see drafts, and returns far more per post than an agent wants to pay for.

`GET /content` — query `search`, `post_type`, `status`, `limit` (max 50, default 20). Returns `id`, `post_type`, `status`, `title`, `slug`, `modified_gmt`, `link`, `content_chars` and a 280-character plain-text excerpt. Add `fields=seo,terms,media,layout,product` (any subset) to include the SEO fields, term slugs, the featured image, the page layout and, on a product, its price and stock per row — an audit of fifty posts is then one call rather than fifty. The default row stays lean on purpose.

`GET /content/{id}` — adds full `post_content`, `post_excerpt`, a `truncated` flag past 60000 characters, and `pending_changes`: ids of proposals already queued against that post. A non-empty list means someone has already proposed an edit on it; read those first. On a GeneratePress site it also carries `layout` (`sidebar_layout`, `content_container`, `hide_title`), on a `gp_elements` post an `element` object in the same names an update may send back, and on a WooCommerce product a `product` object (see *WooCommerce products*). Term fields appear only for taxonomies the post type has: a product used to list empty blog `categories` and `tags`, which invited proposing them, and a page no longer carries them either.

Scope is the same `allowed_post_types` list that governs proposing, so a CPT outside it returns `403` on read as well as on write. Visible statuses are `publish`, `draft`, `pending`, `private`, `future` — trash and auto-drafts never appear. Password-protected posts are readable and proposable: the password gates the public front end only, and a key that already sees drafts and private posts is not kept out by it. They were refused until 0.2.3, which stalled the one case the password exists for — a page put behind one while the client reviews it.

Note this widens the key beyond propose-only: it can read unpublished content. That is required for the workflow (an agent must be able to see the draft it proposed) but it is a real broadening of what a leaked key exposes.

### GET /taxonomies · GET /media

Lookups for the two fields whose valid values an agent cannot guess.

`GET /taxonomies` returns the category and tag slugs it may assign, with their ids — proposals take slugs, but a query-loop block references terms by id. `GET /media` returns images available for `featured_media` (query `search`, `limit`), with id, title, alt text, dimensions and `attached_to`, the post the file was uploaded to, which is the only handle on a library of camera-filename images.

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

Insert places the new block as a **sibling** of the one at `path`. Sibling-only is deliberate: inserting *inside* an arbitrary block raises the question of where among its children and text it goes, and for a leaf there is no sensible answer. The one exception is the document itself: `insert_block` with no `path` and `position` `start` or `end` prepends or appends at the top level, which is the usual shape of "add a call-to-action at the end" and used to cost a read of the block tree just to find the last sibling's path. Both `move_block` paths are given as they appear in the current document — removing the source shifts later siblings, and that adjustment is handled internally rather than asked of the agent.

`markup` must parse to exactly one block whose type is **registered on this site**. A block from a plugin that isn't installed is refused by name, which is how plugin differences surface: as a narrower set of possibilities, never as a failure.

Maintaining `innerContent` is the subtle part. A parent block stores its children as an array of literal HTML interleaved with `null` markers, one per child in order; adding or removing a child without adding or removing the matching `null` renders the children in the wrong places. That bookkeeping is in `BlockReader`.

Staleness for these covers the **whole document**, not one block, because paths are positional: if anything moves before approval, "after the second paragraph" no longer means what the agent meant.

### GET /languages · GET /content/{id}/translations · action `create_translation`

Present when the site runs a multilingual plugin Accesslink can drive. Polylang 3.7+ is supported through its `pll_*` API; WPML is detected and reported unwritable rather than half-supported. `GET /guide` names the resolved adapter.

`GET /languages` returns the configured languages, default first. `GET /content/{id}/translations` returns the post's translation group, its `source_id` (the default-language member when there is one), the languages it is `missing`, and an `outdated` flag per translation — a timestamp comparison against that source, since Polylang tracks no such thing itself. It flags "worth re-reading", not "definitely wrong". The comparison is anchored to the source whichever member is queried: read from the English page, the Finnish original used to be flagged outdated for being older, which was the wrong advice in the wrong direction.

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

**`set_language`** exists because without it the whole flow dead-ended: content predating the multilingual plugin has no language, `create_translation` refuses it, and there was no way to fix that except in wp-admin. It takes `{target_id, lang}` and only ever fills a gap — a post that already has a language is refused with `already_has_language`, since changing one pulls the post out of its translation group, which is a data decision rather than a content one. Its staleness question is simply whether the post still has no language when a human gets to it.

**`sync_translation_meta`** repairs a translation made before postmeta copying existed — the kind that renders a page title its original hides, or has lost a full-width layout. It takes `{target_id}` and copies from the translation's source **only the keys that are absent**. Never overwriting is the whole design: the obvious thing to overwrite is exactly the thing that should differ, namely the target-language SEO title someone corrected after the source's was copied in. Refused when nothing is missing.

`texts` is optional. Omitting it clones the source verbatim, which is what a language-neutral post needs — a GeneratePress Element that only injects CSS has nothing to translate but still needs a counterpart in the target language to run there at all.

The source's postmeta is copied to the translation, minus the edit lock. This is not a nicety: a GeneratePress Element's type, hook and display conditions all live in `_generate_*` meta, so without it a translated Element is an inert draft that never renders. The same applies to per-page layout meta — a translation created without it renders its page title and full-width setting differently from the original. SEO meta is copied too, so a fresh translation starts with the *source language's* title and description; propose an `update` with `seo_title` / `seo_description` against the translation to correct that.

The draft is created and linked immediately, as `create` does; approval sets its status, defaulting to the source's rather than to `publish`, so translating a draft does not publish it. The staleness gate hashes the **source**: if the original changes before approval the proposal goes `stale`, because it is no longer a translation of what is published.

Not covered, and worth saying plainly because a translated page is not a translated site: **menus** are per-language in Polylang and are not touched, **theme and plugin strings** are not touched, and **internal links keep pointing at source-language pages**. Term translations (`pll_save_term_translations`) are not wired up either, so a translated post cannot yet be categorised.

### GET /menus · GET /menus/{id} · action `update_menu`

`GET /menus` lists every menu with the theme locations it occupies and, on a multilingual site, its language. Locations are read from both the theme mod and Polylang's own per-theme/per-location/per-language map, so a menu assigned to *primary* for English only reports as `primary [en]` — reading the filtered theme mod would report just the current language and quietly mislead.

`GET /menus/{id}` returns the menu as a nested tree. A menu item is not a page: it has its own `label`, which overrides the target's title, and points either at a post (`type: post_type` with `object_id`) or at a plain URL (`type: custom`).

`update_menu` takes `menu_id` and the **whole item tree**. Read it, change what you need, send all of it back; items keep their `id` to be updated in place, an item without one is created, and an item you leave out is deleted.

Whole-tree rather than per-item because that is the only form in which a menu is reviewable. "Relabel two, repoint one, nest the last" is several proposals a reviewer has to hold in their head at once; one tree is a single before-and-after they can read. It also makes staleness honest — the thing being replaced is the menu, so the thing hashed is the menu, and a proposal filed before someone edited it in wp-admin is parked rather than silently reverting their work.

This is what the menu work was actually waiting on. The write was never the hard part; approving a menu change was, because a menu diffed as JSON is unreadable and diffed as prose says nothing. The queue renders both sides as an indented `label → target` tree, which makes the edit a translation produces — same label, different destination — visible at a glance.

**Menus ship switched off.** They are site structure rather than content, so a separate *Allow menu edits* toggle gates the action, `capabilities.menus` reports it, and `update_menu` drops out of the advertised `actions` when it is off. Creating a menu and assigning it to a theme location are deliberately still outside the API; those are one-time structural decisions, where repointing items is the repetitive work an agent should do.

### GET /elements

GeneratePress Elements are ordinary posts of the `gp_elements` type, so once an operator adds that type to *Allowed post types* every existing read and write applies to them unchanged. What is not ordinary is what they mean: an Element is site furniture — a hero, a footer, a script injected into `wp_head` — and its behaviour lives in `_generate_*` postmeta, not in its content.

That meta is now proposable as fields (see the field table): a footer call-to-action is one `create` with `element_type`, `block_type`, `hook`, and display and exclude conditions, drafted with its behaviour already set. `create_translation` still copies the meta across from the source. Before this, a created Element arrived inert and the reviewer finished it in the editor.

`GET /elements` surfaces that meta: `element_type` (`block` / `hook` / `layout`), `block_type`, `hook` and priority, and the display, exclude and user conditions. It also reports `editable`, so an agent learns whether proposing against Elements is permitted here without first having a proposal refused. Without this an agent sees a pile of oddly-named pages and cannot tell that the footer CTA is one Element rather than something repeated on forty pages.

The review screen flags any change to a `gp_elements` post, because a diff of an Element looks exactly like a diff of a page while approving it changes every page the Element renders on.

**Elements and languages.** When a multilingual plugin manages `gp_elements` — Polylang does by default — each Element belongs to one language and only runs on pages of that language. A site whose Elements are all in the source language will render a translated page with no header, no footer and none of the CSS or tracking those Elements inject. The fix is an Element per language, which is what `create_translation` is for: text-bearing Elements get translated, and the ones that only inject CSS or a script are created with no `texts` at all, existing purely so they run in the other language too.

### WooCommerce products

Present when WooCommerce is active and an operator has ticked `product` under *Allowed post types*. A product is a post, so its title, description (`post_content`), short description (`post_excerpt`), SEO fields, featured image and the block actions all work on it unchanged. What a product adds is the data WooCommerce owns, and that is written differently.

Price, sale and stock live in postmeta **and** in `wc_product_meta_lookup`, the table the shop's filtering and sorting query instead of the meta. Writing the meta directly, or through `wp_update_post`, desynchronises the two. So those fields go through `ProductApplier`, which uses `wc_get_product()` setters and one `save()` — and **every write to a product ends in that save**, including a change that only touched its description. The save with nothing to set is deliberate: WooCommerce's own editor ends every edit in `WC_Product::save()`, feed and search integrations listen for the `woocommerce_update_product` it fires, and it fills in whatever Woo meta a product drafted as a bare post lacks, so a `create` arrives as a complete simple product, lookup row included. Woo's product instance cache is invalidated by `clean_post_cache` and meta writes, so the post-level write before it is read back fresh.

`GET /content/{id}` on a product adds a `product` object: `type`, `currency`, `prices_include_tax`, the active `price`, `on_sale`, every product field with its current value, `variations` on a variable product, and `proposable` — the fields this product takes here, given its type and the switch below. `GET /content?fields=product` adds a short version per row, so a price audit is one call. `GET /taxonomies` adds `product_categories` and `product_tags`.

Fields are WooCommerce's own REST API names, so what an agent already knows about Woo carries over:

| Field | Notes |
|---|---|
| `product_categories`, `product_tags` | Existing term slugs, as for `categories`. |
| `catalog_visibility` | `visible`, `catalog`, `search`, `hidden`. |
| `featured` | true or false. |
| `regular_price`, `sale_price` | Dot-decimal strings, `"49.90"`. A JSON number or `"49,90"` is accepted and stored with a dot; anything with a thousands separator or a currency sign is refused, because `1.290` read as a decimal is the mistake nothing downstream would catch. `""` as `sale_price` ends a sale; `regular_price` cannot be cleared. A sale price must be below the regular price, judged on the values *after* the change — so lowering the regular price under an existing sale is refused too, where WooCommerce would quietly stop treating the product as on sale. |
| `date_on_sale_from`, `date_on_sale_to` | `YYYY-MM-DD` in the site's timezone. As in the product editor, a sale starts at 00:00:00 of its first day and ends at 23:59:59 of its last. Needs a sale price; an end date in the past is refused. |
| `sku` | Unique across the shop, checked with WooCommerce's own `wc_product_has_unique_sku()`; the refusal names the product holding it. |
| `stock_status` | Only while stock is not managed. With `manage_stock` on, WooCommerce derives the status from the quantity at save and would overwrite a proposed one, so that is refused rather than silently lost. |
| `manage_stock`, `stock_quantity`, `backorders` | Quantity and backorders only with `manage_stock` true, and turning it on needs a quantity. Refused outright while stock management is off shop-wide. |

By product type: **simple** takes everything; **external** takes prices and SKU but no stock, since WooCommerce keeps none for it; **variable** and **grouped** take SKU, visibility and featured only — a variable product's prices and stock live on its variations, which are not proposable yet. Types a plugin adds (bundles, subscriptions) get that same conservative set, since their prices are computed somewhere Accesslink has never seen. A `create` makes a simple product.

**Prices, stock and SKUs ship switched off.** They change what customers pay and can order the moment a change is approved, so like menus they sit behind their own *Allow price and stock edits* toggle, rendered only while WooCommerce is active (saving the settings with Woo deactivated leaves it as it was). Descriptions, product categories and visibility need only the post type. With the toggle off the commerce fields drop out of `allowed_fields`, and a proposal naming them is refused with `403 commerce_disabled` — by name, not dropped with the other unknown fields, because an agent that sent a price and got a `201` for the title alone would report the price as proposed. A price change queued before the toggle went off fails at approval. `capabilities.products` and `capabilities.commerce` report both.

Staleness needs no special case: the hash covers the values being replaced, read through the same `ProductApplier::read_field()` the diff uses. That earns its keep on stock, which moves on its own — an order between proposal and approval parks a stock change as `stale` instead of overwriting the sale.

The review card for a product names its type, current price, stock and SKU, and a change touching commerce fields carries a warning that approving it changes what customers pay or can order. Each proposed price is rendered as the shop prints it, beside the old one and the change in per cent, and flagged when it moves by half or more: a misplaced decimal point is the mistake that matters, and `790` against `79.00` in a text diff hides it where `+900 %` does not. *Preview proposed version* still swaps only post columns, so it shows a changed description but not a changed price.

**Orders, coupons and subscriptions** have an admin UI, so they are offered under *Allowed post types* like any other type, and a test site may want them. Each carries a warning beside its checkbox: orders and subscriptions are customer records whose edits bypass WooCommerce's own handling, and a coupon's title is a live discount code.

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
| `sidebar_layout`, `content_container`, `hide_title` | GeneratePress per-page layout, present only when that theme is active: the same three choices as the editor's Layout panel, with `default` meaning "inherit the site setting". A page built from full-width front-page sections wants `no-sidebar` and `full-width`, or it opens inside whatever the theme default is. Refused on `gp_elements`. |
| `element_type`, `block_type`, `hook`, `custom_hook`, `hook_priority`, `display_conditions`, `exclude_conditions`, `user_conditions` | What a GeneratePress Element *does*, in the names `GET /elements` returns. Only on `gp_elements`, and listed only when that type is allowed. Hooks are validated against GP Premium's own list; condition rules are checked for shape (`{"rule": "post:page", "object": "712"}`), not against the site's rule catalogue — the editor is where a wrong one shows up immediately. |
| `product_categories`, `product_tags`, `catalog_visibility`, `featured`, and — with the commerce switch on — `regular_price`, `sale_price`, `date_on_sale_from`, `date_on_sale_to`, `sku`, `stock_status`, `manage_stock`, `stock_quantity`, `backorders` | WooCommerce product fields. Only on `product`, and listed only when that type is allowed. See *WooCommerce products*. |

A `create` also takes a top-level `slug`. A new draft has nothing to redirect from, so it needs none of the care a rename does; renaming an existing post is still not offered.

Everything is validated at **propose** time, not just at apply, so an agent finds out immediately and the reviewer's queue doesn't fill with proposals that were never applicable. Apply re-checks anyway, since the site can move underneath a queued change.

#### SEO across plugins

The agent uses normalised names; Accesslink maps them onto whatever the site runs. Rank Math (`rank_math_*`) and Yoast (`_yoast_wpseo_*`) are supported. All in One SEO and SEOPress are *detected but not writable* — they keep data outside postmeta, so the fields are reported unavailable rather than written somewhere that does nothing. `GET /guide` reports the resolved adapter as `seo_plugin`.

Scope is three fields on purpose. `noindex` is excluded: an agent proposing to deindex a page is a decision with delayed, silent, severe consequences that a reviewer skimming a diff would not weigh correctly.

Existing values frequently contain plugin template variables that expand at render time — `%%sep%%` on Yoast, `%sep%` on Rank Math. The adapter owns that spelling: the guide prints the site's own variables and the title template a post type falls back to, and the review screen renders a proposed `seo_title` or `seo_description` through the plugin's replace-vars with its length, so a reviewer judges what Google will show rather than the stored string. The guide hardcoded Rank Math's syntax once and was wrong on every Yoast site.

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

What an agent authored is sanitised once, at propose time, whoever ends up approving it: a `post_content` on a create or update, the `html` or `text` of a block edit, the `markup` of an insert. What the site already had is never filtered — the document rebuilt for a block edit is the site's own markup around one sanitised fragment, and it is applied as-is.

It was not always so. Filtering used to happen at apply time by the reviewer's capability, mirroring WordPress's own `unfiltered_html` rule, and that had two faults. The queue could show one thing and an Editor's approval apply another. And for block edits the *whole rebuilt document* went through the filter, so an Editor approving a one-word change on a page full of SVG icons stripped every icon on the page. Sanitising the fragment at propose time and applying the document raw closes both: the diff is the truth, and the site's markup is not the agent's to lose. `create_translation` is the one path with no sanitiser at all, because its markup-skeleton check makes injection impossible and the sanitiser damaged the site's own SVG there.

A second subtlety, because it silently defeated the above for a while: WordPress adds `wp_filter_post_kses` to `content_save_pre` whenever the current user lacks `unfiltered_html`, and a propose-time request has **no user at all**. So every `create` had its content filtered a second time on save, by core's kses rather than by `ContentSanitizer` — which stripped every inline `<svg>` back out, exactly what `ContentSanitizer` exists to prevent. Every save path runs through `PostApplier::without_kses()`, which lifts those filters and restores them. Verified adversarially: `<script>`, `onclick`, `onerror`, `javascript:` URLs and external `<use>` references are all still removed.

Filtering does **not** go through `wp_kses_post()` directly. That call preserves block delimiters fine — they are HTML comments and modern kses keeps them — but it has no allowlist for inline SVG, and on a GeneratePress/GenerateBlocks site SVG icons are everywhere. Measured on this project's own front page, plain `wp_kses_post()` stripped 42 `<svg>`, 82 `<path>` and every `<g>`/`<circle>`/`<defs>`/`<mask>`, costing 14% of the document; across all 16 block posts it lost 7.9%.

`ContentSanitizer` is therefore `post` plus a conservative SVG subset, which brings the loss to 0.5% while still removing `on*` handlers, `<script>` inside SVG, `<use>` (external document references) and `javascript:` protocols. `<style>` is deliberately not allowed, which accounts for most of the remaining 0.5%.

---

## Degrading rather than failing

Sites differ, and absence of a plugin narrows what Accesslink offers instead of breaking it:

- **No SEO plugin, or an unwritable one** — the SEO fields drop out of `allowed_fields` entirely and a proposal naming them is refused with the reason. `GET /guide` reports the resolved adapter and lists what is unavailable on this site.
- **A post type without a taxonomy** — refused by name rather than written where nothing renders it.
- **A block type whose plugin isn't installed** — `insert_block` refuses it by name.
- **Not GeneratePress** — the three layout fields drop out of `allowed_fields`; **no GP Elements, or `gp_elements` not in the allowed types** — the element fields drop out, so the guide never advertises a field every proposal naming it would be refused for.
- **No WooCommerce, or `product` not in the allowed types** — the product fields and taxonomies drop out and the `products` guide section is absent; **commerce switch off** — prices, sales, stock and SKU drop out, and a proposal naming them is refused by name rather than dropped.
- **Logging module off** — audit calls are wrapped and swallowed; nothing depends on it.
- **Queue table never installed** — every endpoint answers `503 accesslink_unavailable` instead of leaking SQL errors.

`GET /guide` returns a `capabilities` object so an agent can branch on what a site supports rather than discovering absence through a failed proposal.

## Not built yet

Named here so nobody assumes otherwise. The roadmap (`ROADMAP.md`, *Accesslink follow-ups*) carries the order and the reasoning.

- **WooCommerce beyond simple products.** Variations — a variable product's prices and stock — and attributes, gallery images, shipping and tax class, upsells and cross-sells, changing a product's type, and an external product's URL. Nor does the front-end preview render a proposed price yet.
- **Attachment fields.** Alt text, title and caption of media items. Reads exist; there is no applier for the `attachment` entity yet, and on GenerateBlocks pages the rendered alt lives in the block markup rather than on the attachment.
- **Custom fields / ACF.** Common on agency sites, entirely absent here.
- **Renaming and scheduling.** `post_name` on an existing post and `post_date`. A rename ships together with redirects or not at all.
- **Media upload.** `/media` is a picker, not an uploader; a page an agent creates has no images unless the library already holds them.
- **Reverting an applied change**, though the revision to revert to already exists.
- **Grouped or atomic batches.** Every proposal is reviewed and applied on its own.
- **Reading back a proposal's payload.** `GET /changes/{id}` returns metadata, not the proposed content, so an agent cannot inspect what a *different* agent queued — only that something is queued.
- **Per-key scoping.** One key per site; the only granularity is the site-wide allowed post types list.
- **Remote approval from EngineLink.** The service layer is already the single implementation behind both entry points, so aggregating queues across sites is additive — but it needs an approver credential that is not the propose key.
- **Menus: creating one, setting its language, assigning a theme location.** Repointing items ships; those one-time structural decisions stay in wp-admin.
