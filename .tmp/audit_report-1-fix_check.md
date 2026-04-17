# Fix Verification for `.tmp/audit_report-1.md` (Static-Only)

Date: 2026-04-17

## Scope and method
- Static-only re-check of the three issues listed in `.tmp/audit_report-1.md`.
- No runtime execution performed (no app startup, Docker, tests, or external services).
- Conclusions are based on current repository code and test artifacts.

## Re-check results

### 1) High: Protected analytics views not object/scope constrained
- Prior status: **Fail**
- Current status: **Fixed (static evidence)**
- Why:
  - Scope filtering helper now exists and is implemented in policy: `applyAnalyticsScope(...)` with actor/object constraints, plus `visibleContentIdsForAnalytics(...)` for content visibility set derivation in `src/Domain/Authorization/Policy.php:324` and `src/Domain/Authorization/Policy.php:370`.
  - Analytics query path applies scope before pagination/aggregation in `src/Domain/Analytics/AnalyticsService.php:150`.
  - Funnel path applies scope in each stage in `src/Domain/Analytics/AnalyticsService.php:242`.
  - KPI path applies scope before aggregate computation in `src/Domain/Analytics/AnalyticsService.php:290`, `src/Domain/Analytics/AnalyticsService.php:295`, `src/Domain/Analytics/AnalyticsService.php:317`, `src/Domain/Analytics/AnalyticsService.php:322`.
  - Targeted out-of-scope object requests are explicitly denied/audited in `src/Domain/Analytics/AnalyticsService.php:158` and `src/Domain/Analytics/AnalyticsService.php:163`.

### 2) Medium: Metrics export documented but not implemented
- Prior status: **Partial Fail**
- Current status: **Fixed (static evidence)**
- Why:
  - Concrete metrics writer exists and writes deterministic NDJSON under configured metrics root in `src/Infrastructure/Metrics/MetricsWriter.php:23` and `src/Infrastructure/Metrics/MetricsWriter.php:59`.
  - Runtime request metrics middleware is implemented and records request count/duration in `src/Application/Middleware/MetricsMiddleware.php:19` and `src/Application/Middleware/MetricsMiddleware.php:51`.
  - Scheduled aggregate export job exists in `src/Domain/Ops/Jobs/MetricsSnapshotJob.php:21` and emits multiple counters via metrics writer (`src/Domain/Ops/Jobs/MetricsSnapshotJob.php:34`, `src/Domain/Ops/Jobs/MetricsSnapshotJob.php:52`, `src/Domain/Ops/Jobs/MetricsSnapshotJob.php:55`).
  - DI wiring and config for metrics root are present in `config/container.php:52` and `config/app.php:20`.
  - Job runner emits job run metrics in `src/Domain/Jobs/JobRunner.php:160`.
  - Integration tests statically evidence writer/middleware/snapshot behavior in `tests/Integration/Ops/MetricsExportTest.php:19`, `tests/Integration/Ops/MetricsExportTest.php:64`, `tests/Integration/Ops/MetricsExportTest.php:74`.

### 3) Medium: Content blacklist enforcement partial
- Prior status: **Partial Fail**
- Current status: **Fixed (static evidence)**
- Why:
  - Content read is blocked for non-admins when content is blacklisted in `src/Domain/Content/ContentService.php:268`.
  - Content metadata edit is blocked for non-admins when content is blacklisted in `src/Domain/Content/ContentService.php:212`.
  - Content search excludes blacklisted content for non-admins in `src/Domain/Content/ContentService.php:328`.
  - Analytics ingest rejects blacklisted content in `src/Domain/Analytics/AnalyticsService.php:72` and `src/Domain/Analytics/AnalyticsService.php:77`.
  - Analytics protected views apply blacklist exclusion in `src/Domain/Analytics/AnalyticsService.php:201` and `src/Domain/Analytics/AnalyticsService.php:243`.
  - Dedup merge rejects participation of blacklisted content in `src/Domain/Dedup/DedupService.php:139` and `src/Domain/Dedup/DedupService.php:146`.
  - Integration coverage for these behaviors exists in `tests/Integration/Blacklist/ContentBlacklistEnforcementTest.php:42`, `tests/Integration/Blacklist/ContentBlacklistEnforcementTest.php:57`, `tests/Integration/Blacklist/ContentBlacklistEnforcementTest.php:84`, `tests/Integration/Blacklist/ContentBlacklistEnforcementTest.php:103`, `tests/Integration/Blacklist/ContentBlacklistEnforcementTest.php:140`.

## Final verdict
- Previously open issues re-checked: **3 / 3**
- Marked fixed by static evidence: **3 / 3**
- Overall re-check conclusion: **All previously reported issues in `.tmp/audit_report-1.md` are fixed (static-only assessment).**

## Static-only caveat
- This verification confirms code-path presence and test artifacts, but does not assert runtime behavior in deployment conditions (filesystem permissions, scheduler invocation cadence, or environment-specific middleware wiring at runtime).
