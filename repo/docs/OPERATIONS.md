# Operations Guide

## Startup sequence

1. `docker compose up -d --build` — brings up `mysql`, `app`, `scheduler`.
2. `docker compose exec app composer migrate` — applies schema.
3. `docker compose exec app composer seed` — seeds roles, permissions, templates, jobs.
4. `docker compose exec app composer admin:bootstrap` — creates the bootstrap admin.

## Background jobs

`job_definitions` holds one row per scheduled job. The `scheduler` container evaluates
`schedule_cron` every 30 seconds; matching minutes enqueue a `queued` `job_runs` row which
the runner picks up and hands to the bound `handler_class`.

| Job key | Schedule | Purpose |
|---|---|---|
| `audit.finalize_daily_chain` | `0 1 * * *` | Seal previous day's audit chain. Fails loudly if prior day missing. |
| `reports.retention_cleanup` | `15 2 * * *` | Delete generated reports beyond 90 days, remove files. |
| `dedup.recompute_candidates` | `30 3 * * *` | Rebuild dedup candidates. |
| `sessions.expire_cleanup` | `0 * * * *` | Mark expired sessions revoked; purge >30 days. |
| `analytics.idempotency_cleanup` | `5 * * * *` | Drop expired idempotency keys. |
| `analytics.rollups` | `10 * * * *` | Refresh analytics_rollups. |
| `metrics.rotate_logs` | `20 0 * * *` | Rotate large log/metric files. |
| `metrics.snapshot` | `0 * * * *` | Append aggregate platform counters (content/moderation/job/blacklist totals) to `storage/metrics/metrics-YYYY-MM-DD.ndjson`. |

Retry policy: max 3 attempts with backoff 60/300/900 seconds. Failures after max attempts
move to `failed`; operators can manually re-enqueue by inserting a new `job_runs` row for
the same `job_key`.

Stale `running` runs older than 1800 seconds are marked `failed` by the next tick.

## Filesystem layout

- `storage/logs/` — structured JSON logs (`app.log`).
- `storage/metrics/` — metrics exports written as daily NDJSON files named
  `metrics-YYYY-MM-DD.ndjson`. Every line has the fixed shape
  `{"ts":"<ISO8601 UTC>","name":"<metric>","value":<number>,"labels":{...}}`.
  Emitters: `MetricsMiddleware` (per-request counters and duration), `JobRunner`
  (per-job-run duration and status), `MetricsSnapshotJob` (hourly aggregate
  counters such as `content.by_risk_state`, `moderation.cases_by_decision`,
  `jobs.runs_by_status`, `blacklists.active_total`, `analytics.events_total`,
  `reports.generated_total`). Override the directory with `METRICS_ROOT`.
- `storage/reports/` — generated report files. Filenames are deterministic and checksummed.
- `storage/exports/` — ad-hoc operator exports (reserved; unused by default).

Only the directories above are permitted output paths. Any new export job must restrict
writes to these roots.

## Encryption key rotation

1. Generate a new 32-byte hex key (`php -r "echo bin2hex(random_bytes(32));"`).
2. In `.env`, add the previous key as `APP_PREVIOUS_KEYS="<old_version>:<old_hex>"`.
3. Bump `APP_MASTER_KEY` to the new hex and `APP_MASTER_KEY_VERSION` to a new integer.
4. Restart the composition.
5. Run `docker compose exec app composer key:rotate` to re-encrypt security answers with the
   new key. The old key remains available for decrypt during the transition.
6. Once migrated, remove the old entry from `APP_PREVIOUS_KEYS` and restart.

## Audit chain verification

- `composer audit:verify` walks every sealed day; exits non-zero on mismatch.
- `composer audit:verify 2026-04-15` checks one day.
- The CLI emits JSON with per-day status and a mismatch log_id when a row_hash does not recompute.

## Backups

- MySQL is the system of record. Use `mysqldump` against the `mysql` container for periodic
  offline backups (scheduled outside this composition).
- `storage/reports/` may be backed up in parallel; records remain authoritative even if
  files are restored from a snapshot, because each `report_files` entry contains the
  checksum.

## Rate limit overrides

`system_settings` is reserved for per-key overrides. The current default (60 req/min/user)
is enforced in `RateLimitMiddleware`. System accounts (`users.is_system = 1`) bypass.

## Permissions introspection

`GET /auth/me` returns the caller's effective permission keys so operators can verify
RBAC behavior from the API tier.

## Blacklist enforcement

Entries in `blacklists` with `entry_type = content` take effect across:

- `GET /api/v1/content/{id}` — non-admin callers receive 404 + audit entry
  `content.view_blocked_blacklisted`.
- `GET /api/v1/content` search — blacklisted rows are excluded for non-admins.
- `PATCH /api/v1/content/{id}` — non-admin edits rejected (403) + audit entry
  `content.edit_blocked_blacklisted`.
- `POST /api/v1/analytics/events` — ingestion with `object_type=content` for a
  blacklisted `object_id` returns 409 `BLACKLISTED_CONTENT` + audit entry
  `analytics.ingest_blocked_blacklisted_content`.
- `GET /api/v1/analytics/events`, `POST /api/v1/analytics/funnel`,
  `GET /api/v1/analytics/kpis` — non-admin queries exclude rows tied to
  blacklisted content before aggregation; targeted queries against a
  blacklisted object return 403.
- `POST /api/v1/dedup/merge` — rejected with 409 `BLACKLISTED_CONTENT` + audit
  entry `dedup.merge_blocked_blacklisted` if either side is blacklisted.

Administrators bypass read/search filtering so governance workflows (revoking
entries, exporting audit) can still read the underlying records.

## Analytics scope

`analytics.query` is capability-gated, but scope is enforced in-service by
`Policy::applyAnalyticsScope`:

- Administrators see everything.
- Other users see only events where they are the actor, where the event targets
  content visible to them (safe risk states, their own drafts, or a
  `scope_type=content` role binding), or where the event targets an event
  within their `scope_type=event_family` binding or authored by them.
- Targeted requests against an out-of-scope object return 403 and an
  `analytics.query_denied_out_of_scope` audit entry instead of silently empty
  results.

## Health checks

- `GET /api/v1/health` — HTTP + DB connectivity.
- `job_runs` table — recent runs, attempts, failure reasons.
- `audit_hash_chain` — presence of a row per day demonstrates the finalization job is running.
