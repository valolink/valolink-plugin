# Valolink Plugin

Internal WordPress plugin for Valolink-managed client sites. A modular toolkit where every module is independently toggleable — disabled modules leave zero footprint (no hooks, no assets, no scheduled work, no autoloaded options).

Not a public product. Not on wordpress.org.

---

## What it does (Phase 1)

| Module | Purpose |
|--------|---------|
| **Core** | Registry, loader, context detection, settings, uninstall coordinator, admin settings page, self-updater, env compatibility gate |
| **B — Staging Detection** | Detects staging/local environments; blocks indexing, intercepts mail, disables live payment gateways; shows admin notice |
| **C — Inventory Push** | Pushes WP core/theme/plugin/PHP inventory to the Valolink Nuxt backend via HMAC-signed POST; runs on cron + update events + on demand |
| **E — Agency Branding** | Custom login logo and support contact info on the WP login screen |

Roadmap modules (Phases 2–3) are documented in `ROADMAP.md`.

---

## Requirements

- PHP ≥ 8.2
- WordPress ≥ 6.5

---

## Development setup

The repo includes a Nix flake. With [Nix flakes](https://nixos.wiki/wiki/Flakes) and [direnv](https://direnv.net/) installed:

```sh
direnv allow
```

This drops you into a shell with the right PHP version (8.3), Composer, and WP-CLI on `$PATH`. No global installs needed.

Without direnv:

```sh
nix develop
```

### Syncing to a dev WordPress install

`bin/sync-dev.sh` watches the repo for changes and rsyncs them into a remote WordPress installation over SSH. It does an initial full sync on startup, then re-syncs on every file change.

**First-time setup:**

```sh
cp .env.example .env
# edit .env with your server details
```

`.env` variables:

| Variable | Required | Description |
|----------|----------|-------------|
| `DEV_SSH_HOST` | yes | `user@hostname` or just `hostname` if configured in `~/.ssh/config` |
| `DEV_SSH_PORT` | no | SSH port (defaults to 22) |
| `DEV_SSH_KEY` | no | Path to identity file (e.g. `~/.ssh/id_ed25519`) |
| `DEV_WP_PATH` | yes | Absolute path to the WordPress root on the server (e.g. `/var/www/html`). The plugin is synced to `$DEV_WP_PATH/wp-content/plugins/valolink-plugin` automatically. |
| `DEV_LINUX_USER` | no | Linux user (and group) that should own the synced files — useful when SSHing as root but files should be owned by e.g. `www-data` |
| `DEV_RSYNC_DELETE` | no | Set to `true` to mirror local deletions to the server (`rsync --delete`). Off by default. |

**Run the watcher:**

```sh
bin/sync-dev.sh
```

The script excludes `.git/`, `.direnv/`, `.env`, `vendor/`, `node_modules/`, and `bin/` from the sync. It uses `rsync --delete` so deletions are mirrored to the server.

---

## Project layout

```
valolink-plugin.php      # Plugin entry point, constants, boot sequence
uninstall.php            # Runs on plugin deletion; delegates to each module
src/
  Autoloader.php         # PSR-4-style autoloader (no Composer dependency)
  Module.php             # Module interface
  Context.php            # Request context descriptor passed to should_load()
  Loader.php             # Reads settings, instantiates + registers enabled modules
  Registry.php           # Module manifest; single source of truth for known modules
  ModuleManifest.php
  Settings.php           # Read/write wrapper for the valolink_settings option
  Plugin.php             # Activation / deactivation hooks, boot()
  EnvCheck.php           # PHP + WP version gate
  Admin/
    SettingsPage.php     # Admin UI: one screen, per-module toggles
  Modules/
    Branding/            # Module E
    …
```

---

## Architecture notes

**Module contract** — every module implements four methods:

```php
id(): string                        // stable key, used in settings storage
should_load(Context $ctx): bool     // cheap, no side effects
register(): void                    // wire hooks; only called when should_load() is true
uninstall(): void                   // clean up all persistent data
```

**Settings** are stored in a single non-autoloaded option (`valolink_settings`), a nested array keyed by module id. Modules use `Settings` helpers; no module reads another module's settings directly.

**Hook/option prefix:** `valolink_` everywhere — hook names, option keys, CPT slugs, REST namespaces, transient keys, cron hook names.

**Module C transport:** one-way HMAC-signed POST from WP to the Nuxt backend. Per-site secret generated at activation. Signed payload includes `{site_url, timestamp, body_hash}`; Nuxt rejects timestamps outside ±5 min.

**Updates:** self-hosted manifest (served from the Nuxt app) hooked via `pre_set_site_transient_update_plugins` and `plugins_api`. Downloaded zip is signature-verified before install.

---

## Testing

PHPUnit + WP test suite for the core loader and non-trivial module logic. Manual smoke test on a real WP install before calling a module done.

```sh
composer install          # install PHPUnit and WP test stubs
composer test             # run the suite
```

(Test scaffolding not yet wired up — this is the next step after the initial module implementations.)

---

## Deployment

Install as a normal WordPress plugin (upload zip or drop folder into `wp-content/plugins/`). Updates come through the standard WP Updates screen once the self-hosted manifest is configured.
