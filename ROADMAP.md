# ROADMAP.md — Valolink Plugin

Phase 1 modules live in `CLAUDE.md`. This file is everything else: deferred modules, scoped-down rewrites of the original brainstorm, and notes on why each one is parked.

Order within each phase is suggested, not fixed.

---

## Phase 2 — Performance & Operations

### Module F — Advanced Performance Optimizations
**Objective:** Surgical perf wins that complement WP Rocket / standard caching.

- Selectively disable specific plugins during specific AJAX actions / REST routes / cron events.
- Admin UI: per-plugin allowlist of contexts where the plugin can be skipped. Manual entry in v1; informed by Module I's profiling output later.
- Other low-level toggles for bottlenecks not covered by page caching (heartbeat throttle, emoji/embed cleanup, REST API discovery headers, etc.) — keep this list short and only add what's been verified to matter.
- Must not break WooCommerce cart/checkout or core admin flows. Hard exclusions: anything firing during `woocommerce_*` AJAX actions, anything during checkout.

### Module J — JavaScript Interaction Loader
**Objective:** Defer non-essential JS until user interaction to improve Core Web Vitals.

- Targeted scripts (by handle or URL pattern) deferred until first scroll/click/mouse/touch event, with a fallback timeout (e.g., 5s) that loads them anyway.
- Admin UI: include list, exclude list, timeout config.
- Hard exclusion defaults: jQuery core, WooCommerce cart/checkout scripts, any script with `data-no-defer` attribute.
- Test against Woo cart updates and checkout end-to-end before shipping.

### Module D — Logging
**Objective:** Structured event logging without bloating core tables.

- Custom table `{prefix}valolink_log`. Indexed by timestamp + level + module.
- Automated rotation: retain N days (configurable), prune on cron.
- Admin viewer loads asynchronously (REST endpoint + paginated table, no full-table SELECT on page load).
- Once shipped, other modules switch from `error_log()` to a `Valolink\Plugin\Log::write()` helper. Helper is a no-op if module D is disabled — modules must not hard-depend on D being on.

---

## Phase 3 — Admin UX & Hardening

### Module G — Site Health & Best Practice Auditor
**Objective:** Flag risky defaults and sub-optimal companion-plugin settings.

- Audits run on-demand from an admin screen, not on every page load. Results cached for 24h.
- Core checks: default permalinks, open user registration, `admin` username present, file editing enabled, debug mode in production, etc.
- Companion checks (extensible): WP Rocket configuration aligned with agency baseline, Woo flags, etc. Each check is a class so adding new ones is trivial.
- Output: lean summary screen with severity + remediation pointer per finding. No auto-fix in v1.

### Module H — Agency Curated Plugin Installer
**Objective:** Speed up new-site setup with the agency's vetted plugin list.

- Curated list fetched from the Nuxt app on-demand when the admin opens the installer screen. Cached for an hour.
- One-click install + activate for entries from wp.org; for licensed/private plugins, support uploading a signed zip URL from the Nuxt-side manifest.
- No persistent footprint when the admin isn't on the installer screen.

### Module A — Security (Scoped Down)
**Objective:** Targeted hardening that doesn't try to replace Wordfence/Solid/Patchstack.

Original brainstorm was too broad. Keep only the pieces that are high-value and cheap to maintain:
- Login protection: rate-limit failed logins per IP + per username, optional country block.
- Vulnerability alerts: poll wp.org / wpvulndb feed on cron, surface alerts for installed plugins/themes/core matching known vulnerabilities. Push to Nuxt via Module C.
- IP blocklist: in-memory (object cache) primary store, periodic flush to DB. Never a synchronous DB write on a blocked request.

**Explicitly out of scope** (use a dedicated security plugin instead): file integrity monitoring, malware scanning, WAF rules, 2FA. Adding any of these would dwarf the rest of the plugin.

---

## Later / Lowest Priority

### Module C v2 — Bidirectional Remote Control
**Objective:** Let the Nuxt app trigger actions on WP sites (toggle modules, trigger updates).

Deliberately deferred until v1 inventory push is solid. The threat surface flips: now Nuxt → WP, which means authenticated inbound endpoints on every client site. Design needs:
- Per-site asymmetric key pair (Nuxt holds private, WP holds public).
- Signed, timestamped, single-use commands with explicit scopes.
- Audit log of every command received and executed.
- Kill switch in WP admin to revoke trust without needing Nuxt access.

