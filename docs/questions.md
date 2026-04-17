# Meridian Content Integrity & Event Ops Platform — Clarification Questions

## 1. Parser Input Model: `html` vs `plain_text` Branch and UTF-8 Canonicalization

**Question:** The prompt specifies that the parsing API "accept[s] raw HTML or plain text plus an optional source identifier," but does not define how the caller signals which branch applies, what happens to invalid byte sequences, or how the raw payload is preserved for later forensic audit. Swallowing invalid UTF-8 silently risks lossy normalization; rejecting the request outright punishes upstream feeders that routinely emit Windows-1252.

**My Understanding:** The request body should carry an explicit `kind` discriminator so HTML denoising is not accidentally applied to plain text. Character encoding correction should be deterministic — the same invalid byte should produce the same replacement character across every run — and the raw payload checksum/size should always be persisted so we can later prove exactly what we ingested even when normalization rejects the record.

**Solution:** The `POST /api/v1/content/parse` contract accepts a required `kind` field (`html` or `plain_text`); `NormalizationPipeline::normalize` in `src/Domain/Content/Parsing/NormalizationPipeline.php` rejects any other value with 422. UTF-8 canonicalization is performed by `mb_convert_encoding($payload, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1')` so invalid sequences are replaced deterministically (not discarded, not faulted). Before any transformation, `raw_checksum = sha256(payload)` and `raw_bytes = strlen(payload)` are captured into `content_ingest_requests` — those rows are written even when the downstream pipeline fails validation, which preserves the provenance trail required by audit.

---

## 2. HTML Denoising: Tag/Attribute Allowlist and Offline XXE Posture

**Question:** "HTML denoising" is specified without identifying which structural elements carry signal (article body) versus which are boilerplate (nav, ads, share buttons). Loose stripping risks losing body text; aggressive stripping can accidentally fail the 200-character minimum. Separately, the prompt is explicit about full offline operation — any XML/HTML parser configured with default entity resolution is an outbound-network risk.

**My Understanding:** The denoiser should use an in-process DOM parser with network resolution disabled at the libxml layer, drop the structural elements that never carry body signal (`script`, `style`, `iframe`, `noscript`, `object`, `embed`, `template`, `form`, `nav`, `header`, `footer`, `aside`, `button`, `menu`, `dialog`, `figcaption`), and also drop any element whose `id` or `class` matches common boilerplate keywords (`ad`, `promo`, `sponsor`, `banner`, `cookie`, `share`). Preserved links should be captured as provenance so banned-domain rules can evaluate them later.

**Solution:** `HtmlDenoiser` in `src/Domain/Content/Parsing/HtmlDenoiser.php` loads input via `DOMDocument::loadHTML` with `LIBXML_NONET` set so entity resolution cannot reach the network. XPath drops the structural element set listed above, plus any element whose `id`/`class` regex matches ad/promo/sponsor/banner/cookie/share boilerplate. The remaining `<a href>` values are extracted into `provenance_urls` prior to URL stripping so the ad-link-density and banned-domain rules can scan them during moderation without re-parsing. The denoiser also counts ad-classed `<a>` elements into `ad_link_count` so `RuleEvaluator::adLinkDensity` can compute per-1 000-character density in one pass.

---

## 3. Language Detection Without Third-Party Services

**Question:** The prompt requires a detected `language` as an ISO code on every normalized record and constrains the entire system to fully offline operation in Docker on a single host. Commercial language detection is typically a remote call or a multi-megabyte model shipped alongside the binary. How does the platform produce a language code without either?

**My Understanding:** Short of shipping a heavy statistical model, a practical offline approach is to combine Unicode-block heuristics (which are near-deterministic for CJK/Hangul/Arabic scripts) with a small stop-word scoring routine for common Latin-script languages. When neither approach yields a confident answer, the API should hold back rather than guess — and an explicit caller override should be supported for privileged roles that are genuinely in a position to assert the language by hand.

**Solution:** `LanguageDetector` in `src/Domain/Content/Parsing/LanguageDetector.php` scores Unicode blocks first (CJK, Hangul) and then applies frequency-weighted stop-word scoring for EN/ES/FR/DE/IT/PT/NL; the detector returns `und` when nothing scores. The confidence threshold is configurable (default 0.75 in `config/app.php → parsing.language_confidence_threshold`). When the detected confidence falls below the threshold, `NormalizationPipeline::normalize` raises a 422 `VALIDATION_ERROR` unless the caller both holds the `content.language_override` permission and supplied `language` in the request body — in that case the override wins and confidence is recorded as 1.0. No language model file is bundled with the image; the detector runs entirely from in-memory stop-word tables.

---

## 4. Section Tag Canonicalization and the 10-Tag Cap

**Question:** The prompt caps `section_tags` at 0–10 and treats them as a first-class normalized field, but does not specify how tags collected from different upstream feeds are reconciled. "News" and "news" and "news-section" would otherwise inflate both storage and analytics slice cardinality.

