# Meridian API Catalog

All endpoints are prefixed with `/api/v1`. Successful responses use the envelope defined in
PRD section 11.1; errors use the error envelope.

## Conventions

- `Authorization: Bearer <token>` required unless endpoint is listed as public.
- All JSON; `Content-Type: application/json`.
- Timestamps are UTC ISO-8601 (`YYYY-MM-DDTHH:mm:ssZ`).
- Pagination parameters: `page`, `page_size` (default 25, max 100). List responses include
  `meta.total`, `meta.page`, `meta.page_size`.

## Authorization model

Protected routes fall into two categories, which apply different gates:

**Business-domain protected routes** (content, events, moderation, analytics, reports,
governance, etc.) apply two gates before serving a request:
1. **capability** — the caller must hold the named permission key (e.g. `content.edit_metadata`).
2. **object scope** — where the route targets a specific object, it must fall within the
   caller's allowed scope. The central `Policy` service
   (`src/Domain/Authorization/Policy.php`) resolves scope from
   `user_role_bindings.scope_type/scope_ref` and, where appropriate, object ownership.

**Auth self-service / session routes** (`GET /auth/me`, `POST /auth/logout`) do not run
the capability + object-scope model. They operate on the caller's own authenticated
session context: `/auth/me` requires a resolved `user` attribute on the request and
returns that user's profile + effective permissions; `/auth/logout` requires a resolved
`session` attribute and revokes it. See `src/Http/Controllers/AuthController.php`
(`me`, `logout`) for the exact behavior. Public auth routes (`/auth/signup`,
`/auth/login`, password reset, `/auth/security-questions`) require neither a session nor
capability checks.

Deny-by-default is the default for every business-domain write endpoint. Authentication
alone is never sufficient for business-domain mutation. Unauthorized list/search calls
never leak unauthorized objects: service-layer filters drop them before the page is
serialized.

Supported scope types:
- `global` (or null binding) — all objects of the resource class
- `content` — specific `content_id`
- `event_family` — events whose `event_family_key` matches `scope_ref`
- `moderation_reviewer` — presence grants reviewer-queue access
- `report` — specific `generated_report.id`

The administrator role has global scope for every protected resource.

## Middleware order

Middleware runs outer -> inner as:
`ErrorResponse -> RequestId -> Auth -> RateLimit -> route`.

Rate limiting therefore sees the authenticated `user` attribute and derives
per-user buckets (`u:<user_id>`). Unauthenticated traffic falls back to
`ip:<REMOTE_ADDR>:<route_path>` buckets, and system accounts (`users.is_system = 1`) bypass
the limiter entirely.

## User blacklist enforcement

Round-3 Fix E centralizes `entry_type='user'` blacklist enforcement in `AuthMiddleware`.
After the session + user are resolved, the middleware calls
`BlacklistService::isBlacklisted('user', $user_id)`. A match triggers:

- `403 USER_BLACKLISTED` response envelope
- `auth.blacklist_denied` audit entry (actor = the blacklisted user id, payload = path + method)
- no downstream service invocation for that request

Effect:
- blacklisted users cannot hit any protected route (moderation writes, analytics ingest, content, events, reports — all routes behind `AuthMiddleware` are blocked the same way)
- existing valid tokens become useless the instant the blacklist entry is created; there is no session to "expire" through
- revoking the blacklist entry (via `DELETE /blacklists/{id}`, governance-only) restores access immediately
- public endpoints (`/auth/signup`, `/auth/login`, password reset, health, `/auth/security-questions`) are untouched; admins still need to be able to log in to manage the blacklist

## Auth & Identity

### GET /auth/security-questions (public)
Returns the catalogue of active security questions: `[{ id, prompt }, ...]`. Used by signup
clients to render the correct prompts before an account exists.

