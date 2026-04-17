# audit_report-2 Fix Check (Recheck)

Source reviewed: `.tmp/audit_report_2.md`.

## Verification boundary
- Static review only.
- No runtime execution (no app startup, tests, Docker).

## Recheck results

| Previous issue | Prior severity | Current status | Evidence | Conclusion |
|---|---|---|---|---|
| Unit test constructor mismatch in SLA test (`ModerationService` dependency drift) | Medium | **Fixed** | `src/Domain/Moderation/ModerationService.php:31` requires `Policy`; test now injects `new Policy()` via helper at `tests/Unit/Moderation/SlaDeadlineTest.php:51` and `tests/Unit/Moderation/SlaDeadlineTest.php:61` | Constructor parity restored for this test |
| Authorization docs overstate route behavior | Low | **Fixed** | Docs now explicitly split business-domain protected routes vs auth self-service/session routes at `docs/API.md:16` and `docs/API.md:26`, with controller behavior references matching `src/Http/Controllers/AuthController.php:101` and `src/Http/Controllers/AuthController.php:164` | Documentation now aligned with implementation semantics |

## Summary
- Fixed: **2 / 2** previously reported issues
- Remaining from prior list: **0 / 2**

## Note
- This confirms only the previously listed items in `audit_report_2.md`. It is not a full new audit.