**My Understanding:** Tags should be lowercased, non-alphanumeric runs collapsed to hyphens, case-insensitively deduplicated, and capped at the first 10 surviving tags. This keeps analytics groupings deterministic and prevents trivially equivalent tags from being treated as distinct.

**Solution:** `SectionTagNormalizer::normalize` in `src/Domain/Content/Parsing/SectionTagNormalizer.php` lowercases every tag, replaces any run of non-letter/number characters with a single hyphen, trims stray hyphens, case-insensitively deduplicates, and truncates to `parsing.section_tags_max` (10). The normalized array is what `ContentService::ingest` persists on the `contents.section_tags` column so downstream analytics filter on canonical values, not caller-provided strings.

---

## 5. Content Ingest Idempotency Key Choice

**Question:** The PRD requires a mapping table from `source_record_id` to `content_id` to prevent duplicate analytics, but does not say whether a repeat submission should create a new record, silently no-op, or fault. A feeder that retries on timeout must not produce two `contents` rows for the same upstream item.

**My Understanding:** The natural idempotency key is the `(source_key, source_record_id)` composite. A repeat submission should return the existing content row (with a flag the caller can detect) rather than minting a second `content_id` — that is what `prevent duplicate analytics and downstream contamination` actually requires.

**Solution:** `ContentService::ingest` performs a `content_sources` lookup on `(source_key, source_record_id)` before running the normalization pipeline; when a row is present it short-circuits with `duplicate: true` and returns the existing `Content` without touching the parser. The unique index on `(source_key, source_record_id)` in the `content_sources` migration enforces the invariant at the storage layer. The response payload carries `duplicate` so feeders know whether their retry actually did anything new.

---

## 6. Dedup Fingerprint Composition and Similarity Threshold

**Question:** Cross-source dedup is specified to use "fingerprints (normalized title + creator/author + duration when present) and fuzzy similarity thresholds (e.g., title similarity ≥ 0.92)," but leaves open the normalization rules, which similarity metric to use, and what to do with borderline pairs between 0.85 and 0.92 or with conflicting authors on otherwise-identical titles.

**My Understanding:** Title and author normalization should be identical (lowercase, punctuation stripped, whitespace collapsed) so the two axes compose. Jaro-Winkler is the conventional metric for titles because it rewards a matching prefix — which is what editorial near-duplicates tend to have. Pairs above a high threshold with no author conflict should auto-merge; pairs in a middle band, or with an explicit author conflict, should go to human review rather than silently collapsing.

**Solution:** `FingerprintService` in `src/Domain/Dedup/FingerprintService.php` normalizes both title and author with `mb_strtolower` + `preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', ...)` + whitespace collapse. `composite_fingerprint = sha256(title_norm || "\u001f" || author_norm || "\u001f" || duration)`. Similarity uses Jaro-Winkler on normalized titles. `DedupService::recompute` classifies pairs with `sim ≥ dedup.auto_merge_similarity` (0.92) and no author conflict as `auto_mergeable`; pairs with `sim ≥ dedup.review_similarity_min` (0.85) or any author conflict go to `pending_review` in the `dedup_candidates` table so a reviewer with `content.merge` or `moderation.review` can adjudicate through `/api/v1/dedup/merge`.

---

## 7. Unmerge Authority and Reattachment Invariant

**Question:** The prompt requires a mapping table preventing duplicate contamination, but does not specify whether merges can be reversed, who may reverse them, or how the mapping table is rewired if they are. A naive unmerge that just drops `merged_into_content_id` would leave every previously reattached `content_source` pointing at a record whose body has since diverged.

**My Understanding:** Unmerge should be administrator-gated because it is a structural rewrite of analytics history. When a record is unmerged, only `content_sources` whose `original_checksum` matches the unmerged record's `body_checksum` should be reattached — this prevents re-pointing records whose body drifted from the one originally folded in.

**Solution:** `DedupService::unmerge` requires the administrator role via `Policy::isAdministrator`. It walks `content_merge_history` for the secondary, finds all `content_sources` rows whose `original_checksum` still matches the unmerged record's `body_checksum`, reattaches them, and writes a compensating `content_merge_history` row with `action=unmerge`. `merged_into_content_id` is cleared on the secondary so it re-enters dedup scans on the next recompute.

---

## 8. Rule Pack Lifecycle: Immutability After Publish

**Question:** Rule packs drive automated moderation on every ingest. If a published pack could be edited in place, a content item that was approved against pack v1 might retroactively become flagged against a v1 whose rules have since changed — destroying forensic reproducibility. But operators still need a way to turn off an obsolete pack.

**My Understanding:** Published rule pack versions should be immutable — no rule additions, edits, or deletions. A new version must be drafted and published to change behavior. "Archive" should flip status only, never touch rules. Empty versions should not be publishable because they would take effect instantly and flag nothing, which is an easy operator mistake.