Do not start this before Phase 2 ships.

### Accesslink follow-ups
**Objective:** widen what an agent can actually do through the change queue.

Spec and current wire shapes: `accesslink.md`. Ordered by value × cheapness × fit
with what already exists, not by ambition. Tier 0 is a defect in shipped code, and
everything below it assumes the review gate is honest — so it goes first.

Test site: `staging.valolink.fi` (Polylang 3.8.7, GenerateBlocks, Rank Math,
Redirection). Deployed as a plain file copy under `valolink-web`, no git checkout —
rsync `src/` to test, and remember the live `valolink.fi` sits under the same user.

#### Tier 0 — the review gate

- [x] **Structural changes were approved blind** — fixed. `update_text`,
  `insert_block`, `delete_block` and `move_block` all rendered an empty diff and a
  preview showing the *unchanged* page, because `QueuePage::render_diff()` and
  `AccesslinkModule::filter_preview_posts()` both special-cased `update_block` and
  then looped over `payload['fields']`, which those five actions do not carry. Five
  of seven actions were unreviewable, including `update_text` — the one the guide
  tells agents to prefer. All three call sites now resolve through
  `ChangeService::proposed_content()`, structural changes get a block-outline tree
  diff, and a preview that cannot be built fails loudly instead of rendering the
  current page. Verified end to end on staging across all four actions.

#### Gaps the front-page translation exposed

Everything below had to be done over SSH or in wp-admin, so an agent asked to
translate a site cannot finish the job on its own.

- [x] **Assigning a language to a post that has none** — shipped as the
  `set_language` action, queued and reviewed like anything else. It only fills a
  gap: a post that already has a language is refused, because changing one pulls
  it out of its translation group, which is a data decision rather than a content
  one. Verified on staging that `set_language` → `create_translation` now
  completes a chain that previously required wp-admin.
- [~] **Navigation menus** — first slice shipped. `GET /menus`, `GET /menus/{id}`
  and an `update_menu` action taking the whole item tree, behind an *Allow menu
  edits* toggle that is off by default. The blocker was never the write but the
  review: the queue now renders both sides as an indented `label → target` tree,
  which is the same tree diff the block outline uses. Verified on staging by
  adding a Home entry to the English menu, and by racing a wp-admin edit against
  a pending proposal — the human's edit survived and the proposal went `stale`.
  Still by hand: **creating** a menu, setting its language, and assigning it to a
  theme location, including Polylang's per-language location map. Those are
  one-time structural decisions; repointing items is the repetitive part and is
  now covered.
- [x] **Repairing an existing translation's postmeta** — shipped as
  `sync_translation_meta`, which copies from the source only the keys the
  translation is *missing*. Additive on purpose: the obvious thing to overwrite
  is exactly the thing that should differ, the target-language SEO title someone
  corrected after the source's was copied in. Verified that a stripped
  `_generate-disable-headline` came back while a hand-edited `rank_math_title`
  was left alone.
- [x] **Purging the page cache after a non-content change** — shipped as
  `CacheCleaner`, called after a menu apply. Post edits still need no help; a
  menu is not a post, so nothing fires and the change would be real but
  invisible. Verified by warming the English page as a visitor, approving a
  relabel, and seeing the new label without any manual purge. Guarded calls for
  WP Rocket, LiteSpeed, W3TC and WP Super Cache, plus an action for anything
  else.
- [x] **Permanently deleting a draft** — closed, but not by adding a delete
  action. WordPress empties the trash by itself after `EMPTY_TRASH_DAYS`, and a
  trashed draft is not publicly reachable, so nothing needed deleting; adding a
  delete verb would have widened the blast radius for no gain. What *was* broken
  is that a rejected translation stayed in its translation group, so re-proposing
  it was refused as `already_translated` pointing at a post in the trash —
  rejection is now an unlink as well as a trash, and proposing ignores trashed
  group members so older rejections do not block a retry either.

- [x] **The `action` column was too narrow.** `varchar(20)` against a 21-character
  `sync_translation_meta`: the insert failed, nothing surfaced, and the API
  answered `201` with a row of nulls. Widened to `varchar(32)` at schema 3, and
  `announce()` now converts an empty result into a `queue_write_failed` error, so
  a failed queue write can never again look like a successful proposal.

#### For the main branch, not this one

