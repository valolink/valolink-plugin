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

`stale` is the interesting one. An update proposal records a hash of the fields it intends to touch plus the post's modification time. That hash is recomputed at approval; if it disagrees, somebody edited the post in the meantime and the proposal is answering a stale question. The change is parked rather than applied, and nothing is overwritten. Re-propose against the current version.

## Create vs update

They behave differently on purpose.

- **create** — the post is written immediately as a `draft`. A draft is not publicly reachable, so nothing is exposed, but the operator can preview it in the real theme and open it in the real editor. Approving is then just a status flip; the payload is not re-applied later. Rejecting moves the draft to trash (recoverable).
- **update** — the live post is never touched until approval. The proposed values sit in the queue row and are applied with `wp_update_post` on approval.

Note that create therefore *does* write to the database before any human sees it. That write is inert and invisible to visitors, but it is a write — which is why the module ships disabled, needs its own key, and has a kill switch.

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

Budget: base is ~3.5 kB; site instructions cap at 4000 characters and the notes section at 3000, so the worst case is roughly 10 kB (~2.6k tokens).

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

### POST /changes

Files a proposal. Returns `201` with the created row.

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

Be aware that kses strips HTML comments, and block markup *is* HTML comments — so filtered content loses its block structure and degrades to classic HTML. That is the safe direction to fail in, but it means a non-admin approving a block-editor post gets a worse result than an admin approving the same post.

---

## Not built yet

Named here so nobody assumes otherwise:

- **WooCommerce products.** Products are a CPT, but price/stock/SKU live in postmeta *and* in Woo's `wp_wc_product_meta_lookup` table. Writing them through `wp_update_post` desynchronises the two. A `ProductApplier` going through `wc_get_product()` setters and `save()` slots in beside `PostApplier`; the queue, auth, staleness and review UI all work unchanged.
- **Block-aware editing.** The big one. On a GeneratePress/GenerateBlocks site nearly all content is blocks, with copy nested several levels inside wrappers, and an agent that regenerates whole `post_content` will corrupt block attribute JSON. The right primitive is addressing individual blocks. Related and urgent: `wp_kses_post()` strips HTML comments, and block markup *is* HTML comments, so a non-administrator approving a block-based post currently flattens it.
- **Custom fields / ACF.** Common on agency sites, entirely absent here.
- **Reading back a proposal's payload.** `GET /changes/{id}` returns metadata, not the proposed content, so an agent cannot inspect what a *different* agent queued — only that something is queued.
- **Per-key scoping.** One key per site; the only granularity is the site-wide `allowed_post_types` list.
- **Remote approval from EngineLink.** The service layer is already the single implementation behind both entry points, so aggregating queues across sites is additive — but it needs an approver credential that is not the propose key.
- **Per-key scoping.** One key per site, all-or-nothing across the allowed post types.