**Solution:** `RulePackService` in `src/Domain/Moderation/RulePackService.php` enforces these invariants: `addRule` fails with `CONFLICT` if the target version is not `draft`; `publishVersion` fails if the target has zero rules (`EMPTY_VERSION` conflict); `archiveVersion` only flips `rule_pack_versions.status` to `archived` and leaves `rule_pack_rules` untouched. Automated moderation evaluates every version whose `status = 'published'`, so flipping to `archived` cleanly removes a pack from the ingest path without mutating history.

---

## 9. Ad-Link Density Formula and Trigger Inequality

**Question:** The PRD pins ad density at "maximum 3 per 1,000 characters." But how is this measured against a body that is, say, 847 characters long with two ad links? Rounding up vs. down, counting the threshold itself vs. only strictly-greater values, and counting against raw vs. normalized body length all change whether that record trips the rule.

**My Understanding:** Density should be computed against the normalized body length (so banned-domain denoising doesn't shift the denominator), expressed as `(ad_link_count / body_length) * 1000`, and should fire only when strictly greater than the threshold — using `>` rather than `>=` prevents boundary noise from misreported counts.

**Solution:** `RuleEvaluator::evaluate` in `src/Domain/Moderation/RuleEvaluator.php` computes `density = (ad_link_count / max(1, body_length)) * 1000` and fires only when `density > threshold`. The default threshold is `moderation.ad_link_density_max = 3.0` from `config/app.php`; individual `rule_pack_rules.threshold` values override per-rule. `body_length` is the UTF-8 character length of the normalized body (`mb_strlen($content->body, 'UTF-8')`), so denoising and URL stripping have already been applied.

---

## 10. SLA Business Hours Definition

**Question:** Reports and appeals carry an SLA target of "initial review within 24 business hours." But "business hours" is a local convention — is the system counting Monday–Friday 09:00–17:00, or 24/7, or the site's own calendar? A report filed at 18:00 Friday has radically different due times depending on the answer.

**My Understanding:** The platform should default to Monday–Friday 09:00–17:00 in local server time, skip weekends, and let operators override via configuration for sites that run different hours. Holidays are an operational concern and should be handled by extending the configured weekend set rather than hard-coding a country-specific calendar.

**Solution:** `ModerationService` receives `sla.business_hours` from `config/app.php`, which defaults to `start='09:00'`, `end='17:00'`, `weekdays=[1,2,3,4,5]`. `computeSlaDueAt` walks forward from the case open time, adding business-hour segments until `moderation_initial_hours` (24) are accumulated, skipping any day whose weekday index is not in the configured set. Operators can override any of these values via environment/config without code change; no holiday calendar is bundled.

---

## 11. Moderation Notes: Private Notes, Append-Only, and Author Sentinel

**Question:** Reviewers need to leave internal notes that are not visible to reporters, and the prompt requires "immutable moderation notes." Without a private/public split, reviewers self-censor in shared notes. Without an explicit immutability guarantee, notes become a surface for rewriting history. Automated system entries also need an author identity but there is no "system user" concept in the auth model.

**My Understanding:** `moderation_notes` should be strictly append-only (no UPDATE/DELETE endpoint), carry an `is_private` flag checked by a permission on read, and accept a sentinel author id (e.g., 0) for automated system-authored notes so we can distinguish them from human reviewers without inventing fake users.

**Solution:** The `moderation_notes` table has `case_id`, `author_user_id`, `note`, `is_private`, `created_at` and no `updated_at`. `ModerationService` offers `addNote`/`listNotes` only; no edit/delete path exists. `listNotes` filters private rows unless the caller holds `moderation.view_private_notes`. `AutomatedModerator::moderate` writes its summary note with `author_user_id = 0` — the sentinel for system-authored entries, which the listing serializer renders as "system."

---

## 12. Appeal Uniqueness and Authorization Probe

**Question:** The PRD treats appeals as first-class but does not specify (a) whether multiple appeals can sit open on the same case simultaneously or (b) whether an unauthorized caller submitting against a non-existent/unresolved case learns anything about its state. The second point is a subtle information-leak risk.

**My Understanding:** Only one active appeal per case should be allowed — otherwise, any resolution becomes ambiguous. Authorization should be checked strictly before any eligibility/state probes so an unauthorized caller cannot distinguish "case doesn't exist" from "case is not resolved yet" from "appeal already active."

**Solution:** `moderation_cases.has_active_appeal` is set when an appeal is opened and cleared only when `resolveAppeal` writes a new `moderation_decisions` row (the original decision is never overwritten). In `ModerationService::submitAppeal`, `Policy::canSubmitAppeal` is called first — only administrators, the original reporter (`moderation_reports.reporter_user_id`), the content creator (`contents.created_by_user_id`), or a user with a `scope_type='content'` binding matching the case's `content_id` pass. Only after authorization succeeds does the service check `status = 'resolved'` (else 409 `CASE_NOT_RESOLVED`) and `has_active_appeal = false` (else 409 `APPEAL_ACTIVE`). Unauthorized attempts also record a `moderation.appeal_denied` audit entry.

---

## 13. Event Draft Optimistic Locking

**Question:** Event drafts are inherently long-lived objects that multiple editors may update concurrently. A last-write-wins model silently clobbers earlier edits; a pessimistic lock is overkill for a single-host tool. The PRD is silent on the conflict model.

**My Understanding:** Drafts should carry a monotonically-increasing `draft_version_number` and mutating requests must supply `expected_draft_version_number`. A mismatch returns a distinct conflict code so clients can surface a "someone else updated this draft" message rather than a generic error.

**Solution:** The `event_versions` table includes `draft_version_number`. `EventService::updateDraft` compares the caller-supplied `expected_draft_version_number` against the current row value inside a `lockForUpdate`; mismatches throw `ConflictException` with code `DRAFT_LOCK_CONFLICT` (409). Successful updates atomically bump `draft_version_number`. Published versions reject any edit regardless of the lock field.

---

## 14. Effective-Window Overlap Between Active Event Versions

**Question:** The event lifecycle spec allows multiple publications over time with `effective_from`/`effective_to`, but does not say whether two publications of the *same* event can overlap. Overlapping active versions create ambiguity for every downstream query ("which rule set applied at timestamp T?").

**My Understanding:** At most one publication of a given event should be effective at any given moment. Publishing a new version whose window overlaps an active window of the same event should hard-fail so the operator must either bound the earlier window first or explicitly roll back.

**Solution:** `EventService::publishVersion` checks for any other publication of the same event whose `[effective_from, effective_to]` intersects the incoming window; a hit returns 409 `EFFECTIVE_OVERLAP`. Rollback is the other mutation path — `rollbackVersion` records an `event_publications` row with `action=rollback` and reactivates a prior published version instead of duplicating the target. Published versions are themselves never edited: the `config_snapshot_json` frozen at publish time is the source of truth.

---

## 15. Event Rule Defaults: Check-In Windows and Attempt Limits

**Question:** The prompt spells out specific defaults (check-in opens 60 minutes prior, late cutoff 10 minutes after start, attempt limit 3 unless overridden) but does not say where those values live — hard-coded in the service, attached to templates, or per-version overrides.

**My Understanding:** The defaults should be baked into event templates so an operator creating, say, an "individual" event inherits them automatically, but every draft version should be able to override any of the three in its own rule set before publish. Overrides must not reach back to mutate the template row.

**Solution:** `event_templates` seeds carry `default_rule_set` as JSON with `checkin_open_minutes_before_start=60`, `late_checkin_cutoff_minutes_after_start=10`, `attempt_limit=3`. `EventService::createDraftVersionInternal` copies the template's defaults into a new `event_rule_sets` row associated with the draft version; subsequent `PATCH /events/{id}/versions/{versionId}` requests targeting `rule_set` write to that row, leaving the template untouched. Publish snapshots the rule set into `event_versions.config_snapshot_json`, making overrides immutable once the version is live.

---

## 16. At-Rest Encryption: Algorithm, Envelope, and Key Rotation

**Question:** The prompt requires AES-256 encryption at rest for secrets and security answers with a locally managed key, but does not pin the mode, specify IV/nonce handling, or prescribe a rotation path. ECB would technically satisfy AES-256 but is catastrophically insecure; static IVs reopen the door to the same failure modes.

**My Understanding:** AES-256-GCM with a per-ciphertext random 12-byte IV and a 16-byte auth tag is the right baseline — it provides authenticated encryption and detects ciphertext tampering without requiring a separate HMAC. Rotation needs a versioned envelope so old ciphertexts stay readable after a new key goes live, without a big-bang re-encrypt pass.

**Solution:** `AesGcmCipher` in `src/Infrastructure/Crypto/AesGcmCipher.php` wraps OpenSSL with `aes-256-gcm`, a fresh 12-byte IV per call (`random_bytes(12)`), and a 16-byte tag. The envelope is `v<version>:base64(iv || tag || cipher)`. Previous keys are supplied via `APP_PREVIOUS_KEYS=ver:hex,...`; `decrypt()` dispatches on the envelope's version prefix and falls back to whichever historical key matches, while `encrypt()` always uses the current `APP_MASTER_KEY` + `APP_MASTER_KEY_VERSION`. The `composer key:rotate` script re-encrypts security answers in place once an operator has promoted a new current version, keeping forward progress during rotation.

---

## 17. Password Hashing, Minimum Length, and Account Status Enum

**Question:** The prompt specifies bcrypt but not the cost factor, minimum password length, or how the account lifecycle should differentiate between "fresh from signup," "locked by failures," "forced to reset," and "administratively disabled." A global "active/disabled" binary collapses states that need different API responses.

**My Understanding:** Bcrypt cost 12 is the current recommended floor. A 12-character minimum password length balances usability against the brute-force floor. A richer status enum (`pending_activation`, `active`, `locked`, `password_reset_required`, `disabled`) captures the real lifecycle without inventing parallel boolean columns.

**Solution:** `PasswordHasher` uses bcrypt with cost 12 via `password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])`. `AuthService::signup` enforces a ≥ 12-character minimum. `users.status` is an enum column with exactly the five values above; authentication paths differentiate `locked` (lockout window active) from `disabled` (administrative action) from `password_reset_required` (reset-in-flight) when producing the 401/403 response.

---

## 18. Session Token Format Without Plaintext at Rest

**Question:** Bearer tokens have to be returned to the client as a single string, but a database breach that exposes raw bearer tokens lets an attacker take over every active session. The prompt does not specify the session token format.

**My Understanding:** The token should be an opaque `<session_id>.<raw_secret>` pair; the DB should only store `sha256(raw_secret)` as `token_hash`. On resolve, the server looks up the session id and constant-time compares the SHA-256 of the presented secret against the stored hash. A DB read no longer yields usable tokens.

**Solution:** `SessionService::issue` generates a UUID `session_id` and a 32-byte random secret (`bin2hex(random_bytes(32))`). The token returned to the client is `session_id . "." . raw_secret`. Only `sha256($rawSecret)` is persisted in `user_sessions.token_hash`. `SessionService::resolve` splits the presented token, looks the row up by `session_id`, and compares via `hash_equals` to defeat timing attacks. IP addresses stored alongside the session row are AES-256-GCM encrypted so unmasking is permission-gated.

---

## 19. Session TTLs and Concurrent-Session Policy

**Question:** "Session timeout" is not specified, nor is whether a user should be able to maintain an unlimited number of concurrent sessions. Without an absolute ceiling, a leaked refresh-on-activity session can live indefinitely; without a concurrency ceiling, a compromised credential can mint an unbounded number of tokens.

**My Understanding:** Sessions should have both an absolute ceiling (e.g., 12 hours) and a shorter idle timeout (e.g., 2 hours) so inactivity closes the door while a legitimately active user's day is not interrupted. A per-user concurrent cap (e.g., 5) stops credential replay at scale, and password reset should wipe all active sessions.

**Solution:** `SessionService` is constructed with `SESSION_ABSOLUTE_TTL=43200` (12 h), `SESSION_IDLE_TTL=7200` (2 h), and `SESSION_MAX_CONCURRENT=5` (see `config/app.php → session`). `issue()` calls `enforceConcurrentLimit` which revokes the oldest active session when the new total would exceed the cap. Every resolve refreshes `last_seen_at`/`idle_expires_at`. `AuthService::completeReset` revokes all active sessions of the target user atomically with the password update so a compromised credential's tokens die on reset.

---

## 20. Login and Reset Lockout: Thresholds and Isolation

**Question:** The prompt is silent on the specific lockout thresholds. A single threshold that conflates login and password-reset brute force makes it too easy for an attacker to DoS a real user by spamming reset attempts, and too easy for a credential stuffer to hide among legitimate login noise.

**My Understanding:** Login and reset should have independent counters with their own windows and their own lock durations. Login: 5 failures / 15 min → 30 min account lock. Reset: 5 failures / 30 min → 60 min reset-specific lock. Separate columns (`locked_until`, `reset_locked_until`) keep the two lockouts from cross-contaminating.

**Solution:** `LockoutPolicy` pulls thresholds from `config/app.php → lockout`: login has `login_failures_threshold=5`, `login_window_seconds=900`, `login_lock_seconds=1800`; reset has `reset_failures_threshold=5`, `reset_window_seconds=1800`, `reset_lock_seconds=3600`. `users.locked_until` and `users.reset_locked_until` are distinct datetime columns; `login_attempts` and `password_reset_attempts` record per-try results so counting is done against DB history rather than an in-memory counter (which would reset on container restart).

---

## 21. Password Reset: One-Time Tickets Without Email/SMS

**Question:** The PRD specifies "password reset via security questions (no email/SMS)," which rules out the usual email-link pattern. But a naive "answer questions → get new password" flow in a single step has no protection against replay: once an attacker sees a valid answer set, they can reuse it freely until the user rotates their questions. The two-step model the industry uses relies on an out-of-band channel we don't have.

**My Understanding:** The flow should still split into begin/complete, but use a short-lived, one-time, server-persisted ticket instead of an email link. Begin returns a ticket and the security prompts. Complete validates the ticket (bound to user, not consumed, not revoked, not expired, constant-time hash compare) before even checking the answers. All failure modes should collapse to a single opaque 401 so an attacker cannot distinguish "wrong ticket" from "wrong answers" from "ticket expired."

**Solution:** `/auth/password-reset/begin` calls `AuthService::beginReset`, which mints a raw ticket secret, persists `sha256(ticket_secret)` to `password_reset_tickets` with `ttl_seconds=900` (15 min), revokes any prior active ticket for that user, and returns the raw value once. `/auth/password-reset/complete` hashes the incoming raw ticket, looks it up by user+hash with a `hash_equals` constant-time compare, and requires `consumed_at IS NULL AND revoked_at IS NULL AND expires_at > NOW()`. Any failure throws `AuthenticationException` → 401 `AUTHENTICATION_REQUIRED`, identical for all causes. On success the password swap, ticket consumption, and session revocation all happen inside a single DB transaction.

---

## 22. Rate Limiting: Bucket Strategy and System Account Bypass

**Question:** "Per user 60 requests/minute" leaves ambiguous how unauthenticated traffic is bucketed, whether two users behind one NAT contend, and what happens when a system account (ingest worker, scheduled report) legitimately bursts far above the 60-rpm ceiling. Bucketing purely per-IP collapses legitimate multi-user traffic; bucketing per-user without a fallback blanks out anonymous paths.

**My Understanding:** Authenticated human users should bucket on `u:<user_id>` so colocated users don't collide. Unauthenticated traffic should bucket on `ip:<REMOTE_ADDR>:<route_path>` so `/auth/login` traffic doesn't compete with `/health`. System accounts (flagged in the schema) should bypass the limiter entirely — if they are mis-scheduled, that's an operator problem, not a rate-limit one. For any of this to work, rate limiting must run *after* authentication so the user attribute has already been resolved.

**Solution:** The middleware chain in `AppFactory` is `ErrorResponse → RequestId → Auth → RateLimit → route`. `RateLimitMiddleware::bucketKey` returns `u:<id>` for `User` instances, `ip:<REMOTE_ADDR>:<path>` otherwise; it short-circuits to bypass when `user.is_system === true`. Counters live in `rate_limit_windows` keyed on `(bucket_key, window_start)` and reset on a 60-second boundary; the 429 `RATE_LIMITED` response includes `Retry-After`. The default limit is `rate_limit.default_per_minute=60` (from `config/app.php`).

---

## 23. Analytics Ingest Idempotency: Within-Window vs Post-Expiry Reuse

**Question:** The prompt rejects duplicate idempotency keys within 24 hours but says nothing about what happens afterward. A long-lived client that always reuses the same key (`"daily-rollup-2026-04-17"`) expects that key to become usable again on the next cycle. But if the cleanup job hasn't run, the stale row is still sitting there, and the naive "unique index" approach would block the legal reuse.

**My Understanding:** Duplicates within the 24-hour window return 409 unconditionally. After the window expires, the same key must become reusable *atomically* — a correct implementation row-locks the existing key inside the ingest transaction, re-checks the 24-hour window, and replaces the stale row with the fresh insert. The cleanup job is then a cost-of-storage optimization, not a correctness dependency.

**Solution:** `AnalyticsService::ingest` wraps the idempotency check in `DB::transaction`: it runs `SELECT ... FROM analytics_idempotency_keys WHERE key = ? FOR UPDATE`, re-applies the `analytics.idempotency_window_hours` (24) window check against the row's `inserted_at`, and — when the existing row is outside the window — deletes it before inserting the fresh `analytics_events` row and a new `analytics_idempotency_keys` row. Within-window duplicates throw `ConflictException` with code `ANALYTICS_DUPLICATE`. `analytics.idempotency_cleanup` still runs hourly to prune aged rows, but ingest correctness does not depend on it.

---

## 24. Dwell-Seconds Cap: Bounding Session Pathologies

**Question:** The prompt caps `dwell_seconds` at 4 hours per session but doesn't say whether to reject over-cap values, truncate them, or pass them through. Each has different downstream effects: reject shifts the burden to the feeder; truncate hides pathological sessions; pass-through lets a stuck tab inflate cohort averages.

**My Understanding:** Silent clamp to `[0, 14400]` during ingest is the right compromise. Values outside the range are almost always instrumentation bugs (negative clocks, left-open tabs) and blocking the entire event for that reason would hurt analytics coverage more than clamping does. The cap is logged via audit so outliers are still traceable.

**Solution:** `AnalyticsService::ingest` clamps incoming `dwell_seconds` with `max(0, min(dwell_cap_seconds, $value))` where `dwell_cap_seconds = analytics.dwell_cap_seconds = 14400`. Clamped values are stored on `analytics_events.dwell_seconds` and used directly by rollups; the raw submitted value is not persisted separately because the cap is treated as definitional, not as an anomaly marker.

---

## 25. Sensitive-Field Masking in Query Responses

**Question:** The prompt requires that "sensitive fields (emails if stored, IPs) must be masked in query responses based on permission scopes." The underlying storage is encrypted, but the act of decrypting and returning plaintext to any caller with analytics-read access would defeat the at-rest encryption design.

**My Understanding:** A default read path should return `[masked]` for IP/email regardless of the caller. Unmasking should require an explicit, auditable permission — not just "analytics read." An export download that contains unmasked values should additionally require the same permission *and* emit its own audit entry, so forensic review can reconstruct exactly who pulled unmasked data.

**Solution:** `AnalyticsService::query` and the serializers in `src/Http/Responses` return `[masked]` for `ip_address`/`email` fields unless the caller holds `analytics.view_unmasked` or `sensitive.unmask` (checked via `Policy::hasCapability`). `ReportService::download` requires `governance.export_reports`; unmasked exports additionally require `sensitive.unmask` and emit `reports.export_downloaded` to the audit log. Ciphertext never leaves `AesGcmCipher::decrypt` unless the permission gate is cleared.

---

## 26. Report Output Formats: CSV/JSON vs Bundled PDF

**Question:** "Scheduled report generation stored locally as files with retention of 90 days" is ambiguous on format. PDF generation offline requires bundling a non-trivial PDF library and fonts into the image, which conflicts with the "no third-party network dependencies" constraint if we weren't careful. CSV and JSON can be produced deterministically from the same query rows.

**My Understanding:** Ship CSV and JSON on day one; both are deterministic and require zero external libraries. PDF is deferred — it's a post-launch addition that slots in without breaking the existing API contract because `output_format` is already an enum.

**Solution:** `ReportService::createScheduled` accepts `output_format ∈ {'csv', 'json'}` only; any other value returns 422. The scheduled_reports.output_format enum and generated_reports output_format columns hold the same set. The generation path in `ReportService::generate` writes to a temp file under `config.report_root` (`storage/reports/`), computes `sha256` of the bytes, and atomically renames to the final `report_files` path — identical for both formats. A PDF formatter can be added as a new enum value later without a schema migration breaking existing rows.

---

## 27. Report Retention: Physical-File Lifecycle at 90 Days

**Question:** "Retention of 90 days" for generated reports leaves open whether we retain DB metadata, the file on disk, or both — and whether expiry is driven by a scheduled job, a read-time check, or a filesystem-level rule.

**My Understanding:** DB row and physical file should expire together. A daily job is the right abstraction so retention survives container restarts (a read-time check would silently skip reports nobody tried to read). Each expiry should emit an audit entry so governance can later demonstrate the retention policy actually ran.

**Solution:** `generated_reports` carries `expires_at = generated_at + retention.generated_reports_days (90)` at creation. The `reports.retention_cleanup` job (`15 2 * * *` in the default job definitions) walks rows where `expires_at < now() AND status = 'available'`, unlinks the file from `storage/reports/`, flips `status = 'expired'`, and writes `reports.retention_cleaned` to the audit log. If unlink fails the row is left `available` and the next run retries so filesystem transients don't produce orphans.

---

## 28. Audit Trail: Per-Row Hash Chain and Daily Finalization

**Question:** "Tamper-evident audit trails" is under-specified. A plain append-only log is tamper-evident only to the extent that an attacker with DB write access cannot rewrite history without detection. Hashing each row against the previous one creates chain-level tamper evidence, but an attacker can still rewrite the whole chain if nothing pins it down periodically.

**My Understanding:** Every audit row should carry `row_hash = sha256(previous_row_hash || occurred_at || actor || action || object || payload)`, so altering any row breaks every subsequent hash. On top of that, a daily job should seal the previous day's chain by committing a final hash (plus the prior day's seal) to a separate `audit_hash_chain` table. That table becomes the durable fingerprint — rewriting history now requires rewriting every sealed day as well, which becomes conspicuous because operators can spot-check.