### POST /auth/signup (public)
Self-service signup for local accounts.
```json
{
  "username": "newuser",
  "password": "AtLeast12CharsLongEnough",
  "display_name": "New User",
  "security_answers": [
    { "question_id": 1, "answer": "first pet answer" },
    { "question_id": 2, "answer": "birth city answer" }
  ]
}
```
Behavior:
- Creates a local account with status `active` and role `learner`.
- Persists at least 2 security answers encrypted at rest (AES-256-GCM) so the account can later use the existing password reset flow.
- Issues a session bearer token identical to `/auth/login`.
- Sensitive fields (password hash, security answers, key versions) are never returned.
- Rate-limited by the anonymous `ip:<REMOTE_ADDR>:<path>` bucket.
- Failure codes: `VALIDATION_ERROR` (422) for bad username/password/answers, `USERNAME_TAKEN` (409) for duplicate usernames.

### POST /auth/login (public)
```json
{ "username": "admin", "password": "..." }
```
Returns `{ token, user: { id, username, display_name, status } }`.

### POST /auth/logout
Revokes the current session.

### POST /auth/password-reset/begin (public)
```json
{ "username": "admin" }
```
Returns `{ reset_ticket, expires_at, questions: [{ id, prompt }] }`.

The `reset_ticket` value is returned exactly once. Only SHA-256(ticket_secret) is persisted
in `password_reset_tickets`. Tickets expire after 15 minutes, are bound to the user that
initiated the flow, and are revoked when a subsequent begin-reset call is made for the same
user.

### POST /auth/password-reset/complete (public)
```json
{
  "username": "admin",
  "reset_ticket": "<value from begin>",
  "new_password": "NewPasswordThatIs12+",
  "answers": [{ "question_id": 1, "answer": "..." }, { "question_id": 3, "answer": "..." }]
}
```

The ticket is validated (user bound, not consumed, not revoked, not expired, constant-time
hash match) before the security answers are checked. Replay, tampered, or expired tickets
all return 401 `AUTHENTICATION_REQUIRED`. On success the ticket's `consumed_at` is set
atomically with the password change and all active sessions for that user are revoked.


### GET /auth/me
Returns the current user identity plus effective permission keys.

## User Administration (requires `auth.manage_users` unless noted)

| Method | Path | Purpose |
|---|---|---|
| POST | `/admin/users` | Create user |
| GET | `/admin/users` | List users (q, status filters) |
| GET | `/admin/users/{id}` | Get user |
| PATCH | `/admin/users/{id}` | Update status/email/display_name |
| POST | `/admin/users/{id}/role-bindings` | Assign role (requires `auth.manage_roles`) |
| DELETE | `/admin/users/{id}/role-bindings/{bindingId}` | Remove role |
| POST | `/admin/users/{id}/password-reset` | Admin password reset (requires `auth.reset_other_password`) |
| POST | `/admin/users/{id}/security-answers` | Set security answers |
| GET | `/admin/security-questions` | List active questions |

## Blacklists (`governance.manage_blacklists`)

- `GET /blacklists` — paginated listing
- `POST /blacklists` — `{ entry_type, target_key, reason? }` (entry_type ∈ user/content/source)
- `DELETE /blacklists/{id}` — revoke

## Audit (`governance.view_audit`)

- `GET /audit/logs` — filter by action, object_type, object_id, actor_id
- `GET /audit/chain` — recent daily chain entries
- `GET /audit/chain/verify?day=YYYY-MM-DD` — walk hash chain (administrator only)

## Content

### POST /content/parse (`content.parse`)
```json
{
  "source": "acme_news",
  "source_record_id": "article-123",
  "kind": "html",
  "payload": "<html>...</html>",
  "title": "Optional override",
  "author": "Jane",
  "media_source": "article",
  "section_tags": ["technology", "news"],
  "published_at": "2026-04-17T09:00:00Z",
  "duration_seconds": null,
  "language": "en"
}
```
- Idempotent on `(source, source_record_id)`.
- 422 returned when the normalized body is shorter than 200 chars or language confidence is
  below the threshold (unless the caller holds `content.language_override` and supplies
  `language`).

