# Meridian — Content Integrity & Event Ops Platform: Design

## Overview

Meridian is an offline, single-host backend that ingests third-party article/media
metadata, normalizes it into trusted internal records, enforces rule-driven moderation,
aligns cross-source duplicates, governs versioned event schedules, and emits
auditable analytics — all without reaching beyond the local Docker composition.

Every external-facing behavior the PRD calls out (parsing, risk scoring, dedup, event
lifecycle, analytics ingestion, scheduled reports, audit) runs as a resource-oriented
HTTP API under `/api/v1`, structured by Slim 4 and backed by Eloquent over MySQL 8.0.

## Architectural goals

1. **Deterministic, offline processing.** No runtime path constructs an outbound HTTP
   client; `libxml` is pinned to `LIBXML_NONET`; media references are stored as local
   paths or content hashes without fetching remote resources.
2. **Deny-by-default authorization.** Authentication alone is never sufficient for
   business-domain mutations. Every mutating route is gated by a central `Policy`
   service that resolves capability + object scope in one place.
3. **Tamper-evident audit.** Every privileged/state-changing action is appended to
   `audit_logs` with a per-row SHA-256 chain; a daily job finalizes the chain into
   `audit_hash_chain` for per-day tamper evidence.
4. **Idempotent ingest.** Content ingest is idempotent on `(source_key,
   source_record_id)`; analytics ingest is idempotent on `idempotency_key` within a
   24-hour window with transactional reuse after expiry.
5. **Single-host operability.** All schedules, reports, logs, and metrics are written
   to the local `storage/` volume. A scheduler container evaluates cron expressions
   in-process against `job_definitions`.

## Stack

| Layer | Technology |
|---|---|
| HTTP framework | PHP 8.2 + Slim 4 |
| Dependency injection | PHP-DI |
| ORM | Eloquent (`illuminate/database`) |
| Database | MySQL 8.0 |
| Migrations | Phinx |
| Runtime | Docker Compose (`app`, `mysql`, `scheduler`) |
| Tests | PHPUnit 10 (unit + integration, in-memory SQLite + schema builder) |
| Crypto | AES-256-GCM (OpenSSL extension), bcrypt cost 12 |

## Service topology

```
┌──────────────────────────────────────────────────────────────────────┐
│                         meridian_net (bridge)                        │
│                                                                      │
│  ┌───────────┐      ┌──────────────────────────┐      ┌───────────┐  │
│  │  clients  │──►──▶│  app  (Slim, port 8080)  │──►──▶│  mysql    │  │
│  └───────────┘      │                          │      │  (8.0)    │  │
│                     │  ErrorResponse ─► RequestId ─►  │           │  │
│                     │     Auth ─► RateLimit ─► Route  │           │  │
│                     └──────────────────────────┘      └───────────┘  │
│                                                            ▲         │
│                     ┌──────────────────────────┐           │         │
│                     │  scheduler (scheduler_   │───────────┘         │
│                     │   loop.php, 30s tick)    │                     │
│                     └──────────────────────────┘                     │
│                                                                      │
│                    ┌────────────────────────────┐                    │
│                    │ storage/ (logs, metrics,   │                    │
│                    │   reports, exports)        │                    │
│                    └────────────────────────────┘                    │
└──────────────────────────────────────────────────────────────────────┘
```

- `app` serves HTTP directly on port 8080. A reverse proxy is intentionally omitted;
  operators may add one locally without changing application code.
- `scheduler` runs `scripts/scheduler_loop.php` continuously, evaluating cron
  expressions (`Meridian\Infrastructure\Cron\CronExpression`) in `job_definitions`
  every 30 seconds and enqueuing runs for the in-process `JobRunner`.
- `mysql` holds all persistent state on a named volume. No other persistence layer.

## Code layout

