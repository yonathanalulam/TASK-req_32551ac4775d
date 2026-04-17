# Meridian API Specification

All endpoints are prefixed with `/api/v1`. Requests and responses are JSON
(`Content-Type: application/json`). Timestamps are UTC ISO-8601
(`YYYY-MM-DDTHH:mm:ssZ`).

Authentication is `Authorization: Bearer <token>` unless a route is marked public.

## Response envelope

Success:
```json
{
  "success": true,
  "data": { ... },
  "meta": { "request_id": "uuid", "timestamp_utc": "2026-04-17T12:00:00Z" },
  "error": null
}
```

Error:
```json
{
  "success": false,
  "data": null,
  "meta": { "request_id": "uuid", "timestamp_utc": "..." },
  "error": { "code": "VALIDATION_ERROR", "message": "...", "details": { ... } }
}
```

## Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `VALIDATION_ERROR` | 422 | Invalid request body / rule violation |
| `AUTHENTICATION_REQUIRED` | 401 | Missing / invalid / expired token |
| `NOT_AUTHORIZED` | 403 | Permission or object-scope gate failed |
| `USER_BLACKLISTED` | 403 | Authenticated user has an active `user` blacklist entry |
| `NOT_FOUND` | 404 | Resource missing |
| `CONFLICT` | 409 | Duplicate / state conflict |
| `ANALYTICS_DUPLICATE` | 409 | Repeat `idempotency_key` within window |
| `BLACKLISTED_SOURCE` | 409 | Ingest attempted against blacklisted source |
| `BLACKLISTED_CONTENT` | 409 | Analytics ingest on blacklisted content |
| `EFFECTIVE_OVERLAP` | 409 | Event version effective window overlap |
| `DRAFT_LOCK_CONFLICT` | 409 | Optimistic lock mismatch on draft update |
| `USERNAME_TAKEN` | 409 | Signup with duplicate username |
| `CASE_NOT_RESOLVED` | 409 | Appeal submitted on non-resolved case |
| `APPEAL_ACTIVE` | 409 | Second appeal submitted while one is active |
| `RATE_LIMITED` | 429 | Fixed-window rate limit exceeded |
| `INTERNAL_ERROR` | 500 | Unexpected failure |

## Pagination

List endpoints accept `page` and `page_size` (default 25, max 100; hard ceiling
500). Responses include `meta.total`, `meta.page`, `meta.page_size`.

## Middleware pipeline

Outer → inner: `ErrorResponseMiddleware → RequestIdMiddleware → AuthMiddleware →
RateLimitMiddleware → route`. Rate limiting therefore sees the resolved `user`
and derives per-user buckets (`u:<user_id>`). Unauthenticated callers use
`ip:<REMOTE_ADDR>:<route_path>`. System accounts (`users.is_system=1`) bypass
rate limiting entirely.

Public paths (no auth required): `/auth/signup`, `/auth/login`,
`/auth/security-questions`, `/auth/password-reset/begin`,
`/auth/password-reset/complete`, `/health`.

## Authorization model

Protected business routes apply two gates before serving a request:

1. **Capability** — the caller must hold the named permission
   (`content.view`, `events.publish`, etc.).
2. **Object scope** — `user_role_bindings.scope_type/scope_ref` must allow the
   targeted object. Scope types: `global` / null, `content`, `event_family`,
   `moderation_reviewer`, `report`. Administrators have global scope for every
   protected resource. Ownership shortcuts exist where the PRD allows (content
   creators on non-terminal records, event draft creators on their own drafts).

Deny-by-default applies to every mutating business route.

---

## Health

### `GET /health` _(public)_
Returns `200 { status: "ok" }` when the database is reachable. `503` when the DB
ping fails.

---

## Auth & Identity

### `GET /auth/security-questions` _(public)_
Returns the catalogue of active security prompts.
```json
{ "data": [{ "id": 1, "prompt": "..." }, ...] }
```

### `POST /auth/signup` _(public)_
```json
{
  "username": "newuser",
  "password": "AtLeast12CharsLong",
  "display_name": "New User",
  "security_answers": [
    { "question_id": 1, "answer": "first pet answer" },
    { "question_id": 2, "answer": "birth city answer" }
  ]
}
```
- Creates a `learner`-role account with `active` status.
- Persists ≥ 2 AES-256-GCM-encrypted security answers so password reset works.
- Issues a bearer token identical to `/auth/login`.
- Failure codes: `VALIDATION_ERROR` (422), `USERNAME_TAKEN` (409).