**Solution:** `AuditLogger::record` in `src/Domain/Audit/AuditLogger.php` performs the write inside `DB::transaction`: it selects the most recent row `FOR UPDATE`, computes `row_hash = sha256(prev_row_hash || iso_ts || actor_type || actor_id || action || object_type || object_id || payload_json)`, and inserts the new row with `previous_row_hash` set. The `audit.finalize_daily_chain` job (`0 1 * * *`) walks the previous day's rows, computes the terminal hash including the prior `audit_hash_chain.final_hash`, and commits a new sealed row. The job refuses to seal day N when day N-1 is missing but earlier records exist, so a gap can't silently hide tampering.

---

## 29. Centralized Object-Level Authorization

**Question:** "RBAC must enforce object-level authorization for content items, moderation cases, event definitions, and protected analytics views, with explicit deny-by-default for write operations." A per-service permission check pattern (copy-pasted across 12 services) is guaranteed to drift — one service's ownership fallback will disagree with another, and the list of writable terminal states will get out of sync.

**My Understanding:** Object-level authorization should live in a single `Policy` service that every controller and service calls. Scope types should be enumerated explicitly (`global`/null, `content`, `event_family`, `moderation_reviewer`, `report`); administrators should have global scope everywhere; and ownership shortcuts should be whitelisted narrowly — creators may edit their own non-terminal content, and event draft creators may keep editing their own drafts — so the surface for privilege-escalation bugs stays small.