```
config/                      app & DI wiring (container.php)
database/
  migrations/                Phinx migrations — full schema
  seeds/                     roles, permissions, templates, job definitions
public/                      index.php HTTP front door
scripts/                     CLI entry points (scheduler_loop, admin bootstrap, ...)
src/
  Application/               Slim bootstrap, middleware stack, DI wiring, exceptions
  Domain/                    Auth, Audit, Blacklist, Content, Dedup, Moderation,
                             Events, Analytics, Reports, Jobs, Authorization, Ops
  Http/                      Controllers, routes, response envelope
  Infrastructure/            Clock, Crypto (AES-256-GCM), Cron, Logging, Metrics
storage/                     runtime output (log/export/report/metrics, volume-mounted)
tests/                       PHPUnit unit + integration suites
```

Domain subsystems expose services (e.g., `ContentService`, `ModerationService`,
`DedupService`, `AnalyticsService`, `ReportService`). Controllers are thin
adapters — request validation, DTO mapping, response envelope — and delegate all
business logic to the domain layer.

## Request lifecycle

Middleware runs outer → inner:

```
ErrorResponseMiddleware ─► RequestIdMiddleware ─► AuthMiddleware ─► RateLimitMiddleware ─► route
```

- `ErrorResponseMiddleware` converts every `ApiException` (and unexpected `Throwable`)
  into the canonical error envelope with the correct HTTP status.
- `RequestIdMiddleware` assigns a UUID request id that threads through audit entries
  and log lines.
- `AuthMiddleware` resolves the bearer session, attaches `user` + `session` request
  attributes, denies blacklisted users with `403 USER_BLACKLISTED`, and short-circuits
  a small list of public paths (`/auth/signup`, `/auth/login`, password reset,
  `/auth/security-questions`, `/health`).
- `RateLimitMiddleware` runs *after* auth so per-user buckets (`u:<user_id>`) can be
  used; unauthenticated traffic falls back to `ip:<REMOTE_ADDR>:<route_path>` and
  system accounts bypass the limiter entirely.

## Authorization model

`Meridian\Domain\Authorization\Policy` is the single decision point for object-level
authorization. Every protected route resolves capability + scope here:

1. **Capability check** — user has at least one of the listed permissions
   (`UserPermissions::hasPermission`).
2. **Scope check** — user’s `user_role_bindings.scope_type/scope_ref` row allows
   the object in question. Scope types: `global`/null, `content`,
   `event_family`, `moderation_reviewer`, `report`.

Administrators always have global scope for every protected resource. Ownership
shortcuts exist for specific, non-terminal cases: content creators may edit metadata
on their own `normalized`/`flagged`/`under_review` content, and event draft
creators may keep editing their own drafts.

List endpoints filter results through the policy (`filterContentIds`,
`filterModerationCaseIds`, `filterEventIds`) so unauthorized objects never leak via
search/listing.

## Data model highlights

Core tables (see `database/migrations/`):

- **users** — `username` unique, `password_hash` (bcrypt 12), `status` enum
  (`pending_activation`, `active`, `locked`, `password_reset_required`, `disabled`),
  `last_login_at`, lockout timestamps, `is_system` bypass flag.
- **roles / permissions / permission_groups** — RBAC primitives with group bindings
  (`role_permission_groups`, `permission_group_members`) and explicit `allow`/`deny`
  effect per role×permission.
- **user_role_bindings** — carries `(scope_type, scope_ref)` so object-scope
  authorization flows directly from role assignment.
- **user_sessions** — UUID id, SHA-256 of raw secret in `token_hash`, encrypted IP,
  absolute + idle expiries.
- **password_reset_tickets** — SHA-256(ticket_secret) with 15-minute TTL,
  one-time consumption.
- **contents** — `content_id` UUID unique, `source_key`/`media_source` enums,
  `language`, `published_at` (UTC, indexed), `body_checksum`, `risk_state` enum,
  `title_normalized` for dedup.
- **content_sources** — `(source_key, source_record_id)` unique, mapped to
  `content_id`. Drives ingest idempotency.
- **content_fingerprints** — normalized title/author, `duration_seconds`, `simhash_hex`,
  `composite_fingerprint` (SHA-256 of title‖author‖duration).
- **dedup_candidates** — pairs over the review threshold with `title_similarity`,
  `author_match`, `duration_match`, `status` (`pending_review` / `auto_mergeable`).
