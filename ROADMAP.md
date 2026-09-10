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

Spec and current wire shapes: `accesslink.md`. Open work is ordered by value ×
cheapness × fit with what already exists, not by ambition. Everything below
assumes the review gate is honest, so gate defects go first. Shipped work stays
at the end as history, because the *why* of a decision is what stops it being
re-litigated.

Test site: `staging.valolink.fi` (Polylang 3.8.7, GenerateBlocks, Rank Math,
Redirection; menus and `gp_elements` allowed). Deployed as a plain file copy, no
git checkout — from the plugin checkout:

```
rsync -vahP --exclude=.git ./ valolink-web:/home/valolink-web/web/staging.valolink.fi/public_html/wp-content/plugins/valolink-plugin/
```

The live `valolink.fi` sits under the same user. Scripted wp-admin access goes
through the Security module's login token (`vl_lt` hidden field, submit no sooner
than a second after the form), and fail2ban bans after ten `wp-login.php` POSTs in
an hour. Safe fixtures: post 4316, page 4064, draft page 742.

#### The review gate

Nothing open. Both items that were here closed on 2026-09-10, before the
WooCommerce work widened the write surface:

- [x] **A non-admin reviewer degraded content on approval.** Filtering ran at
  apply time by the reviewer's capability, so the queue could show one thing and
  an Editor's approval apply another — and for block edits the *whole rebuilt
  document* went through the filter, stripping every SVG icon on the page for a
  one-word change. Now what an agent authored (post body, block fragment,
  inserted markup) is sanitised once at propose time, and the document is applied
  raw; the site's own markup is never filtered. Verified on staging with a
  hostile `update` and a hostile `insert_block`: `<script>`, `onclick`,
  `onmouseover` and a `javascript:` href gone from the diff and from the
  applied post, the inline SVG kept, and the page's existing SVG count unchanged.
- [x] **Restrict the Valolink admin screens to administrators** — closed by
  audit, no code change. Every module page is registered with `manage_options`
  (the settings page and the log viewer through their `CAPABILITY` constants),
  every `admin_post_` handler checks a capability before its nonce, and the
  updater's check action uses `update_plugins`. The Accesslink screen is the
  deliberate exception: registered for `publish_posts` because reviewers need
  it, with the propose key, the writes toggle and the settings form rendered and
  handled only for `manage_options`.

#### Open — Tier 1: cheap, and the existing machinery already fits

- [ ] **Grouped review.** Thirteen identical metadata proposals were thirteen
  approvals and thirteen reloads. An optional `batch` label on proposals,
  grouped in the queue under one *Approve all* button, each still applied and
  stale-checked on its own with a result per row. Not atomicity — that is Tier 2.
  The attachment alt sweep below is unusable without this.
- [ ] **Revert an applied change.** `wp_update_post` already wrote a revision at
  apply time, so a Revert button on applied rows is nearly free. Moves how risky
  approving *feels* more than anything else on this list, and matters more once
  prices and stock are proposable.
- [ ] **Attachment alt text / title / caption.** New `entity_type` (the column
  exists and defaults to `post`), small applier, trivial diff, near-zero blast
  radius. Unlocks an accessibility + SEO sweep across a whole media library:
  high-volume, billable, miserable by hand. Necessary but not sufficient on
  GenerateBlocks sites: the alt Google sees on jet-steel's front page comes from
  the block markup, not the attachment, so a safe `set_image_alt` at a block
  path belongs beside this applier — or the sweep changes the library and not
  the pages.
- [ ] **Content audit reads.** `GET /audit/content`: missing meta descriptions,
  images without alt text, thin content, orphan pages, broken internal links. No
  new write surface — only reads that already exist. Aims at the top friction in
  the root `CLAUDE.md` (employees inventing work when the queue is empty) by
  turning Accesslink into a source of concrete claimable work. The jet-steel
  audit was most of that session, and every part of it was a read.