**Solution:** `Meridian\Domain\Authorization\Policy` is the single decision point. Every `can*` method starts with a capability check through `UserPermissions::hasPermission`, then resolves scope from `user_role_bindings.scope_type/scope_ref`. `isAdministrator` short-circuits to true for administrators. Ownership shortcuts are narrow: `canEditContent` allows the creator only when `risk_state ∈ ('normalized', 'flagged', 'under_review')`; `canEditEventVersion` allows the draft creator while status remains `draft`. Listing APIs call `filterContentIds`/`filterEventIds`/`filterModerationCaseIds` before serializing so unauthorized rows never appear in pagination pages.

---

## 30. Centralized Blacklist Enforcement at the Middleware Gate

**Question:** A `user` blacklist that requires every service to re-check before every operation is guaranteed to miss a path — adding a new endpoint requires remembering to add the check. And tokens issued prior to the blacklist entry continue to work until the session's idle timeout, which is far too long.

**My Understanding:** Centralize the `user` blacklist check in `AuthMiddleware` so it runs once per request, immediately after session resolution. Every route behind the middleware becomes blacklist-aware by construction — adding a new controller gets the protection automatically. Existing tokens stop working on the very next request rather than waiting for a timeout. Public auth paths (login, signup, password reset, health) stay exempt so an administrator can still log in to manage the list.