- **rule_packs / rule_pack_versions / rule_pack_rules** — versioned rule packs;
  published versions are immutable.
- **moderation_cases / moderation_case_flags / moderation_decisions /
  moderation_notes / moderation_reports / moderation_appeals** — case lifecycle;
  decisions are append-only, notes are append-only, appeals single-active.
- **events / event_versions / event_publications / event_rule_sets /
  event_advancement_rules / event_bindings / event_templates / event_venues /
  event_equipment** — versioned event schedules with publish/rollback/cancel
  semantics and non-overlapping effective windows for active publications.
- **analytics_events / analytics_idempotency_keys / analytics_rollups** —
  event ingestion with 24-hour idempotency protection, hourly rollups.
- **scheduled_reports / generated_reports / report_files** — definitions, runs,
  and SHA-256-checksummed report artifacts.
- **audit_logs / audit_hash_chain** — append-only log with per-row SHA-256 chain
  and daily finalization rows.
- **blacklist_entries** — entries for `user` / `content` / `source`; enforced in
  `AuthMiddleware` and at ingest time.
- **job_definitions / job_runs / job_locks** — cron + singleton-locked local
  scheduling ledger.
- **rate_limit_windows** — fixed-window per-bucket counters.

## Content ingest flow

`POST /api/v1/content/parse` routes through `ContentController::parse` →
`ContentService::ingest` → `NormalizationPipeline::normalize` →
`AutomatedModerator::moderate`, all inside a single DB transaction:

1. Guard: blacklisted `source` or blacklisted actor → 409/403 before doing work.
2. Idempotency: lookup `(source_key, source_record_id)` in `content_sources`; if
   found, return the existing content with `duplicate=true`.
3. Normalize: UTF-8 canonicalization (`mb_convert_encoding(..., 'UTF-8,
   Windows-1252, ISO-8859-1')`), HTML denoising via `DOMDocument`/XPath with
   `LIBXML_NONET` (dropping `script`, `style`, `iframe`, `nav`, `form`, ads-class
   boilerplate, etc.), URL stripping, title trim (1..180 chars), body-length floor
   (≥ 200 chars post-denoise), language detection (Unicode-block heuristics + stop
   words for EN/ES/FR/DE/IT/PT/NL with 0.75 confidence threshold), section tag
   canonicalization (≤ 10), `media_source` enum clamp.
4. Persist the trusted `contents` row + media refs + `content_sources` mapping +
   fingerprint (composite SHA-256, Jaro-Winkler-ready normalized title).
5. Run every currently-published rule pack in the **same transaction** via
   `AutomatedModerator::moderate`. Matching rules open one `moderation_cases` row;
   additional matches from other versions attach flags to that same case. Max
   severity maps to risk state: `critical` → `quarantined`, `warning` → `flagged`.
6. Audit-log the ingest and any risk-state transition.

Response carries the full normalized object plus `media_refs` and an
`automated_moderation` summary (`case_id`, `flag_count`, `new_risk_state`).

## Deduplication & ID alignment

- **Fingerprint**: `title_normalized` (lowercased, punctuation → spaces, whitespace
  collapsed) + normalized author + `duration_seconds`, joined with `\u001F` and
  SHA-256’d. `simhash_hex` computed over 3-word title shingles for near-duplicate
  discovery.
- **Similarity**: Jaro-Winkler over normalized titles.
- **Candidate generation** (`DedupService::recompute`): O(N²) pass over
  unmerged contents; pairs with sim ≥ `review_similarity_min` (0.85) become
  candidates. sim ≥ `auto_merge_similarity` (0.92) without an author conflict are
  marked `auto_mergeable`; everything else (or any author conflict) is
  `pending_review`.
- **Merge**: primary/secondary merge rewrites `content_sources` so every
  `source_record_id` now points at the primary, records `content_merge_history`, and
  sets `merged_into_content_id` on the secondary so it no longer pollutes dedup
  scans. Unmerge (administrator-only) reverses using `original_checksum` as the key.
- **Scheduled re-scan**: `dedup.recompute_candidates` runs nightly at 03:30 UTC.