- [ ] **Restrict the Valolink admin screens to administrators.** Every module's
  wp-admin page is currently reachable by any role the menu capability lets in,
  and the Accesslink screen in particular exposes the propose key and the writes
  kill switch. The menu should be registered for `manage_options` only, and each
  `admin_post_` handler should capability-check to match rather than relying on
  the menu being hidden. Deliberately **not** done on the `accesslink` branch —
  it touches every module's registration, so it belongs in its own change on
  `main`.

#### Found while building the translation slice

- [x] **Core kses silently undid ContentSanitizer** — fixed. `wp_insert_post` /
  `wp_update_post` apply `wp_filter_post_kses` whenever the current user lacks
  `unfiltered_html`, and a propose-time request has no user, so every created
  draft lost its inline SVG *after* `ContentSanitizer` had deliberately kept it.
  All save paths now go through `PostApplier::without_kses()`. Re-verified that
  script tags, `on*` handlers, `javascript:` URLs and external `<use>` refs are
  still blocked.
- [ ] **A non-admin reviewer still degrades content on approval.** `set_status`
  and `apply_update` now lift kses, but `filter_content()` itself returns
  `ContentSanitizer::filter()` for a reviewer without `unfiltered_html` — an
  Editor approving a change to a page full of SVG icons would strip them from
  the whole document, not just the edited part. Either restrict approval to
  `unfiltered_html`, or filter only the incoming fragment.
- [x] **Menus, GeneratePress Elements and per-language site chrome** — resolved
  for this site. The cause was not display conditions: Polylang translates the
  `gp_elements` post type, so all 17 Elements were `fi` and none ran on an `en`
  page — including the hook Elements that inject the header's CSS. Nine
  site-wide Elements now have English counterparts created through Accesslink
  (four translated, five language-neutral copies), and an English menu is
  assigned to both theme locations. See the Elements section below.
- [x] **Empty JSON objects in block delimiters were silently rewritten** —
  fixed. `parse_blocks()` decodes with `json_decode($json, true)`, so
  `{"styles":{}}` came back as an empty array and `serialize_blocks()` wrote it
  out as `{"styles":[]}` — an attribute change on blocks the edit never touched,
  on every text edit. `BlockReader::preserve_delimiters()` now restores the
  original delimiters whenever the delimiter count is unchanged. Structural
  actions legitimately change that count and are still exposed; worth revisiting
  if a GenerateBlocks page ever comes back invalid after an insert or move.

#### Found doing SEO on jet-steel.fi (2026-09-09)

First run on a real client site: a full audit, then 17 proposals in one sitting —
13 Yoast metadata updates, 3 `insert_block` call-to-actions, 1 `create` of a
service-page draft. The loop held. Idempotency keys made the filing script safe to
rerun three times without a duplicate, `/validate` and the per-post
`pending_changes` list did their jobs, and the notes endpoint carried the
conventions forward. Everything below is friction *around* the loop, in the order
it cost time.

- [x] **The queue listed newest first and the outline was always open.**
  Approving reloads the page at the top, so the next change to review has to be
  the top one — and proposals against one document often only apply in filing
  order. Pending is now oldest first (REST `GET /changes` keeps newest first,
  since an agent polling it wants what just happened); the block outline sits in
  a `<details>` folded by default.
- [x] **The guide hardcodes Rank Math's variable syntax.** Fixed in 0.2.2: the adapter owns the spelling, the guide prints the site's variables and title template, and the queue renders SEO fields with a length. `GuideBuilder.php:314`
  tells every agent to preserve `%sep%` / `%sitename%`, on Yoast sites too, where
  the syntax is `%%sep%%`. The agent guessed right, and the queue could not have
  told anyone either way because the diff shows the raw stored string. The SEO
  adapter should own the variable syntax, the guide should print the site's
  resolved title template ("titles render as `%%title%% - Jet Steel Oy`"), and
  the queue should render `seo_title` / `seo_description` through the plugin's
  own replace-vars with a character count beside them. Independent of which
  plugin a site runs — jet-steel is moving to Rank Math, and the bug would simply
  flip sides.
- [x] **Auditing needs one read per post.** Fixed in 0.2.2: `GET /content?fields=seo,terms,media,layout`. `GET /content` omits SEO fields,
  categories and featured image, so "which posts have no description" and "which
  are in the wrong category" took ~35 `GET /content/{id}` calls. A
  `fields=seo,terms,media` parameter on the list, or the audit read in Tier 1.
  The missing categories are what hid the Artikkelit problem: that page's query
  loop lists `yleinen`, both SEO articles sat in `ruostumatonta-tietoa`, and the
  page rendered empty.