### `POST /auth/login` _(public)_
```json
{ "username": "admin", "password": "..." }
```
Returns `{ token, user: { id, username, display_name, status } }`.
Counts toward the per-username lockout window (5 failures / 15 min → 30 min lock).

### `POST /auth/logout`
Revokes the caller's current session.

### `POST /auth/password-reset/begin` _(public)_
```json
{ "username": "admin" }
```
Returns `{ reset_ticket, expires_at, questions: [{ id, prompt }] }`.
The raw `reset_ticket` is returned exactly once; DB stores `sha256(ticket_secret)`.
Tickets expire after 15 minutes; a new begin-reset call revokes any prior ticket
for the same user.

### `POST /auth/password-reset/complete` _(public)_
```json
{
  "username": "admin",
  "reset_ticket": "<value from begin>",
  "new_password": "NewPasswordThatIs12+",
  "answers": [
    { "question_id": 1, "answer": "..." },
    { "question_id": 3, "answer": "..." }
  ]
}
```
Ticket is validated (bound to user, not consumed, not revoked, not expired,
constant-time hash match) before the answers. Any failure returns 401
`AUTHENTICATION_REQUIRED`. Success atomically flips password, consumes ticket,
and revokes all active sessions for that user.

### `GET /auth/me`
Returns the authenticated user + effective permission keys.

---

## User Administration

Requires `auth.manage_users` unless otherwise noted.

| Method | Path | Notes |
|---|---|---|
| `POST` | `/admin/users` | Create a user |
| `GET` | `/admin/users` | `q`, `status` filters |
| `GET` | `/admin/users/{id}` | |
| `PATCH` | `/admin/users/{id}` | Update status / email / display_name |
| `POST` | `/admin/users/{id}/role-bindings` | Assign role; requires `auth.manage_roles` |
| `DELETE` | `/admin/users/{id}/role-bindings/{bindingId}` | Remove role |
| `POST` | `/admin/users/{id}/password-reset` | Requires `auth.reset_other_password` |
| `POST` | `/admin/users/{id}/security-answers` | Set security answers |
| `GET` | `/admin/security-questions` | List active questions |

Role assignment body:
```json
{ "role_key": "reviewer", "scope_type": "content", "scope_ref": "<content_id>" }
```

---

## Blacklists (`governance.manage_blacklists`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/blacklists` | Paginated listing |
| `POST` | `/blacklists` | `{ entry_type, target_key, reason? }` with `entry_type ∈ user/content/source` |
| `DELETE` | `/blacklists/{id}` | Revoke |

A new `user` entry takes effect on the very next request (`AuthMiddleware` denies
with 403 `USER_BLACKLISTED`).

---

## Audit (`governance.view_audit`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/audit/logs` | Filter by `action`, `object_type`, `object_id`, `actor_id` |
| `GET` | `/audit/chain` | Recent daily chain entries |
| `GET` | `/audit/chain/verify?day=YYYY-MM-DD` | Administrator only; walks + verifies the chain |

---

## Content

### `POST /content/parse` (`content.parse`)
```json
{
  "source": "acme_news",
  "source_record_id": "article-123",
  "kind": "html",
  "payload": "<html>...</html>",
  "title": "Optional override",
  "author": "Jane Doe",
  "media_source": "article",
  "section_tags": ["technology", "news"],
  "published_at": "2026-04-17T09:00:00Z",
  "duration_seconds": null,
  "language": "en"
}
```
- Idempotent on `(source, source_record_id)` — repeat submissions return the
  existing content with `duplicate: true`.
- `kind`: `html` | `plain_text`. HTML is denoised (scripts, styles, nav, forms,
  ads-class boilerplate removed) with `LIBXML_NONET`; plain text is passed
  through with whitespace collapse.
- 422 when body is under 200 chars post-denoise or language confidence falls
  below 0.75 (unless caller holds `content.language_override` and supplies
  `language`).

