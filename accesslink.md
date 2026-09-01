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
| `fields` | At least one of `post_title`, `post_content`, `post_excerpt`. Anything else is dropped. |
| `note` | Free text shown to the reviewer. Use it — a reviewer deciding on a diff alone has to reverse-engineer intent. |
| `idempotency_key` | Optional but recommended. A repeat returns the original row instead of filing a duplicate. |

Send `X-Accesslink-Agent: <name>` to identify the caller in the queue and the audit log.

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
- **Taxonomy, featured images, custom fields.** `PostApplier::ALLOWED_FIELDS` is three fields on purpose.
- **Remote approval from EngineLink.** The service layer is already the single implementation behind both entry points, so aggregating queues across sites is additive — but it needs an approver credential that is not the propose key.
- **Per-key scoping.** One key per site, all-or-nothing across the allowed post types.
