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