**Response shape (success):**
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
      "author": "Jane",
      "published_at": "2026-04-17T09:00:00Z",
      "media_source": "article",
      "section_tags": ["technology", "news"],
      "duration_seconds": null,
      "risk_state": "normalized",
      "body_length": 1234,
      "body_checksum": "sha256...",
      "ingested_at": "2026-04-17T12:00:00Z",
      "version": 1
    },
    "media_refs": [
      { "media_type": "image", "local_path": null, "reference_hash": "sha256...", "external_url": "...", "caption": null }
    ],
    "automated_moderation": {
      "case_id": "uuid|null",
      "flag_count": 0,
      "new_risk_state": "normalized"
    }
  },
  "meta": { "request_id": "...", "timestamp_utc": "..." },
  "error": null
}
```

Ingest runs automated moderation against every currently-published rule pack as part of the
same transaction (keyword, regex, banned_domain, ad_link_density). When any rule fires the
response includes the opened `case_id`, the number of flags, and the new `risk_state`
(`flagged` for max severity=warning, `quarantined` for max severity=critical).

### Other content routes
| Method | Path | Perm |
|---|---|---|
| GET | `/content` | `content.view` |
| GET | `/content/{id}` | `content.view` |
| PATCH | `/content/{id}` | `content.edit_metadata` |

## Deduplication

| Method | Path | Perm |
|---|---|---|
| GET | `/dedup/candidates?status=pending_review` | `content.merge` or `moderation.review` |
| POST | `/dedup/merge` | `content.merge` |
| POST | `/dedup/unmerge` | administrator |
| POST | `/dedup/recompute` | administrator |

`/dedup/merge` body: `{ primary_content_id, secondary_content_id, reason? }`.

## Rule Packs

| Method | Path | Perm |
|---|---|---|
| GET | `/rule-packs` | any authenticated |
| POST | `/rule-packs` | `admin.manage_rules` |
| POST | `/rule-packs/{id}/versions` | `rules.draft` |
| POST | `/rule-packs/versions/{versionId}/rules` | `rules.draft` |
| POST | `/rule-packs/versions/{versionId}/publish` | `rules.publish` |
| POST | `/rule-packs/versions/{versionId}/archive` | `rules.archive` |
| GET | `/rule-packs/versions/{versionId}` | any authenticated |

Rule kinds: `keyword`, `regex`, `banned_domain`, `ad_link_density`.

## Moderation

| Method | Path | Perm |
|---|---|---|
| GET | `/moderation/cases` | `moderation.view_cases` |
| GET | `/moderation/cases/{id}` | `moderation.view_cases` |
| POST | `/moderation/cases` | `moderation.review` |
| POST | `/moderation/cases/{id}/assign` | `moderation.review` |
| POST | `/moderation/cases/{id}/transition` | `moderation.review` |
| POST | `/moderation/cases/{id}/decisions` | `moderation.decide` |
| POST | `/moderation/cases/{id}/notes` | `moderation.review` |
| GET | `/moderation/cases/{id}/notes` | authenticated; private notes require `moderation.view_private_notes` |
| POST | `/moderation/reports` | `moderation.report.create` + `content.view` on the target (or content ownership, or administrator) |
| POST | `/moderation/cases/{id}/appeal` | `moderation.appeal.create` + case linkage (original reporter, content owner, explicit content-scope binding, or administrator) |
| POST | `/moderation/cases/{id}/appeal/resolve` | `moderation.appeal_resolve` |

### Moderation report submission

Report creation is **not** authentication-only. Callers must hold the explicit
`moderation.report.create` permission. When a `content_id` is supplied the Policy layer also
requires that the caller either owns the content or passes `canViewContent` (administrator
is always allowed). Missing capability or out-of-scope targets return 403 and emit a
`moderation.report_denied` audit record. An unknown `content_id` returns 422 `VALIDATION_ERROR`.

Default bindings (see `database/seeds/RolesAndPermissionsSeeder.php` + migration
`20260501000001_add_moderation_write_permissions.php`): `learner`, `instructor`, `reviewer`,
and `administrator` all hold `moderation.report.create`.

### Moderation appeal submission

Appeal creation requires the explicit `moderation.appeal.create` capability **and** a
case-level relationship. Allowed actors:

- administrators
- the user that previously filed a report tied to the same case (`moderation_reports.reporter_user_id`)
- the creator of the content referenced by the case (`contents.created_by_user_id`)
- a user holding a `scope_type='content'` binding whose `scope_ref` equals the case's `content_id`

Unauthorized callers receive 403 and emit a `moderation.appeal_denied` audit entry.
Eligibility & uniqueness checks run only after authorization: appeals on non-resolved cases
return 409 `CASE_NOT_RESOLVED`; submitting a second appeal while one is active returns 409
`APPEAL_ACTIVE`. Default bindings match report-create (learner/instructor/reviewer/admin).

## Events

| Method | Path | Perm |
|---|---|---|
| POST | `/events` | `events.draft` or administrator |
| GET | `/events` | authenticated |
| GET | `/events/{id}` | authenticated |
| POST | `/events/{id}/versions` | `events.draft` |
| PATCH | `/events/{id}/versions/{versionId}` | `events.draft` (accepts `expected_draft_version_number`) |
| POST | `/events/{id}/versions/{versionId}/publish` | administrator / `events.publish` |
| POST | `/events/{id}/versions/{versionId}/rollback` | administrator / `events.rollback` |
| POST | `/events/{id}/versions/{versionId}/cancel` | administrator / `events.cancel` |
| POST | `/events/{id}/versions/{versionId}/bindings` | `events.manage_bindings` |
| GET | `/events/{id}/versions/{versionId}` | authenticated |

## Analytics

| Method | Path | Perm |
|---|---|---|
| POST | `/analytics/events` | `analytics.ingest` (idempotency key required) |
| GET | `/analytics/events` | `analytics.query` |
| POST | `/analytics/funnel` | `analytics.query` |
| GET | `/analytics/kpis?from=..&to=..` | `analytics.query` |

Ingest request:
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

**Idempotency reuse semantics** (round-3 Fix D):
- Duplicate keys submitted **within 24 hours** of the original insert return 409 `ANALYTICS_DUPLICATE`.
- After the 24-hour protection window elapses, the same key becomes reusable. The expired
  `analytics_idempotency_keys` row is dropped atomically inside the new insertion's
  transaction under a row-level lock, so a stale row can never block a legal reuse and a
  concurrent within-window duplicate is still rejected.
- The `analytics.idempotency_cleanup` job prunes expired rows asynchronously; reuse does not
  depend on that job having run.

## Reports

| Method | Path | Perm |
|---|---|---|
| POST | `/reports/scheduled` | `governance.export_reports` or administrator |
| GET | `/reports/scheduled` | authenticated |
| POST | `/reports/scheduled/{id}/run` | `governance.export_reports` |
| GET | `/reports/generated` | authenticated |
| GET | `/reports/generated/{id}` | authenticated |
| GET | `/reports/generated/{id}/download` | `governance.export_reports` (unmasked exports also require `sensitive.unmask`) |

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

## Health

`GET /health` — always reachable; returns 503 if the database is unreachable.

## Error codes

| Code | Status | Meaning |
|---|---|---|
| `VALIDATION_ERROR` | 422 | Invalid request body / rule violation |
| `AUTHENTICATION_REQUIRED` | 401 | Missing/invalid token |
| `NOT_AUTHORIZED` | 403 | Permission gate failed |
| `NOT_FOUND` | 404 | Resource missing |
| `CONFLICT` | 409 | Duplicate/state conflict |
| `ANALYTICS_DUPLICATE` | 409 | Repeat idempotency key within window |
| `EFFECTIVE_OVERLAP` | 409 | Event version effective window overlap |
| `DRAFT_LOCK_CONFLICT` | 409 | Optimistic lock mismatch on draft update |
| `RATE_LIMITED` | 429 | Too many requests |
| `USER_BLACKLISTED` | 403 | The authenticated user has an active `user` blacklist entry — every protected request is denied at the AuthMiddleware gate. |
| `INTERNAL_ERROR` | 500 | Unexpected failure |
