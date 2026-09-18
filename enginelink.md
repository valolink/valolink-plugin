# EngineLink Companion — Plugin API Specification

## Context

You are building a WordPress plugin that acts as a data source for EngineLink, a web agency management platform. EngineLink manages client WordPress sites and needs to collect information about them.

**Communication model:** EngineLink pulls data from the plugin on demand. The plugin does not push anything — it simply responds to requests.

**Authentication:** Every request from EngineLink carries an `Authorization: Bearer <key>` header. The key is a secret shared between EngineLink and the plugin, configured once by the admin. Return `401` for any request with a missing or incorrect key.

---

## Endpoints

### GET /wp-json/enginelink/v1/ping

Lightweight check used to verify the plugin is reachable and the key is valid.

```json
{
  "ok": true,
  "plugin_version": "1.0.0"
}
```

---

### GET /wp-json/enginelink/v1/status

Full snapshot of the site's current state. This is the main endpoint EngineLink calls on each sync.

```json
{
  "plugin_version": "1.0.0",
  "collected_at": "2025-06-07T10:00:00Z",
  "wordpress": {
    "version": "6.7.1",
    "language": "fi",
    "timezone": "Europe/Helsinki",
    "multisite": false,
    "debug_mode": false
  },
  "php": {
    "version": "8.2.15",
    "memory_limit": "256M",
    "max_execution_time": 30
  },
  "themes": {
    "active": {
      "name": "Astra",
      "slug": "astra",
      "version": "4.6.2",
      "author": "Brainstorm Force"
    }
  },
  "plugins": [
    {
      "name": "WooCommerce",
      "slug": "woocommerce/woocommerce.php",
      "version": "8.4.0",
      "active": true,
      "update_available": true,
      "new_version": "8.5.0",
      "author": "Automattic"
    }
  ],
  "updates": {
    "wordpress_update_available": false,
    "new_wordpress_version": null,
    "plugin_updates_count": 2,
    "theme_updates_count": 0
  },
  "users": {
    "admin_count": 2,
    "total_count": 847
  },
  "database": {
    "size_mb": 42.3
  },
  "health": {
    "loopback_ok": true,
    "scheduled_events_ok": true,
    "https_ok": true
  },
  "staging": {
    "module_enabled": true,
    "loader_version": "2.0",
    "declared": true,
    "production_host": "example.fi",
    "home_host": "staging2.example.fi",
    "active": true,
    "reason": "mismatch"
  }
}
```

`staging` reports the Staging module's declared-production-host model: `reason` is one of
`forced`, `undeclared`, `mismatch`, `match`, or `module_off`. A production site with
`declared: false` has no staging safeties on any of its clones; a clone with `active: false`
is unprotected. `production_host` is the plaintext copy and may have been rewritten by a
search-replace on a clone — the hash decides, this field is for display. `loader_version` is
the header version of the installed `mu-plugins/valolink-staging-loader.php` (null when
missing); anything below 2.0 is stale and refreshes on the site's next wp-admin visit.

Use `null` for any field that cannot be determined rather than omitting it. EngineLink handles nulls.

Do not include credentials, secret keys, or file system paths anywhere in the response.

---

### GET /wp-json/enginelink/v1/logs

Provided by the Logging module. Returns rows from the plugin's custom log table, newest first. EngineLink proxies this behind `GET /api/websites/[id]/logs` for the website detail page's Logs tab.

Query args: `event` (string), `user_login` (string), `level` (string), `before` (integer id cursor — returns rows with `id < before`), `limit` (integer, max 500, default 100).

```json
{
  "logs": [
    {
      "id": 123,
      "created_at": "2026-07-01 09:12:44",
      "event": "user_login",
      "level": "info",
      "user_id": 1,
      "user_login": "admin",
      "ip": "192.0.2.10",
      "message": "User logged in",
      "context": { "any": "json" }
    }
  ],
  "next_cursor": 42
}
```

`next_cursor` is the last returned id when a full page was served, `null` when there are no more rows.

### GET /wp-json/enginelink/v1/log-events

Distinct event names with counts, for building filter dropdowns:

```json
[
  { "event": "auth.login", "count": 231 },
  { "event": "plugin.activated", "count": 4 }
]
```

#### Event names

The set grows, so treat an unknown name as opaque.

- **Posts** — `post.created`, `post.updated`, `post.published`, `post.unpublished`, `post.trashed`, `post.restored`, `post.deleted`. One row per save of any content type — posts, pages, products, media, templates, custom post types. `context.changed` names the post columns that changed (`title`, `content`, `excerpt`, `status`, `slug`, `date`, `author`, `parent`, `order`, `password`, `comments`); it is empty for a save that changed only terms or meta. `content_chars` gives the length before and after when the content changed, and `title_before` the old title. `via` is `cron` or `cli` for a change no signed-in user made.
- **Menus** — `menu.created`, `menu.updated`, `menu.deleted`, once per menu per request rather than once per item.
- **Not logged:** saves with nobody signed in outside cron and WP-CLI (a visitor's form stored as a post, Accesslink proposals — which have their own `accesslink_*` rows), WordPress's internal types, menu items, product variations and WooCommerce's order records. The block editor's second save of a post for its meta boxes is folded into the first.
- **Everything else** — `auth.login`, `auth.login_failed`, `auth.logout`; `plugin.installed`, `.updated`, `.activated`, `.deactivated`, `.deleted`; `theme.installed`, `.updated`, `.switched`; `core.updated`; `user.created`, `user.deleted`; `mail.sent`, `mail.failed`; and `accesslink_proposed`, `_applied`, `_rejected`, `_stale`, `_failed`.
