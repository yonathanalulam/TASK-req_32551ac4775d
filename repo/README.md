# Meridian — Content Integrity & Event Ops Platform

**Project type:** backend (HTTP API, no frontend).

Meridian is an offline, single-host backend that ingests third-party article/media metadata,
applies content integrity & moderation controls, manages duplicate alignment, governs versioned
event schedules, and tracks analytics through auditable, append-only workflows.

All runtime behavior is confined to the local Docker composition. No external network services
(email, SMS, object storage, cloud queues, remote moderation, SaaS auth) are called at runtime.

## Stack
| Layer | Technology |
|---|---|
| HTTP framework | PHP 8.2 + Slim 4 |
| ORM | Eloquent (illuminate/database) |
| Database | MySQL 8.0 |
| Migrations | Phinx |
| Runtime | Docker Compose (single host) |
| Auth | Local username/password (bcrypt) + AES-256-GCM for secrets |
| Audit | Append-only hash-chained logs with daily finalized seals |
| Jobs | DB-backed runner with cron evaluation in-process |

## Quickstart (Docker-contained — no host installs)

Every step below runs inside Docker. The only host tool required is Docker itself.
`docker-compose up` and `docker compose up` are both supported — use whichever your
Docker version provides.

```bash
# 1. Copy the example env (one-time).
cp .env.example .env

# 2. Build & start the stack. Either of the two styles works:
docker-compose up -d --build          # classic docker-compose CLI
# or:
docker compose up -d --build          # Docker CLI plugin

# 3. Run migrations, seed RBAC, provision the admin + demo users (all inside containers).
docker compose exec app composer migrate
docker compose exec app composer seed
docker compose exec app composer admin:bootstrap
docker compose exec app composer demo:bootstrap
```

Notes:
- `composer install` is baked into the `app` image at build time
  (`Dockerfile` runs `composer install --no-dev`), so there is no runtime install step.
- The master AES key defaults to the deterministic value in `.env.example`. Replace it
  with your own 64-hex output before opening the service to real traffic.

## Verification

After `compose up` completes and the bootstrap commands have run, verify the stack is
healthy and authentication works end-to-end using only `curl`.

### 1. Health check (public)

```bash
curl -s http://localhost:8080/api/v1/health
```

Expected (HTTP 200):
```json
{
  "success": true,
  "data": { "status": "ok", "database": "reachable" },
  "meta": { "request_id": "<uuid>", "timestamp_utc": "<iso8601>" },
  "error": null
}
```

### 2. Authenticated flow — login then call a protected endpoint

```bash
TOKEN=$(curl -s -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"demo_admin","password":"DemoPass!2026"}' \
  | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["data"]["token"];')

curl -s -H "Authorization: Bearer $TOKEN" http://localhost:8080/api/v1/auth/me
```

Expected (HTTP 200): `{"success":true,"data":{"id":<n>,"username":"demo_admin","status":"active","permissions":[...]},...}`.

### 3. Role-protected endpoint — admin-only audit chain

```bash
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/api/v1/audit/chain
```

Expected: `200` for `demo_admin`. The same request as `demo_learner` returns `403 NOT_AUTHORIZED`:

```bash
LEARNER_TOKEN=$(curl -s -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"demo_learner","password":"DemoPass!2026"}' \
  | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["data"]["token"];')
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "Authorization: Bearer $LEARNER_TOKEN" \
  http://localhost:8080/api/v1/audit/chain
```

Expected: `403`.

## Demo credentials

`composer demo:bootstrap` (run during Quickstart step 3) provisions one account per
business role so reviewers can exercise every permission surface without writing SQL:

| Role          | Username          | Password         | Command that creates it             |
|---------------|-------------------|------------------|-------------------------------------|
| Learner       | `demo_learner`    | `DemoPass!2026`  | `composer demo:bootstrap`           |
| Instructor    | `demo_instructor` | `DemoPass!2026`  | `composer demo:bootstrap`           |
| Reviewer      | `demo_reviewer`   | `DemoPass!2026`  | `composer demo:bootstrap`           |
| Administrator | `demo_admin`      | `DemoPass!2026`  | `composer demo:bootstrap`           |
| Bootstrap admin | `admin`         | value of `BOOTSTRAP_ADMIN_PASSWORD` in `.env` | `composer admin:bootstrap` |

Override the shared demo password by exporting `DEMO_USER_PASSWORD` before running the
script. Re-running `demo:bootstrap` is idempotent — existing accounts are left alone, so
the documented password is only the *initial* value.

The API is then available at http://localhost:8080/api/v1/health.

## Layout