## Moderation

- **Rule packs** are versioned. Publishing a version freezes it; archiving only
  flips status (no rule mutation). Empty versions cannot be published.
- **Rule kinds**: `keyword` (case-insensitive substring), `regex` (PCRE against
  body), `banned_domain` (host match against parsed provenance URLs), and
  `ad_link_density` (`(ad_link_count / max(body_len, 1)) * 1000 > threshold`; default 3.0).
- **Case lifecycle**: `open → under_review → resolved / rejected`. Decisions are
  append-only in `moderation_decisions`. Notes are append-only; `is_private` notes
  require `moderation.view_private_notes` to read.
- **SLA**: initial review due within 24 business hours (Mon–Fri 09:00–17:00 local).
  The due-at calculation walks business hours forward from the open time.
- **Reports**: `moderation.report.create` + object-scope (content view or
  ownership) required. Unknown content returns 422; unauthorized returns 403 with a
  `moderation.report_denied` audit entry.
- **Appeals**: `moderation.appeal.create` + case linkage (administrator, original
  reporter, content owner, or explicit content-scope binding). One active appeal
  per case. Authorization runs before eligibility/uniqueness so unauthorized
  callers cannot probe case state.

## Event lifecycle

- An **Event** is a named container with a family key and a template. Each draft
  version starts with the template's baseline rule set (3 attempts, 60-min pre-start
  check-in window, 10-min late cutoff by default) and an `effective_from`
  that can be set on publish.
- **Optimistic locking**: draft edits require `expected_draft_version_number`;
  mismatch returns 409 `DRAFT_LOCK_CONFLICT`.
- **Publish**: moves draft → published, snapshots the full config into
  `config_snapshot_json`, records an `event_publications` row with
  `action=publish`. Effective windows of active publications for one event may not
  overlap — conflict returns 409 `EFFECTIVE_OVERLAP`.
- **Rollback**: `POST /events/{id}/versions/{versionId}/rollback` targets a prior
  published (or previously rolled-back) version. Never mutates the published row;
  records a rollback entry in `event_publications`.
- **Cancel**: closes an active publication window without changing the snapshot.

## Analytics

- **Ingest** (`POST /analytics/events`): requires `analytics.ingest` +
  `idempotency_key` (body or `Idempotency-Key` header). `dwell_seconds` is clamped
  to `[0, 14400]` (4 hours).
- **Idempotency reuse semantics**: within the 24-hour window, duplicate keys
  return 409 `ANALYTICS_DUPLICATE`. After window expiry, the stale row is
  row-locked and dropped inside the new insert transaction so a legal reuse always
  succeeds while a concurrent within-window duplicate is still rejected.
- **Blacklist gate**: `object_type='content'` ingest is rejected if the content is
  blacklisted so rollups can't be seeded with events that will then be filtered at
  read time.
- **Storage**: IP addresses are stored AES-256-GCM encrypted.
  `analytics.view_unmasked` / `sensitive.unmask` permissions are required to see the
  plaintext; otherwise query responses return `[masked]`.
- **Rollups**: hourly `analytics.rollups` job aggregates raw events; they are
  derived, never authoritative — always regenerable from raw events.

## Reports

- **Scheduled reports**: stored in `scheduled_reports` with `cron_expression`,
  `output_format` (csv/json), `report_kind`
  (`content_summary`/`moderation_summary`/`event_summary`/`analytics_daily`).
- **Generation path**: resolve rows (masked/unmasked based on caller permission) →
  write to temp file under `storage/reports/` → SHA-256 checksum → atomic rename →
  record `report_files` metadata → audit → `expires_at = now + 90 days`.
- **Retention**: `reports.retention_cleanup` at 02:15 UTC daily marks rows
  `expired`, deletes the file, and emits an audit entry.
- **Download**: `governance.export_reports` required; unmasked exports additionally
  need `sensitive.unmask` and emit `reports.export_downloaded` audit.

## Security

- **Passwords**: bcrypt cost 12; minimum 12 characters; username pattern enforced
  server-side.