**Success response**:
```json
{
  "success": true,
  "data": {
    "duplicate": false,
    "content": {
      "content_id": "uuid",
      "title": "...",
      "body": "normalized body text",
      "language": "en",
      "author": "Jane Doe",
      "published_at": "2026-04-17T09:00:00Z",
      "media_source": "article",
      "section_tags": ["technology", "news"],
      "duration_seconds": null,
      "risk_state": "normalized",
      "body_length": 1234,
      "body_checksum": "sha256:...",
      "ingested_at": "2026-04-17T12:00:00Z",
      "version": 1
    },
    "media_refs": [
      {
        "media_type": "image",
        "local_path": null,
        "reference_hash": "sha256:...",
        "external_url": "https://...",
        "caption": null
      }
    ],
    "automated_moderation": {
      "case_id": "uuid|null",
      "flag_count": 0,
      "new_risk_state": "normalized"
    }
  }
}
```

Ingest runs every currently-published rule pack in the same DB transaction
(`keyword`, `regex`, `banned_domain`, `ad_link_density`). When any rule fires,
one `moderation_cases` row is opened; additional matches from other versions
attach to that same case. Max severity maps to risk state: `critical` →
`quarantined`, `warning` → `flagged`.

### Other content routes

| Method | Path | Permission |
|---|---|---|
| `GET` | `/content` | `content.view` (policy-filtered listing) |
| `GET` | `/content/{id}` | `content.view` |
| `PATCH` | `/content/{id}` | `content.edit_metadata` (or creator on non-terminal risk states) |

---

## Deduplication

| Method | Path | Permission |
|---|---|---|
| `GET` | `/dedup/candidates?status=pending_review` | `content.merge` or `moderation.review` |
| `POST` | `/dedup/merge` | `content.merge` |
| `POST` | `/dedup/unmerge` | administrator |
| `POST` | `/dedup/recompute` | administrator |

`POST /dedup/merge` body:
```json
{ "primary_content_id": "uuid", "secondary_content_id": "uuid", "reason": "optional note" }
```

Similarity is Jaro-Winkler over normalized titles. Pairs ≥ 0.92 with no author
conflict become `auto_mergeable`; the 0.85 … 0.92 band or any author conflict is
`pending_review`.

---

## Rule Packs

| Method | Path | Permission |
|---|---|---|
| `GET` | `/rule-packs` | authenticated |
| `POST` | `/rule-packs` | `admin.manage_rules` |
| `POST` | `/rule-packs/{id}/versions` | `rules.draft` |
| `POST` | `/rule-packs/versions/{versionId}/rules` | `rules.draft` |
| `POST` | `/rule-packs/versions/{versionId}/publish` | `rules.publish` |
| `POST` | `/rule-packs/versions/{versionId}/archive` | `rules.archive` |
| `GET` | `/rule-packs/versions/{versionId}` | authenticated |

Rule kinds: `keyword`, `regex`, `banned_domain`, `ad_link_density`.

Rule payload:
```json
{
  "rule_kind": "keyword",
  "pattern": "...",
  "threshold": null,
  "severity": "warning",
  "description": "optional"
}
```

`ad_link_density` uses `threshold` (links per 1 000 chars; default 3.0; trigger
is strictly greater than threshold).

---

## Moderation

| Method | Path | Permission |
|---|---|---|
| `GET` | `/moderation/cases` | `moderation.view_cases` |
| `GET` | `/moderation/cases/{id}` | `moderation.view_cases` |
| `POST` | `/moderation/cases` | `moderation.review` |
| `POST` | `/moderation/cases/{id}/assign` | `moderation.review` |
| `POST` | `/moderation/cases/{id}/transition` | `moderation.review` |
| `POST` | `/moderation/cases/{id}/decisions` | `moderation.decide` |
| `POST` | `/moderation/cases/{id}/notes` | `moderation.review` |
| `GET` | `/moderation/cases/{id}/notes` | authenticated; private notes require `moderation.view_private_notes` |
| `POST` | `/moderation/reports` | `moderation.report.create` + `content.view` on target (or content ownership, or administrator) |
| `POST` | `/moderation/cases/{id}/appeal` | `moderation.appeal.create` + case linkage |
| `POST` | `/moderation/cases/{id}/appeal/resolve` | `moderation.appeal_resolve` |

### Report submission

```json
{
  "content_id": "uuid|null",
  "reason_code": "ads|abuse|policy|other",
  "details": "optional free text"
}
```
Unknown `content_id` → 422. Missing capability or out-of-scope target → 403 with
a `moderation.report_denied` audit entry.

### Appeal submission

Allowed actors: administrator, the original reporter (`moderation_reports.reporter_user_id`),
the content creator (`contents.created_by_user_id`), or a user with a
`scope_type='content'` binding whose `scope_ref` matches the case's `content_id`.
Authorization precedes eligibility so unauthorized callers never learn case
state. Non-resolved cases → 409 `CASE_NOT_RESOLVED`; second active appeal → 409
`APPEAL_ACTIVE`.