```
config/           app/container wiring
database/
  migrations/     Phinx migrations (full schema)
  seeds/          Initial roles/permissions/templates/jobs
public/           index.php HTTP front door
scripts/          CLI entry points
src/
  Application/    Slim bootstrap, middleware, DI wiring
  Domain/         Auth, Audit, Blacklist, Content, Dedup, Moderation, Events, Analytics, Reports
  Http/           Controllers, routes, response envelope
  Infrastructure/ Clock, Crypto, Cron, Logging
storage/          Local log/export/report/metrics output (container-writable)
tests/            PHPUnit unit + integration suites
```

## Principal scripts

| Command | Purpose |
|---|---|
| `composer migrate` | Apply schema migrations |
| `composer seed` | Load roles, permissions, templates, job definitions |
| `composer admin:bootstrap` | Create bootstrap administrator user |
| `composer jobs:daily` | Enqueue and drain all daily jobs locally |
| `composer audit:verify [date]` | Walk and verify audit hash chain |
| `composer key:rotate` | Re-encrypt security answers with current master key |

The `scheduler` container runs `scripts/scheduler_loop.php` continuously, evaluating cron
expressions in `job_definitions` and ticking the runner every 30 seconds.

## Authentication

- GET `/api/v1/auth/security-questions` — public catalogue of active prompts used for signup/reset clients.
- POST `/api/v1/auth/signup` — self-service account creation (learner role, active status).
- POST `/api/v1/auth/login` — returns a bearer token.
- POST `/api/v1/auth/logout` — revokes the current session.
- POST `/api/v1/auth/password-reset/begin` — returns security question prompts.
- POST `/api/v1/auth/password-reset/complete` — completes a reset using verified answers.

Hard security invariants:
- bcrypt password hashing (cost 12, minimum 12-character passwords)
- AES-256-GCM for encrypted-at-rest secrets & security answers
- session TTLs: 12 h absolute, 2 h idle, 5 concurrent max per user
- lockout: 5 failures in 15 min -> 30 min account lock; 5 reset failures in 30 min -> 60 min reset lock
- password reset requires a one-time persisted ticket (15-minute TTL) that is revoked on use
- all privileged operations recorded to `audit_logs` with a running per-row SHA-256 chain
- every protected route enforces capability AND object scope through
  `Meridian\Domain\Authorization\Policy`; mutating endpoints are deny-by-default
- `AuthMiddleware` additionally consults the user blacklist after session resolution and
  returns `403 USER_BLACKLISTED` for any authenticated request issued by a blacklisted
  user, with an `auth.blacklist_denied` audit entry
- analytics idempotency keys are enforced transactionally: within-window duplicates return
  `409 ANALYTICS_DUPLICATE`; after the 24-hour window the same key becomes reusable even
  if the stale row is still present (it is atomically replaced inside the ingest tx)
- middleware order: `ErrorResponse -> RequestId -> Auth -> RateLimit -> route`, so rate
  limit buckets are keyed per-user (`u:<id>`) once authentication resolves
- content ingest runs all currently-published rule packs inside the same transaction as
  persistence; flags, moderation cases, and risk-state transitions commit atomically with
  the trusted content record

See `docs/API.md` for the full endpoint catalog.
See `ASSUMPTIONS.md` for all implementation-time assumptions.

## Offline guarantees

- No code paths in `src/` construct outbound HTTP clients; `libxml` is configured with `LIBXML_NONET`.
- Parsers accept only caller-supplied payloads; media references store either local paths or hashes.
- All exports (reports, metrics, logs) land under `storage/` inside the single-host volume mount.
- Docker composition contains only `mysql`, `app`, and `scheduler` services on a local bridge network.

## Metrics export

Metrics are written as append-only NDJSON files to `storage/metrics/`
(override with `METRICS_ROOT`). Filenames follow `metrics-YYYY-MM-DD.ndjson`
and every line has the fixed shape:

```json
{"ts":"2026-04-17T14:00:00Z","name":"jobs.run.duration_ms","value":124,"labels":{"job_key":"analytics.rollups","status":"succeeded","attempt":1}}
```

The `MetricsMiddleware` emits per-request counters (`http.request.count`,
`http.request.duration_ms`) and the `JobRunner` emits per-run counters
(`jobs.run.count`, `jobs.run.duration_ms`). The hourly `metrics.snapshot` job
writes aggregate platform counters such as `content.by_risk_state`,
`moderation.cases_by_decision`, `blacklists.active_total`, and
`analytics.events_total`. Label values are sanitised to scalars and long
hex/base64-looking strings are redacted to `[redacted]`.

## Testing

```bash
docker compose exec app composer test
```

Runs Phinx migrations against the `meridian_test` database and then PHPUnit across unit +
integration suites. Unit tests don't require a database.