- **Sessions**: UUID session id + 32-byte raw secret. DB stores SHA-256 of the
  raw secret. TTLs: 12 h absolute, 2 h idle, 5 concurrent per user. Password reset
  revokes all active sessions.
- **Password reset**: ticket raw value returned once by
  `/auth/password-reset/begin`; DB stores `sha256(ticket_secret)` with 15-minute
  TTL, one-time consumption. All failure modes (expired, replayed, tampered,
  revoked) collapse to 401 `AUTHENTICATION_REQUIRED`.
- **Lockouts**: 5 login failures in 15 min → 30 min account lock; 5 reset
  failures in 30 min → 60 min reset lock.
- **At-rest secrets**: AES-256-GCM with per-ciphertext 12-byte IV + 16-byte tag.
  Envelope `v<version>:base64(iv || tag || cipher)`. `APP_PREVIOUS_KEYS=ver:hex,...`
  enables seamless rotation; `encrypt()` always uses the current key, `decrypt()`
  accepts any known version.
- **Rate limiting**: default 60 req/min/user, DB-backed fixed window.
- **Audit**: every `AuditLogger::record` call locks the last row, computes
  `sha256(prev_row_hash || iso_timestamp || actor || action || object || payload)`,
  and inserts under transaction. Daily `audit.finalize_daily_chain` seals the chain
  and refuses to seal day N when day N-1 is missing (but prior records exist).
- **Blacklist**: `AuthMiddleware` enforces `user` blacklist after session
  resolution; services enforce `content`/`source` blacklist at their entry points.

## Jobs & scheduling

`JobRunner` picks up `queued` rows from `job_runs`, acquires a `job_locks` entry
(singleton), invokes the registered `JobHandler`, and records attempts, resume
markers, failure reasons. Retry policy: max 3 attempts, backoff
`60/300/900` seconds. Stale `running` rows older than 1800 seconds are reaped.

Standard schedule (`database/seeds`):

| Job key | Cron | Purpose |
|---|---|---|
| `audit.finalize_daily_chain` | `0 1 * * *` | Seal previous day's audit chain |
| `reports.retention_cleanup` | `15 2 * * *` | Delete expired generated reports |
| `dedup.recompute_candidates` | `30 3 * * *` | Rebuild dedup candidates |
| `sessions.expire_cleanup` | `0 * * * *` | Mark expired sessions revoked; purge >30 days |
| `analytics.idempotency_cleanup` | `5 * * * *` | Drop expired idempotency keys |
| `analytics.rollups` | `10 * * * *` | Refresh `analytics_rollups` |
| `metrics.rotate_logs` | `20 0 * * *` | Rotate large log/metric files |
| `metrics.snapshot` | `0 * * * *` | Append aggregate platform counters |

## Logging & metrics

- **Logs**: structured JSON lines to `storage/logs/app.log`. Rotated by
  `metrics.rotate_logs` at 50 MB with `<base>.log.<YYYY-MM-DD-HHMMSS>` suffix.
- **Metrics**: NDJSON to `storage/metrics/metrics-YYYY-MM-DD.ndjson`, one event
  per line: `{"ts": ..., "name": ..., "value": ..., "labels": {...}}`.
  Emitters: `MetricsMiddleware` (`http.request.count`, `http.request.duration_ms`),
  `JobRunner` (`jobs.run.count`, `jobs.run.duration_ms`), `MetricsSnapshotJob`
  (hourly platform aggregates). Label values are sanitized to scalars; long
  hex/base64-looking strings are redacted to `[redacted]`.

## Non-functional targets

- p95 API latency < 250 ms for typical reads at 200 RPS on a single machine
  (indexed reads, no synchronous external calls, connection pool tuned in
  `config/container.php`).
- Daily batch jobs must complete within a 2-hour window — jobs are bounded by
  batch sizes (`limit` params on recompute and rollup queries) and are
  resumable via `resume_marker`.
- Deterministic behavior: no third-party network calls anywhere in `src/`, all
  randomness scoped to encryption (IVs, session secrets) — every normalization,
  dedup, moderation, and reporting step is reproducible from inputs.