---

## Events

| Method | Path | Permission |
|---|---|---|
| `POST` | `/events` | `events.draft` or administrator |
| `GET` | `/events` | authenticated |
| `GET` | `/events/{id}` | authenticated |
| `POST` | `/events/{id}/versions` | `events.draft` |
| `PATCH` | `/events/{id}/versions/{versionId}` | `events.draft` (accepts `expected_draft_version_number`) |
| `POST` | `/events/{id}/versions/{versionId}/publish` | administrator or `events.publish` |
| `POST` | `/events/{id}/versions/{versionId}/rollback` | administrator or `events.rollback` |
| `POST` | `/events/{id}/versions/{versionId}/cancel` | administrator or `events.cancel` |
| `POST` | `/events/{id}/versions/{versionId}/bindings` | `events.manage_bindings` |
| `GET` | `/events/{id}/versions/{versionId}` | authenticated |

Create event:
```json
{ "name": "Spring Invitational", "template_key": "individual", "event_family_key": "invitational" }
```

Draft update supports `rule_set` (`attempt_limit`, `checkin_open_minutes_before_start`,
`late_checkin_cutoff_minutes_after_start`), `advancement_rules`, `bindings`
(venue/equipment). Publish requires the draft not to conflict with any other
active publication's `effective_from`/`effective_to` window on the same event.

---

## Analytics

| Method | Path | Permission |
|---|---|---|
| `POST` | `/analytics/events` | `analytics.ingest` (idempotency key required) |
| `GET` | `/analytics/events` | `analytics.query` |
| `POST` | `/analytics/funnel` | `analytics.query` |
| `GET` | `/analytics/kpis?from=..&to=..` | `analytics.query` |

Ingest body:
```json
{
  "event_type": "content_view",
  "object_type": "content",
  "object_id": "uuid",
  "occurred_at": "2026-04-17T12:00:00Z",
  "session_id": "uuid",
  "dwell_seconds": 120,
  "language": "en",
  "media_source": "article",
  "section_tag": "news",
  "idempotency_key": "req-1234"
}
```
`Idempotency-Key` header is accepted as an alternative to `idempotency_key`.

**Idempotency reuse**: duplicates within the 24-hour window return 409
`ANALYTICS_DUPLICATE`; after the window, the stale row is row-locked and replaced
inside the new insert transaction. `dwell_seconds` is clamped to `[0, 14400]`.
`object_type='content'` ingest for a blacklisted content id returns 409
`BLACKLISTED_CONTENT`.

Funnel body:
```json
{
  "from": "2026-04-01",
  "to": "2026-04-17",
  "steps": [
    { "event_type": "content_impression" },
    { "event_type": "content_view", "min_dwell_seconds": 5 },
    { "event_type": "content_complete" }
  ],
  "window_seconds": 1800
}
```

---

## Reports

| Method | Path | Permission |
|---|---|---|
| `POST` | `/reports/scheduled` | `governance.export_reports` or administrator |
| `GET` | `/reports/scheduled` | authenticated |
| `POST` | `/reports/scheduled/{id}/run` | `governance.export_reports` |
| `GET` | `/reports/generated` | authenticated |
| `GET` | `/reports/generated/{id}` | authenticated |
| `GET` | `/reports/generated/{id}/download` | `governance.export_reports` (unmasked exports additionally require `sensitive.unmask`) |

Scheduled definition body:
```json
{
  "key": "content-weekly",
  "description": "Weekly content totals",
  "report_kind": "content_summary",
  "output_format": "csv",
  "parameters": {},
  "cron": "0 4 * * 1"
}
```
`report_kind` ∈ `content_summary` / `moderation_summary` / `event_summary` /
`analytics_daily`. `output_format` ∈ `csv` / `json`. Generated files are
SHA-256-checksummed and expire after 90 days (retention job at 02:15 UTC daily).

---

## Rate limiting

Default: **60 requests / minute / bucket**. Buckets:

- authenticated user → `u:<user_id>`
- unauthenticated → `ip:<REMOTE_ADDR>:<route_path>` (path-scoped so
  `/auth/login` and `/health` don't share a bucket)
- system accounts (`users.is_system=1`) → bypass

Exceeding the window returns 429 `RATE_LIMITED` with `Retry-After` in seconds.
