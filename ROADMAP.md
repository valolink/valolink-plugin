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
- [ ] **Menus, GeneratePress Elements and per-language site chrome.** Translating
  the front page produced a correct English page with no header: the site header
  is a GP Element whose display conditions do not match the new page, and the
  English menu is a separate Polylang menu that is effectively empty. A
  translated *page* is not a translated *site*, and the gap is visible the moment
  anyone looks at the result.

#### Tier 1 — cheap, and the existing machinery already fits

- [ ] **`post_name` and `post_date`.** An agent cannot currently rename or schedule
  anything. Two entries in `PostApplier::POST_FIELDS`. Ship slug together with
  redirects — a silent rename is worse than no rename.
- [ ] **Attachment alt text / title / caption.** New `entity_type` (the column
  exists and defaults to `post`), small applier, trivial diff, near-zero blast
  radius. Unlocks an accessibility + SEO sweep across a whole media library:
  high-volume, billable, and miserable by hand.
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
- [ ] **Content audit reads.** `GET /audit/content`: missing meta descriptions,
  images without alt text, thin content, orphan pages, broken internal links. No
  new write surface — only reads that already exist. Aims at the top friction in
  the root `CLAUDE.md` (employees inventing work when the queue is empty) by
  turning Accesslink into a source of concrete claimable work rather than only a
  way to apply edits.
- [ ] **ACF / custom fields.** CPT UI is on much of the fleet, so custom post types
  exist that are only half-readable now. Needs discovery first — `GET /field-groups`
  and `GET /content/{id}/fields` — because an agent cannot guess field keys. v1 is
  scalars only (text, textarea, wysiwyg, number, url, image id, select, true/false);
  repeaters and flexible content are where the cost lives, and they wait.
- [ ] **Media upload by URL, sideloaded at approval.** Without it every created page
  is imageless. Fetch happens only on approve, behind a host allowlist with MIME
  and size limits.
- [ ] **Redirects.** Adapter for the Redirection plugin, degrading to unavailable.
  Pairs with slug changes, which needs the next item.
- [ ] **Batched proposals.** A `batch_id` with all-or-nothing apply. Today every
  change stands alone, so a translation plus its menu entry plus its redirect can
  be approved piecemeal and leave the site half-done. Architectural, and the unlock
  for every multi-entity item here.

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
  not being the same secret.
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