- [x] **Staleness for `update` hashes the modification time.** Fixed in 0.2.2: values only. Proven on staging by approving an excerpt change and then an SEO change on the same post. `PostApplier::hash()`
  folds `post_modified_gmt` in, so two proposals touching disjoint fields on one
  post cannot both apply — the second parks as `stale` once the first bumps the
  stamp, which is why the category move had to be folded into the metadata
  proposals. The field values are the honest question ("are the values I am
  replacing still what I saw?"); the stamp only adds false positives. Drop it.
- [ ] **Thirteen identical proposals were thirteen approvals and thirteen reloads.**
  Not atomicity — the first slice is review: an optional `batch` label on
  proposals, grouped in the queue under one *Approve all* button, each still
  applied and stale-checked on its own with a result per row. The attachment alt
  sweep in Tier 1 will produce a hundred proposals and is unusable without this.
- [x] **`create` does not accept `slug`; `create_translation` does.** Fixed in 0.2.2. The
  service-page draft has no URL yet, and for a landing page the URL is the point.
  A slug on a new draft has nothing to redirect from, so it needs none of the
  redirect pairing a rename needs.
- [x] **`insert_block` needs a path even to append.** Fixed in 0.2.2: `position: start | end` with no path. "Add a call-to-action at
  the end" is the common case and took a `/blocks` read to find the last sibling.
  `position: start | end` without a path, at the top level.
- [x] **`/taxonomies` returns slugs; query loops reference term ids.** Fixed in 0.2.2. Inferring
  that term 1 was Yleinen worked, but it was a guess. Add `id` per term.
- [x] **The translation `outdated` flag is symmetric.** Fixed in 0.2.2: anchored to the group's source, reported as `source_id`. Read from the English
  home, the Finnish *source* was flagged outdated because it was older — the
  wrong advice. Define outdated for translations relative to their source only.
- [x] **`create` cannot set an Element's type, hook or display conditions.** Fixed in 0.2.2: element fields on create and update, hooks validated against GP Premium's list, conditions checked for shape; and the three page layout keys as `sidebar_layout`, `content_container`, `hide_title`. Proven on staging by creating a before-footer Element and a full-width page in one proposal each.
  Found when proposing a footer call-to-action as a `gp_elements` post: the
  draft arrives with none of the `_generate_*` meta, so the reviewer picks the
  type, hook and conditions in the editor before publishing. `ElementReader`
  already maps the keys; accepting `element` fields on `create` (and `update`)
  with the same shape `GET /elements` returns, validated against GeneratePress's
  own hook and rule lists, would let an agent finish the job. The review screen
  already warns that an Element change is site-wide. The same gap covers a
  page's own layout meta (`_generate-sidebar-layout-meta`,
  `_generate-full-width-content`, `_generate-disable-headline`): a page
  created through Accesslink opens with the theme default, so the Palvelut
  draft built from full-width front-page sections needs "no sidebar, full
  width" set in the editor before it looks like the front page. Reading those
  three keys on `GET /content/{id}` and accepting them on `create` is the
  small version of this item.
- [x] **Allowed post types are checkboxes now**, listing every type with an
  admin UI (attachments excluded, since Accesslink cannot write them). A saved
  type whose plugin is inactive stays listed and checked, so saving the form
  does not silently drop it. The comma-separated string is still accepted on
  save for scripted POSTs.
- [~] **The media list cannot be chosen from.** First slice in 0.2.2: `attached_to`, the post an image was uploaded to. "Used on" across content is still open; it needs a content scan. Entries titled "164", "163",
  empty alt, no usage — and an agent cannot see pixels. Return which posts use
  each attachment, so "the image on the säiliöt article" becomes addressable.

Not roadmap, but recorded so it is not rediscovered: the demand data (keyword
targets, Search Console rows) lives in EngineLink and reached the agent as an
exported PDF. A read-only site brief on EngineLink's existing bot zone would close
that gap for any agent, not only Sidelink — that is an EngineLink backlog item.
And on jet-steel specifically, allowing `gp_elements` would let the after-article
call-to-action be one Element instead of an edit to each technical article the
client wants left alone.

#### Tier 1 — cheap, and the existing machinery already fits

