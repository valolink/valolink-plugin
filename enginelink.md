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
    "max_execution_time": 30,
    "build_date": "2026-10-21T22:10:32Z",
    "build_date_source": "phpinfo",
    "sapi": "litespeed"
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

`php.build_date` is when the running PHP was built (since 0.2.13): phpinfo()'s "Build Date"
(`build_date_source: "phpinfo"`, the build machine's clock, read as UTC), or the binary's
modification time where phpinfo() is disabled (`"binary_mtime"`), or null. Hosts that backport
security fixes keep the old version number — CloudLinux's alt-php 8.4.26 rebuilt on
2026-10-21 with the fixes for CVE-2026-109618/109619 still reports 8.4.26 — so EngineLink
treats a build made after an advisory was published as carrying its fix. `sapi` is `PHP_SAPI`
(`fpm-fcgi`, `litespeed`, `apache2handler`, …).

Use `null` for any field that cannot be determined rather than omitting it. EngineLink handles nulls.

Do not include credentials, secret keys, or file system paths anywhere in the response.

---

### POST /wp-json/enginelink/v1/self-update

Since 0.2.13. Updates this plugin, and only this plugin, to its latest GitHub release — the
package the Updater offers in wp-admin — so EngineLink can update sites it has no other way onto
(sites on other hosts). A leaked key can at most trigger an update to an official release.

```json
{ "updated": true, "from": "0.2.13", "to": "0.2.14" }
{ "updated": false, "from": "0.2.14", "to": "0.2.14", "reason": "up_to_date" }
```

Refused with 409 when `DISALLOW_FILE_MODS` is set (staging copies, locked-down sites) or when
WordPress cannot write its files directly (it would ask for FTP credentials); 500 with WordPress's
own messages when the upgrade fails. WordPress's upgrader deactivates an active plugin outside cron
and leaves reactivation to the browser; the endpoint puts it back in the active list (network-wide
on a multisite when it was) without loading it twice, then refreshes the staging mu-loader.
Tested 2026-10-22 on pohja.demolink.fi: 0.2.11 → 0.2.12 in 2 s, active afterwards, front page 200.

### GET /wp-json/enginelink/v1/pages

The site's published content, for EngineLink's content workflow
(EngineLink repo, `docs/projects/content-workflow.md` §5). EngineLink uses the
list to know which pages (and posts, products, other public post types) exist
and then reads each one's public URL as a visitor sees it; the text never
comes from here. `post_content` is left out on purpose: what the theme, page
builders and ACF render is not in it. `words` is a count from it, a size hint
so the operator can tell an article from a stub when choosing what to read.

`?types=page,post,product` names the public post types to list (unknown and
non-public ones are ignored; attachments never listed); without it, pages
only, as before 0.2.12. Published items only, passworded ones excluded, every
language (Polylang). Pages in menu order then title, other types newest first,
at most 500 per type (`truncated_types` names the types that had more).
`types` lists every public post type with its published count, whatever was
asked, so EngineLink can offer them.

```json
{
  "plugin_version": "0.2.12",
  "total": 2,
  "truncated": false,
  "truncated_types": [],
  "types": [
    { "name": "post", "label": "Artikkelit", "count": 230 },
    { "name": "page", "label": "Sivut", "count": 42 }
  ],
  "pages": [
    { "id": 12, "type": "page", "url": "https://example.fi/", "title": "Etusivu", "parent": 0, "menu_order": 0, "date": "2019-03-01T10:00:00+00:00", "modified": "2026-09-20T08:14:03+00:00", "lang": "fi", "front": true, "categories": [], "words": 840 },
    { "id": 301, "type": "post", "url": "https://example.fi/blogi/kattoremontin-hinta/", "title": "Mitä kattoremontti maksaa?", "parent": 0, "menu_order": 0, "date": "2026-05-02T09:00:00+00:00", "modified": "2026-05-02T09:00:00+00:00", "lang": "fi", "front": false, "categories": ["Blogi"], "words": 1200 }
  ]
}
```

`lang` is the Polylang slug, or `null` without Polylang. `date` and `modified`
are GMT. `categories` are the names in the type's public hierarchical
taxonomies (post categories, product categories). Sites on a plugin without
this route answer 404, and EngineLink falls back to `wp/v2`, the sitemap and
the front page's links; a plugin before 0.2.12 answers pages only, without
`types`, and EngineLink lists other types through `wp/v2` where it can.

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
