# CLAUDE.md — Valolink Plugin

## 1. Project Context

Internal WordPress plugin for the Valolink agency's own client sites. **Not** a product for public distribution. The plugin is a "many-in-one" toolkit: many modules, each toggleable, with strict zero-impact-when-off discipline so the bundle is cheaper to operate than maintaining many mini-plugins per site.

One downstream consumer matters: a Nuxt app (the agency's business backend) that receives site inventory data from each WP install. Module C is what feeds it.

See `ROADMAP.md` for modules outside the current phase.

## 2. Core Principles

- **Zero-Impact Deactivation.** Toggled-off modules must have no footprint: no hooks, no asset loading, no scheduled work, no autoloaded options.
- **Context-Aware Loading.** Modules expose a cheap `should_load()` check; the loader calls it before any module-side code runs. AJAX/REST/cron/admin/frontend contexts are first-class — modules irrelevant to the current request do not load.
- **Graceful Failure.** Guard before acting. The plugin must never WSOD a client site. Incompatible environment → admin notice, not a fatal.
- **Environment Compatibility.** Check PHP and WP version at bootstrap. If unmet, refuse to load modules and show a notice.
- **Strict Security.** Sanitize inputs at the boundary, escape outputs late, capability-check every privileged action, nonce every state-changing request.
- **Database Hygiene & Clean Uninstall.** No autoloaded bloat. `uninstall.php` removes every option, table, CPT entry, and cron event the plugin ever created. Each module owns its own `uninstall()` so the core uninstaller just iterates.
- **Asset Discipline.** CSS/JS enqueue only on the exact screens the active module needs.
- **Caching Compatibility.** Work correctly behind WP Rocket, page cache, object cache. Never write to options or transients on uncached frontend requests if it can be avoided.
- **Conflict Prevention.** Everything namespaced/prefixed (see §3).
- **Native Update Integration.** Plugin updates surface in the standard WP Plugins list and Updates screen via a self-hosted manifest + custom updater.

## 3. Architecture Decisions

- **PHP namespace:** `Valolink\Plugin\…`. One top-level namespace, sub-namespaced per module (`Valolink\Plugin\Modules\Branding`).
- **Public prefix:** `valolink_` for hook names, option keys, CPT slugs, REST namespaces, transient keys, cron hook names. (Short, unambiguous, easy to grep.)
- **Settings storage:** Single non-autoloaded option `valolink_settings`, a nested array keyed by module id. Per-module accessor helpers; no module reads another module's settings directly.
- **Custom tables:** Avoid unless a module truly needs one. Module D (Logging) will need one; nothing else in Phase 1 does.
- **Module contract:** Each module is a class implementing:
  - `id(): string` — stable identifier, used as settings key.
  - `should_load(Context $ctx): bool` — cheap, no side effects. Receives a Context describing the current request (is_admin, is_ajax, ajax_action, is_rest, rest_route, is_cron, is_frontend, etc.).
  - `register(): void` — wire hooks. Only called if `should_load()` returned true.
  - `uninstall(): void` — remove this module's persistent footprint.
- **Module loader:** Reads `valolink_settings`, instantiates only enabled modules, calls `should_load(ctx)`, then `register()`. The loader itself has no per-module knowledge; modules self-describe via a registry.
- **Logging during Phase 1:** Modules log to `error_log()` until Module D ships. No direct DB writes for diagnostics.
- **EngineLink transport (implemented — replaces the earlier HMAC-push design):** pull-based REST. EngineLink calls `enginelink/v1` endpoints (`/ping`, `/status` from EngineLinkModule; `/logs`, `/log-events` from LoggingModule) with an `Authorization: Bearer <key>` header. The key is generated on the plugin's EngineLink settings page and pasted into EngineLink's website settings (`Website.pluginApiKey`); `EnginelinkAuth` validates it. The plugin never pushes. Full API spec: `enginelink.md`.
- **Accesslink transport (implemented):** its own REST namespace `accesslink/v1` and its own Bearer key, separate from EngineLink's. Agents may only *propose* content changes; applying requires the `publish_posts` capability, so the propose key can never approve its own work. Ships disabled, with an explicit writes toggle acting as kill switch. Full API spec: `accesslink.md`.
- **Update channel (implemented):** GitHub releases. `src/Updater.php` checks `api.github.com/repos/valolink/valolink-plugin/releases/latest` and hooks `pre_set_site_transient_update_plugins` + `plugins_api`; releases are cut with `release.sh` (version bump → tag → GitHub Actions builds the zip). EngineLink does not serve an update manifest. Server-side install/upgrade on HestiaCP boxes: `hestiascripts/v-wp-valolink-plugin-install`.

## 4. Phase 1 Scope

Only these ship in v1. Everything else in `ROADMAP.md`.

### Core Framework
- Module registry, loader, context detection, settings storage, uninstall coordinator, admin settings page (one screen, module toggles), self-updater, env compatibility gate.

### Module B — Staging Detection & Helpers
Detect staging/local environments reliably (multiple heuristics: hostname patterns, `WP_ENVIRONMENT_TYPE`, known host markers, IP ranges). On detection: block search indexing, intercept outgoing mail, disable live payment gateways (WooCommerce). Admin notice that staging mode is active.

### EngineLink module (shipped — pull, not push)
Read-only inventory served over REST: `/ping` + `/status` (WP core version, theme, plugins with update state, PHP env, users, DB size, health). EngineLink pulls on its own 6-hourly cron and on demand; Bearer-key auth as described in §3. The Logging module adds `/logs` + `/log-events` on the same namespace. Spec: `enginelink.md`.

### Module E — Agency Branding
Replace WP login logo with agency logo, inject agency support contact info beneath the login form. Must coexist with 2FA/security plugins on the login screen.

## 5. Working Notes for Claude

- **Do not run any git commands.** The user handles all git operations themselves.
- This file is loaded every turn — keep it short. Detailed per-module specs live in `ROADMAP.md`.
- When in doubt about scope, ask before adding. Do not pre-build infrastructure for roadmap modules.
- Don't introduce a dependency injection container, event bus, or other framework abstraction unless a Phase 1 module concretely needs it.
- Tests: PHPUnit + WP test suite for the core loader and any non-trivial module logic. Manual smoke test on a real WP install before declaring a module done.
- Current layout: `valolink-plugin.php` (bootstrap) + `src/` (Loader, Registry, Settings, Context, Updater, `Modules/{Accesslink,AssetVersion,Branding,Email,EngineLink,Logging,Scripts,Security,Staging,Toolbox}`) + `bin/` build tooling + `release.sh`. Shipped as v0.1.x via GitHub releases.
- **Asset Versioning module** stamps `?ver=<filemtime>` onto local script/style URLs via `script_loader_src` / `style_loader_src` at priority 100000 (last word on the URL). It exists because our nginx templates cache assets with `expires max`, which is only safe when the URL changes as the file does — and WordPress's default version is the *WordPress* version, which does not change when a plugin updates.
- **Never strip `?ver=` to hide a version.** SecurityModule's `hide_wp_version` used to do that and it froze every asset in visitors' browsers at an unchangeable URL — the energiatuote.fi cart outage on 2026-09-02. Removed 2026-09-02; the toggle now only touches the generator tag and feed generator. Replacing the value (Asset Versioning) achieves the same disclosure goal without disabling cache busting.