- [ ] **`post_name` and `post_date`.** An agent cannot rename or schedule
  anything. Two entries in `PostApplier::POST_FIELDS`. Ship the rename together
  with redirects — a silent rename is worse than no rename. (`slug` on `create`
  is the redirect-free case and already ships.)
- [ ] **`wp_block` (synced patterns).** Just a post type — but editing one changes
  every page using it, so the queue has to show "used on N pages" or approval is
  blind.
- [ ] **Media "used on".** `attached_to` ships; which posts actually reference an
  image needs a content scan and is the second slice.

#### Open — Tier 2: real work, real payoff

- [ ] **Polylang, the rest.** `sync_translation` (mirror a source's structural
  change into an existing translation), term translations
  (`pll_save_term_translations`, without which a translated post cannot be
  categorised), and the status matrix as a standalone endpoint. Design below.
- [ ] **ACF / custom fields.** CPT UI is on much of the fleet, so custom post types
  exist that are only half-readable now. Needs discovery first — `GET /field-groups`
  and `GET /content/{id}/fields` — because an agent cannot guess field keys. v1 is
  scalars only (text, textarea, wysiwyg, number, url, image id, select, true/false);
  repeaters and flexible content are where the cost lives, and they wait.
- [ ] **Media upload by URL, sideloaded at approval.** Without it every created page
  is imageless. Fetch happens only on approve, behind a host allowlist with MIME
  and size limits. Worth more than its place here suggests: a service page
  drafted without images gets judged on that before anyone reads it, which is
  what the jet-steel Palvelut draft faced.
- [ ] **Redirects.** Adapter for the Redirection plugin, degrading to unavailable.
  Pairs with the rename above.
- [ ] **Atomic batches.** A `batch_id` with all-or-nothing apply. Today every
  change stands alone, so a translation plus its menu entry plus its redirect can
  be approved piecemeal and leave the site half-done. Architectural, and the unlock
  for every multi-entity item here. Second slice after grouped review.

#### Open — Tier 3: deferred, with the reason

- [ ] **WooCommerce products.** A `ProductApplier` beside `PostApplier` going through
  `wc_get_product()` setters and `save()` — price/stock/SKU live in postmeta *and*
  Woo's `wp_wc_product_meta_lookup`, so `wp_update_post` desynchronises them. Queue,
  auth, staleness gate and review UI need no changes. Build it when a Woo client asks.
- [ ] **Contact Form 7.** Read-only value only — knowing what a form collects is
  useful; rewriting a form template risks lead capture.
- [ ] **Remote approval from EngineLink.** `ChangeService` is already the single
  implementation behind both the REST routes and the admin screen, so aggregating
  every site's queue into one Nuxt page is additive — but it needs an approver
  credential distinct from the propose key, since the whole design rests on those
  not being the same secret. Closer than it looks: reviewing a fleet every month
  through one wp-admin at a time will not scale.
- [ ] **Per-key scoping** (which post types, which categories) and **reading back a
  proposal's payload**. Both are prerequisites for multiple agents or an EngineLink
  reviewer; neither matters while one operator reviews in wp-admin.