- [ ] **`post_name` and `post_date`.** An agent cannot currently rename or schedule
  anything. Two entries in `PostApplier::POST_FIELDS`. Ship slug together with
  redirects — a silent rename is worse than no rename. (`slug` on `create` is
  the separate, redirect-free case noted above; it need not wait for this.)
- [ ] **Attachment alt text / title / caption.** New `entity_type` (the column
  exists and defaults to `post`), small applier, trivial diff, near-zero blast
  radius. Unlocks an accessibility + SEO sweep across a whole media library:
  high-volume, billable, and miserable by hand. Necessary but not sufficient on
  GenerateBlocks sites: the alt Google sees on jet-steel's front page comes from
  the block markup, not the attachment, so a safe `set_image_alt` at a block
  path belongs beside this applier — or the sweep changes the library and not
  the pages. Needs the grouped review above to be usable at volume.
- [ ] **Content audit reads.** `GET /audit/content`: missing meta descriptions,
  images without alt text, thin content, orphan pages, broken internal links. No
  new write surface — only reads that already exist. Aims at the top friction in
  the root `CLAUDE.md` (employees inventing work when the queue is empty) by
  turning Accesslink into a source of concrete claimable work rather than only a
  way to apply edits. Moved up from Tier 2 after jet-steel: the audit was most of
  that session, and every part of it was a read that already exists.
- [ ] **`wp_block` (synced patterns).** Just a post type — but editing one changes
  every page using it, so the queue has to show "used on N pages" or approval is
  blind.
- [ ] **Revert an applied change.** `wp_update_post` already wrote a revision at
  apply time, so a Revert button on applied rows is nearly free. Moves how risky
  approving *feels* more than anything else on this list.

#### Tier 2 — real work, real payoff

- [~] **Polylang translations.** First slice shipped: `GET /languages`,
  `GET /content/{id}/translations` and the `create_translation` action, behind a
  `Translation/` adapter (Polylang 3.7+ via `pll_*`; WPML detected-unwritable).
  Proven on staging by translating the 260-block front page into English —
  markup skeleton identical, all 21 SVG icons and 13 `<mark>` styles intact.
  Still open: `sync_translation` (mirror a source change into an existing
  translation), term translations, and the outdated-translation report as a
  standalone endpoint. See the design subsection below.
- [ ] **ACF / custom fields.** CPT UI is on much of the fleet, so custom post types
  exist that are only half-readable now. Needs discovery first — `GET /field-groups`
  and `GET /content/{id}/fields` — because an agent cannot guess field keys. v1 is
  scalars only (text, textarea, wysiwyg, number, url, image id, select, true/false);
  repeaters and flexible content are where the cost lives, and they wait.
- [ ] **Media upload by URL, sideloaded at approval.** Without it every created page
  is imageless. Fetch happens only on approve, behind a host allowlist with MIME
  and size limits. Worth more than its place here suggests: a service page drafted
  without images gets judged on that before anyone reads it, which is exactly what
  the jet-steel exemplar page will face.
- [ ] **Redirects.** Adapter for the Redirection plugin, degrading to unavailable.
  Pairs with slug changes, which needs the next item.
- [ ] **Atomic batches.** A `batch_id` with all-or-nothing apply. Today every
  change stands alone, so a translation plus its menu entry plus its redirect can
  be approved piecemeal and leave the site half-done. Architectural, and the unlock
  for every multi-entity item here. This is the second slice; the first — grouped
  review with one *Approve all*, no change to apply — is in the jet-steel section
  and should ship first, because it is what metadata and alt sweeps need.

#### Tier 3 — deferred, with the reason

- [ ] **WooCommerce products.** A `ProductApplier` beside `PostApplier` going through
  `wc_get_product()` setters and `save()` — price/stock/SKU live in postmeta *and*
  Woo's `wp_wc_product_meta_lookup`, so `wp_update_post` desynchronises them. Queue,
  auth, staleness gate and review UI need no changes. Build it when a Woo client asks.
- [ ] **Menus.** `nav_menu_item` posts as a tree. Blocked on review, not on the write:
  a text diff of a menu is meaningless.
- [ ] **GeneratePress Elements.** Read first (type, hook, display conditions resolved
  to which posts they apply to) so an agent can tell the footer CTA is an Element and
  not a page. Writing a global template is a much later conversation.
- [ ] **Contact Form 7.** Read-only value only — knowing what a form collects is
  useful; rewriting a form template risks lead capture.