**Solution:** `AuthMiddleware::process` in `src/Application/Middleware/AuthMiddleware.php` calls `BlacklistService::isBlacklisted('user', (string)$user->id)` after resolving the session. A hit short-circuits with `ApiException('USER_BLACKLISTED', 403)` and emits `auth.blacklist_denied` to the audit log with the request path+method. `PUBLIC_PATHS` — `/auth/signup`, `/auth/login`, `/auth/security-questions`, `/auth/password-reset/begin`, `/auth/password-reset/complete`, `/health` — bypass both auth and the blacklist gate so operators can always get in to revoke the entry. `content` and `source` blacklist checks remain at their respective ingest entry points because they are object-level, not actor-level.

---

## 31. Runtime Stack, Offline Proxy, and Local Scheduler

**Question:** "Slim to structure resource-oriented APIs and Eloquent with MySQL for persistence, ensuring all processing runs fully offline in Docker on a single host" fixes the top-level stack but leaves open whether a reverse proxy should ship, how migrations are run in an offline environment, how scheduled jobs fire without an external cron provider, and which test framework backs the guarantees.

**My Understanding:** A reverse proxy adds operational surface area without a clear benefit at the single-host scale the prompt targets — Slim can serve HTTP directly on 8080, and any operator who wants TLS termination can bolt one on without code changes. Phinx is the natural migration tool for a PHP stack because it runs fully offline as a PHP-native library. The scheduler should be a second container running a simple loop that evaluates cron expressions against a `job_definitions` table — this replaces an external cron provider with code we can test. PHPUnit 10 is the test framework expected by a PHP 8.2 codebase of this size.