- [ ] **Menus: creating one, setting its language, assigning a theme location**
  (including Polylang's per-language location map). One-time structural decisions;
  repointing items is the repetitive part and ships.

Also outside Accesslink but found through it: EngineLink should expose a read-only
site brief (keyword targets, Search Console rows) on its existing bot zone, so the
agent doing the site work has the demand data without a PDF export.

**Not worth doing:** theme or plugin file edits, options and customizer, user
management, plugin install/activate (that is `hestiascripts`' job), comment
moderation.

#### Shipped, and why

Kept short; the spec carries the current behaviour.

- **Structural changes were approved blind (Tier 0).** `update_text`,
  `insert_block`, `delete_block` and `move_block` rendered an empty diff and a
  preview of the *unchanged* page, because the diff and the preview filter both
  looped over `payload['fields']`, which those actions do not carry. All three
  call sites now resolve through `ChangeService::proposed_content()`; a preview
  that cannot be built fails loudly.
- **The translation slice.** `GET /languages`, `/content/{id}/translations`,
  `create_translation` as a structural clone with translated leaves (markup
  skeleton compared, so only words can change), `set_language` for content
  predating the plugin, `sync_translation_meta` copying only what a translation
  is *missing* (never overwriting — the obvious thing to overwrite is the
  corrected target-language SEO title), menus as a whole-tree proposal behind a
  toggle with a `label → target` tree diff, `CacheCleaner` after non-post
  applies, and rejection unlinking a translation from its group so a retry is
  not refused as `already_translated`. Deleting was closed without a delete verb:
  trash empties itself. `action` column widened to `varchar(32)` after
  `sync_translation_meta` failed to insert and answered `201` with nulls; an
  empty queue write is now an error.
- **Core kses undid ContentSanitizer.** A propose-time request has no user, so
  `wp_filter_post_kses` stripped every inline SVG *after* the sanitizer had kept
  it. All save paths go through `PostApplier::without_kses()`. Empty JSON objects
  in block delimiters were also being rewritten as arrays on every edit;
  `BlockReader::preserve_delimiters()` restores the originals when the
  delimiter count is unchanged.
- **The jet-steel.fi SEO run (2026-09-09, releases 0.2.1–0.2.3).** First real
  client run: a full audit and 17 proposals in one sitting. The loop held —
  idempotency keys, `/validate`, `pending_changes` and notes all did their jobs —
  and the friction around it became: queue oldest first with the block outline
  folded; SEO adapters owning their template-variable syntax with the guide
  printing the site's title template and the queue rendering SEO fields with a
  length (the guide had hardcoded Rank Math's `%sep%` on a Yoast site);
  `GET /content?fields=seo,terms,media,layout`; update staleness on field values
  only, not `post_modified` (two disjoint proposals on one post can both apply);
  `slug` on create; `insert_block` at document start or end without a path; term
  ids on `/taxonomies`; `attached_to` on `/media`; the translation `outdated`
  flag anchored to the group's source; Element type, hook and conditions plus
  the three GeneratePress layout keys as proposable fields (a created Element
  used to arrive inert, a created page opened in the theme's default layout);
  allowed post types as checkboxes; and password-protected posts readable and
  listable, since the password gates the public front end only.

#### Polylang translations — design

The hard part exists: a translation of a GenerateBlocks page is a structural
clone with translated leaf text, and `BlockReader` flattens a document to
addressable paths with `editable` leaves. The reads, `create_translation`,
`set_language` and `sync_translation_meta` ship. Still open:

- `GET /translations/status?post_type=page` — the matrix. "17 pages, 12 in en, 3
  outdated, 2 missing." Nothing in WordPress gives you this cheaply and it is a
  report worth putting in front of a customer.
- `sync_translation` — source changed, so mirror the structural change into the
  translation and leave translated leaves alone except where the matching source
  leaf moved. This is the one that earns its keep; manual translation drift is
  exactly what nobody catches.
- Term translations (`pll_save_term_translations`) — without them a translated
  post cannot be categorised.

Things to settle when building those:
1. **Staleness needs a new marker.** Polylang does not track "translated at source
   version". Accesslink would write its own postmeta (`_accesslink_source_hash`) at
   apply time — a new persistent footprint, so `uninstall()` has to clear it. The
   current `outdated` flag is a modification-time comparison against the source
   and flags "worth re-reading", not "definitely wrong".
2. **The reviewer usually cannot judge the target language.** Approving an English
   page means "structurally sound and plausible", not "correct English". The queue
   states that limit rather than implying it away.
3. **Verify against the installed version before building.** The `pll_*` helpers
   and the `post_translations` hidden-taxonomy internals are not a public
   contract, and free and Pro differ.

Out of scope for translations, so nobody expects a fully translated site from
them: theme/plugin string translations (Loco) and, still, menu creation.

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
