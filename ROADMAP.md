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
**Objective:** widen the agent change queue past posts and pages.

The module shipped with a `PostApplier` covering `post_title` / `post_content` / `post_excerpt`. Deferred, in rough order of usefulness:

- **WooCommerce products.** A `ProductApplier` beside `PostApplier`, going through `wc_get_product()` setters and `save()` — price/stock/SKU live in postmeta *and* Woo's `wp_wc_product_meta_lookup`, so `wp_update_post` desynchronises them. Queue, auth, staleness gate and review UI need no changes.
- **Taxonomy terms, featured image, custom fields** on the existing post applier.
- **Remote approval from EngineLink.** `ChangeService` is already the single implementation behind both the REST routes and the admin screen, so aggregating every site's queue into one Nuxt page is additive — but it needs an approver credential distinct from the propose key, since the whole design rests on those not being the same secret.
- **Per-key scoping** (which post types, which categories) rather than one all-or-nothing site key.

Spec and current wire shapes: `accesslink.md`.

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