**Solution:** `docker-compose.yml` runs three services on one bridge network: `mysql` (8.0), `app` (Slim on 8080, no proxy), and `scheduler` (`scripts/scheduler_loop.php`, 30-second tick). Migrations run via `composer migrate` (Phinx); seeds run via `composer seed`. `Meridian\Infrastructure\Cron\CronExpression` is a minimal offline cron evaluator — no `crontab -l`, no external scheduler. PHPUnit 10 drives unit + integration suites; integration tests run against an in-memory SQLite database with `tests/Integration/SchemaBuilder.php` mirroring Phinx migrations, so the full Slim middleware + policy + persistence stack is covered end-to-end in CI without needing a live MySQL.

---

## 32. Admin Surface and Bootstrap Administrator

**Question:** "Multi-role Account and Access APIs" is silent on whether a UI ships, and any RBAC system that cannot log in its first administrator is unusable. A self-bootstrapping "first user becomes admin" rule is a well-known class of vulnerability (any attacker who hits the endpoint before the legitimate operator wins).

**My Understanding:** A SPA is out of scope for this drop — the APIs and a small set of CLI scripts are sufficient for operator workflows, and removing a frontend removes a substantial attack surface. The bootstrap administrator should be created explicitly by an operator with controlled credentials via a CLI, not via a race-susceptible first-run endpoint. The operator is expected to rotate the password on first real login.

**Solution:** No SPA ships. `src/Http` exposes admin endpoints (see `UserAdminController`) and `scripts/` provides CLI utilities sufficient for day-to-day operator work. `composer admin:bootstrap` runs `scripts/admin_bootstrap.php` which reads `BOOTSTRAP_ADMIN_USERNAME`/`BOOTSTRAP_ADMIN_PASSWORD` from env, creates the user with the `administrator` role + global scope binding, and writes `auth.bootstrap_admin_created` to the audit log. There is no "first HTTP request wins admin" path — the bootstrap fails loudly (does not create a user) if those env vars are unset.