- [ ] **Remote approval from EngineLink.** `ChangeService` is already the single
  implementation behind both the REST routes and the admin screen, so aggregating
  every site's queue into one Nuxt page is additive — but it needs an approver
  credential distinct from the propose key, since the whole design rests on those
  not being the same secret. Closer than it looks: the queue-order fix of
  2026-09-09 was a symptom. Reviewing a fleet every month through one wp-admin at
  a time will not scale, and the service layer is already shared.
- [ ] **Per-key scoping** (which post types, which categories) and **reading back a
  proposal's payload**. Both are prerequisites for multiple agents or an EngineLink
  reviewer; neither matters while one operator reviews in wp-admin.

**Not worth doing:** theme or plugin file edits, options and customizer, user
management, plugin install/activate (that is `hestiascripts`' job), comment
moderation.

#### Polylang translations — design

The hard part already exists. A translation of a GenerateBlocks page is a structural
clone with translated leaf text, and `BlockReader` already flattens a document to
addressable paths with `editable` leaves.

Reads:
- `GET /languages` — configured languages plus the resolved adapter, mirroring the
  SEO adapter pattern. WPML would be detected-but-unwritable.
- `GET /content/{id}/translations` — per language: id, status, link, modified,
  missing and stale flags.
- `GET /translations/status?post_type=page` — the matrix. "17 pages, 12 in en, 3
  outdated, 2 missing." Nothing in WordPress gives you this cheaply and it is a
  report worth putting in front of a customer.

Writes, queued like everything else:
- `create_translation` — draft in the target language, linked into the translation
  group. Same semantics as the existing `create`: draft written immediately,
  approval flips status.
- `sync_translation` — source changed, so mirror the structural change into the
  translation and leave translated leaves alone except where the matching source
  leaf moved. This is the one that earns its keep; manual translation drift is
  exactly what nobody catches.

Plain `update` on an existing translation already works today if the translated post
is in `allowed_post_types`, so the minimum viable version is the reads plus linking.

Four things to settle:
1. **Staleness needs a new marker.** Polylang does not track "translated at source
   version". Accesslink would write its own postmeta (`_accesslink_source_hash`) at
   apply time — a new persistent footprint, so `uninstall()` has to clear it.
2. **The reviewer usually cannot judge the target language.** Approving an English
   page means "structurally sound and plausible", not "correct English". The queue
   screen should show source and translation side by side and assert block-name
   equivalence, and the honest limit should be stated rather than implied away.
3. **Term translations** (`pll_save_term_translations`) — without them a translated
   post cannot be categorised.
4. **Verify against the installed version before building.** The `pll_*` helpers and
   the `post_translations` hidden-taxonomy internals are not a public contract, and
   free and Pro differ.

Out of scope for translation v1, so nobody expects a fully translated site from it:
menu translations and theme/plugin string translations (Loco).

### Module K — Isolated Shortcode Lazy Loader
**Objective:** Last-resort optimization for genuinely unfixable heavy shortcodes/plugins.

User has shipped this pattern before; the design is known-good:
- Register a hidden CPT (`valolink_isolated`) for hosting individual shortcode outputs.
- Custom minimal page template for that CPT: skip theme header/footer, load only the WP core needed to execute the embedded shortcode.
- Wrapper shortcode `[valolink_isolated id="…"]` outputs a `loading="lazy"` iframe pointing at the CPT's permalink.
- Parent ↔ iframe `postMessage` channel to auto-resize iframe height to match content.

Lower priority because F + J cover most of what most clients need.

### Module I — Plugin Profiling & Advisory
**Objective:** Help admins decide what to feed into Module F's exclusion list.

Reframed from the original "static analysis" idea, which is research-grade and error-prone. Better approach: **runtime hook profiling**, only during admin-triggered sessions.

- Snapshot `$wp_filter` after each active plugin loads (mu-plugin or very-early hook) to learn which hooks each plugin attached.
- Record a small set of representative requests (frontend page, cart AJAX, REST cart, admin page, cron) and capture which hooks fired per plugin per request type.
- Per-plugin report: "Attaches to N hooks total. On `wp_ajax_woocommerce_update_order_review`, fires K hooks; none touch cart-related globals — likely safe to exclude here."
- Pair with cheap wp.org metadata (last updated, active installs, support thread volume) as a quality smell column.
- Zero overhead outside an active profiling session. Admin-only UI.

Static analysis remains a possible *enhancement* on top of this, never the foundation.

Ships only after F is live, since F is what the output feeds.
