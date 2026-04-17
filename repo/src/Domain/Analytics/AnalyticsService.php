<?php

declare(strict_types=1);

namespace Meridian\Domain\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\ConflictException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Domain\Authorization\Policy;
use Meridian\Domain\Blacklist\BlacklistService;
use Meridian\Infrastructure\Clock\Clock;
use Meridian\Infrastructure\Crypto\Cipher;

/**
 * Analytics ingestion, idempotency enforcement, query primitives, and funnel support.
 *
 * Required fields for ingestion:
 *   actor_id OR system identity + event_type + object_type + object_id + occurred_at + idempotency_key
 * dwell_seconds is clamped to [0, dwell_cap_seconds] (default 14400 = 4 hours).
 * Duplicate idempotency_key within window returns 409 with deterministic error body.
 */
final class AnalyticsService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Cipher $cipher,
        private readonly AuditLogger $audit,
        private readonly array $config,
        private readonly Policy $policy,
        private readonly BlacklistService $blacklist,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array{duplicate:bool, event:AnalyticsEvent}
     */
    public function ingest(array $input, ?User $actor, ?string $ipAddress = null): array
    {
        if (!$actor instanceof User) {
            throw new AuthorizationException('Authentication required to ingest analytics.');
        }
        if (!$this->policy->canIngestAnalytics($actor)) {
            throw new AuthorizationException('Missing permission: analytics.ingest');
        }
        $now = $this->clock->nowUtc();
        $idem = (string) ($input['idempotency_key'] ?? '');
        if ($idem === '') {
            throw new ValidationException('idempotency_key is required.', ['field' => 'idempotency_key']);
        }
        $actorType = $actor instanceof User ? 'user' : (isset($input['actor_type']) ? (string) $input['actor_type'] : 'anonymous');
        $actorId = $actor instanceof User ? (string) $actor->id : (isset($input['actor_id']) ? (string) $input['actor_id'] : null);
        $eventType = (string) ($input['event_type'] ?? '');
        $objectType = (string) ($input['object_type'] ?? '');
        $objectId = (string) ($input['object_id'] ?? '');
        if ($eventType === '' || $objectType === '' || $objectId === '') {
            throw new ValidationException('event_type, object_type, object_id are required.');
        }
        if ($actorType !== 'anonymous' && ($actorId === null || $actorId === '')) {
            throw new ValidationException('actor_id required when actor_type is not anonymous.');
        }
        // Fix C: analytics ingest for blacklisted content is rejected so downstream
        // aggregates, rollups, and funnels cannot be seeded with events that would
        // then be filtered out at read time.
        if ($objectType === 'content' && $this->blacklist->isBlacklisted('content', $objectId)) {
            $this->audit->record('analytics.ingest_blocked_blacklisted_content', 'content', $objectId, [
                'event_type' => $eventType,
                'idempotency_key' => $idem,
            ], actorType: $actor instanceof User ? 'user' : 'system', actorId: $actor instanceof User ? (string) $actor->id : 'system');
            throw new ConflictException('Content is blacklisted.', 'BLACKLISTED_CONTENT');
        }
        $occurredAt = isset($input['occurred_at']) ? new DateTimeImmutable((string) $input['occurred_at']) : $now;
        $occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
        $dwell = isset($input['dwell_seconds']) ? (int) $input['dwell_seconds'] : null;
        if ($dwell !== null) {
            $dwell = max(0, min($dwell, (int) $this->config['dwell_cap_seconds']));
        }
        $properties = isset($input['properties']) && is_array($input['properties']) ? $input['properties'] : [];

        // Round-3 Fix D: idempotency reuse semantics.
        //   - inside the protected window -> reject (ANALYTICS_DUPLICATE, 409)
        //   - outside the protected window -> the stale row must NOT block a legal reuse.
        // A row that exists but is past its `expires_at` is dropped atomically inside the
        // insertion transaction below so duplicate protection and PK integrity both hold.
        // The pre-transaction check only surfaces an early 409 for live duplicates; it does
        // not block the expired-reuse path.
        $existing = AnalyticsIdempotencyKey::query()->where('idempotency_key', $idem)->first();
        if ($existing instanceof AnalyticsIdempotencyKey) {
            if ($existing->expires_at !== null && new DateTimeImmutable((string) $existing->expires_at) > $now) {
                throw new ConflictException('Duplicate analytics idempotency_key.', 'ANALYTICS_DUPLICATE', [
                    'idempotency_key' => $idem,
                    'first_seen_at' => $existing->first_seen_at,
                ]);
            }
        }

        $expiresAt = $now->modify('+' . (int) $this->config['idempotency_window_hours'] . ' hours');

        return DB::connection()->transaction(function () use ($input, $actorType, $actorId, $eventType, $objectType, $objectId, $occurredAt, $dwell, $properties, $idem, $now, $expiresAt, $ipAddress): array {
            // Drop any expired row for this key before inserting so reuse after the
            // 24-hour window is deterministic even though `idempotency_key` is the PK.
            // A fresh within-window check inside the transaction also protects against
            // the (very narrow) race where two requests arrive microseconds apart.
            $prior = AnalyticsIdempotencyKey::query()
                ->where('idempotency_key', $idem)
                ->lockForUpdate()
                ->first();
            if ($prior instanceof AnalyticsIdempotencyKey) {
                if ($prior->expires_at !== null && new DateTimeImmutable((string) $prior->expires_at) > $now) {
                    throw new ConflictException('Duplicate analytics idempotency_key.', 'ANALYTICS_DUPLICATE', [
                        'idempotency_key' => $idem,
                        'first_seen_at' => $prior->first_seen_at,
                    ]);
                }
                AnalyticsIdempotencyKey::query()->where('idempotency_key', $idem)->delete();
            }

            /** @var AnalyticsEvent $evt */
            $evt = AnalyticsEvent::query()->create([
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                'received_at' => $now->format('Y-m-d H:i:s'),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'session_id' => isset($input['session_id']) ? (string) $input['session_id'] : null,
                'event_type' => mb_substr($eventType, 0, 64),
                'object_type' => mb_substr($objectType, 0, 64),
                'object_id' => mb_substr($objectId, 0, 128),
                'dwell_seconds' => $dwell,
                'idempotency_key' => mb_substr($idem, 0, 128),
                'properties_json' => json_encode($properties),
                'role_context' => isset($input['role_context']) ? mb_substr((string) $input['role_context'], 0, 64) : null,
                'language' => isset($input['language']) ? mb_substr((string) $input['language'], 0, 8) : null,
                'media_source' => isset($input['media_source']) ? mb_substr((string) $input['media_source'], 0, 16) : null,
                'section_tag' => isset($input['section_tag']) ? mb_substr((string) $input['section_tag'], 0, 64) : null,
                'ip_address_ciphertext' => $ipAddress !== null && $ipAddress !== '' ? $this->cipher->encrypt($ipAddress) : null,
            ]);
            AnalyticsIdempotencyKey::query()->create([
                'idempotency_key' => $idem,
                'actor_identity' => $actorId,
                'first_seen_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'analytics_event_id' => (int) $evt->id,
                'status_code' => 201,
                'response_fingerprint' => hash('sha256', (string) $evt->id),
            ]);
            return ['duplicate' => false, 'event' => $evt];
        });
    }

    public function query(User $actor, array $filters, int $page, int $size): array
    {
        if (!$this->policy->canViewProtectedAnalytics($actor)) {
            throw new AuthorizationException('Missing permission: analytics.query');
        }
        $canUnmask = $this->policy->canUnmaskAnalytics($actor);
        $q = AnalyticsEvent::query()->orderByDesc('occurred_at');
        foreach (['event_type', 'object_type', 'object_id', 'actor_type', 'actor_id', 'language', 'media_source', 'section_tag'] as $f) {
            if (!empty($filters[$f])) {
                $q->where($f, (string) $filters[$f]);
            }
        }
        if (!empty($filters['occurred_from'])) {
            $q->where('occurred_at', '>=', (string) $filters['occurred_from']);
        }
        if (!empty($filters['occurred_to'])) {
            $q->where('occurred_at', '<=', (string) $filters['occurred_to']);
        }
        $this->policy->applyAnalyticsScope($actor, $q);
        $this->applyBlacklistExclusion($actor, $q);
        // Targeted out-of-scope request: the caller asked for a specific object_id/object_type
        // pair that the scope filter wipes to zero rows. Surface an explicit denial for
        // auditability instead of returning a silently-empty list.
        if (!$this->policy->isAdministrator($actor)
            && !empty($filters['object_type']) && !empty($filters['object_id'])
        ) {
            if (!$this->userMaySeeAnalyticsObject($actor, (string) $filters['object_type'], (string) $filters['object_id'])) {
                $this->audit->record('analytics.query_denied_out_of_scope', (string) $filters['object_type'], (string) $filters['object_id'], [
                    'actor_user_id' => (int) $actor->id,
                    'event_filters' => $filters,
                ], actorType: 'user', actorId: (string) $actor->id);
                throw new AuthorizationException('Analytics object is outside your scope.');
            }
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $size)->get()->all();
        return [
            'items' => array_map(fn(AnalyticsEvent $e) => $this->formatEvent($e, $canUnmask), $rows),
            'total' => $total,
        ];
    }

    private function userMaySeeAnalyticsObject(User $actor, string $objectType, string $objectId): bool
    {
        if ($this->policy->isAdministrator($actor)) {
            return true;
        }
        if ($objectType === 'content') {
            $visible = $this->policy->visibleContentIdsForAnalytics($actor) ?? [];
            if (!in_array($objectId, $visible, true)) {
                return false;
            }
            return !$this->blacklist->isBlacklisted('content', $objectId);
        }
        // Non-content object types are handled by the scope-clause OR-branch; a narrow
        // object_id filter is authorised if the scope clause would match any rows.
        return (bool) AnalyticsEvent::query()
            ->where('object_type', $objectType)
            ->where('object_id', $objectId)
            ->where(function ($q) use ($actor) {
                $this->policy->applyAnalyticsScope($actor, $q);
            })
            ->exists();
    }

    /**
     * Fix C: excludes analytics events tied to blacklisted content from protected
     * analytics views. Administrators may still see everything for governance.
     */
    private function applyBlacklistExclusion(User $actor, $query): void
    {
        if ($this->policy->isAdministrator($actor)) {
            return;
        }
        $blocked = $this->blacklist->activeTargetKeys('content');
        if ($blocked === []) {
            return;
        }
        $query->where(function ($q) use ($blocked) {
            $q->where('object_type', '!=', 'content')
                ->orWhereNotIn('object_id', $blocked);
        });
    }

    /**
     * Funnel: returns per-stage counts and drop-off. Stages is an ordered list of
     * {event_type, object_type?} matchers. Distinct actor required; overall window bounded.
     * @param array<int,array{event_type:string,object_type?:string}> $stages
     */
    public function funnel(User $actor, array $stages, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (!$this->policy->canViewProtectedAnalytics($actor)) {
            throw new AuthorizationException('Missing permission: analytics.query');
        }
        if (count($stages) < 2) {
            throw new ValidationException('Funnel requires at least 2 stages.');
        }
        $actors = null;
        $results = [];
        foreach ($stages as $i => $stage) {
            $q = AnalyticsEvent::query()
                ->where('event_type', (string) $stage['event_type'])
                ->where('occurred_at', '>=', $from->format('Y-m-d H:i:s'))
                ->where('occurred_at', '<', $to->format('Y-m-d H:i:s'));
            if (!empty($stage['object_type'])) {
                $q->where('object_type', (string) $stage['object_type']);
            }
            if ($actors !== null) {
                $q->whereIn('actor_id', $actors);
            }
            $this->policy->applyAnalyticsScope($actor, $q);
            $this->applyBlacklistExclusion($actor, $q);
            $current = $q->distinct()->pluck('actor_id')->filter()->values()->all();
            $results[] = [
                'event_type' => $stage['event_type'],
                'object_type' => $stage['object_type'] ?? null,
                'distinct_actors' => count($current),
            ];
            $actors = $current;
            if (count($actors) === 0) {
                for ($j = $i + 1; $j < count($stages); $j++) {
                    $results[] = [
                        'event_type' => $stages[$j]['event_type'],
                        'object_type' => $stages[$j]['object_type'] ?? null,
                        'distinct_actors' => 0,
                    ];
                }
                break;
            }
        }
        return [
            'stages' => $results,
            'window' => ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)],
        ];
    }

    /**
     * Required KPIs summary.
     * @return array<string,mixed>
     */
    public function kpiSummary(User $actor, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (!$this->policy->canViewProtectedAnalytics($actor)) {
            throw new AuthorizationException('Missing permission: analytics.query');
        }
        $fromFmt = $from->format('Y-m-d H:i:s');
        $toFmt = $to->format('Y-m-d H:i:s');

        // Fix A + C: aggregate only events the caller is authorised to see and that are
        // not tied to blacklisted content. Scope + blacklist filters are applied BEFORE
        // aggregation so counts never include out-of-scope rows.
        // Inclusive upper bound — rows whose `occurred_at` equals `$to` (common when the
        // caller passes `now` and events were ingested in the same second) must be counted.
        $contentViews = static function () use ($fromFmt, $toFmt) {
            return AnalyticsEvent::query()
                ->where('event_type', 'content_view')
                ->where('occurred_at', '>=', $fromFmt)
                ->where('occurred_at', '<=', $toFmt);
        };
        $viewsLangQ = $contentViews();
        $this->policy->applyAnalyticsScope($actor, $viewsLangQ);
        $this->applyBlacklistExclusion($actor, $viewsLangQ);
        $viewsByLang = $viewsLangQ->selectRaw('language, COUNT(*) as c')->groupBy('language')->get()->all();

        $viewsMediaQ = $contentViews();
        $this->policy->applyAnalyticsScope($actor, $viewsMediaQ);
        $this->applyBlacklistExclusion($actor, $viewsMediaQ);
        $viewsByMedia = $viewsMediaQ->selectRaw('media_source, COUNT(*) as c')->groupBy('media_source')->get()->all();

        $contentByRiskQ = DB::table('contents');
        if (!$this->policy->isAdministrator($actor)) {
            $visible = $this->policy->visibleContentIdsForAnalytics($actor) ?? [];
            $blocked = $this->blacklist->activeTargetKeys('content');
            if ($visible === []) {
                // No visible content: force empty result set.
                $contentByRiskQ->whereRaw('1 = 0');
            } else {
                $contentByRiskQ->whereIn('content_id', $visible);
                if ($blocked !== []) {
                    $contentByRiskQ->whereNotIn('content_id', $blocked);
                }
            }
        }
        $contentByRisk = $contentByRiskQ->selectRaw('risk_state, COUNT(*) as c')->groupBy('risk_state')->get()->all();
        $moderationByDecision = DB::table('moderation_cases')->selectRaw('decision, COUNT(*) as c')->groupBy('decision')->get()->all();

        $dwellQ = $contentViews();
        $this->policy->applyAnalyticsScope($actor, $dwellQ);
        $this->applyBlacklistExclusion($actor, $dwellQ);
        $totalDwell = $dwellQ->sum('dwell_seconds');

        $viewersQ = $contentViews()->whereNotNull('actor_id');
        $this->policy->applyAnalyticsScope($actor, $viewersQ);
        $this->applyBlacklistExclusion($actor, $viewersQ);
        $uniqueViewers = $viewersQ->distinct()->count('actor_id');

        $slaTotal = DB::table('moderation_cases')->count();
        $slaMet = DB::table('moderation_cases')
            ->whereNotNull('resolved_at')
            ->whereRaw('resolved_at <= sla_due_at')
            ->count();
        $slaRate = $slaTotal > 0 ? round($slaMet / $slaTotal, 4) : null;

        $appeals = DB::table('moderation_appeals')->count();
        $appealsUpheld = DB::table('moderation_appeals')->where('status', 'upheld')->count();

        $eventPublications = DB::table('event_publications')->selectRaw('action, COUNT(*) as c')->groupBy('action')->get()->all();

        return [
            'window' => ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)],
            'content_views' => [
                'total' => array_sum(array_map(static fn($r) => (int) $r->c, $viewsByLang)),
                'unique_viewers' => $uniqueViewers,
                'total_dwell_seconds' => (int) $totalDwell,
                'by_language' => array_map(static fn($r) => ['language' => $r->language, 'count' => (int) $r->c], $viewsByLang),
                'by_media_source' => array_map(static fn($r) => ['media_source' => $r->media_source, 'count' => (int) $r->c], $viewsByMedia),
            ],
            'content_by_risk_state' => array_map(static fn($r) => ['risk_state' => $r->risk_state, 'count' => (int) $r->c], $contentByRisk),
            'moderation' => [
                'by_decision' => array_map(static fn($r) => ['decision' => $r->decision, 'count' => (int) $r->c], $moderationByDecision),
                'sla_compliance_rate' => $slaRate,
                'appeal_rate' => $slaTotal > 0 ? round($appeals / $slaTotal, 4) : null,
                'appeal_uphold_rate' => $appeals > 0 ? round($appealsUpheld / $appeals, 4) : null,
            ],
            'events' => [
                'publications_by_action' => array_map(static fn($r) => ['action' => $r->action, 'count' => (int) $r->c], $eventPublications),
            ],
        ];
    }

    private function formatEvent(AnalyticsEvent $e, bool $canUnmask): array
    {
        $ip = null;
        if ($canUnmask && $e->ip_address_ciphertext !== null) {
            try {
                $ip = $this->cipher->decrypt($e->ip_address_ciphertext);
            } catch (\Throwable) {
                $ip = null;
            }
        }
        return [
            'id' => (int) $e->id,
            'occurred_at' => (string) $e->occurred_at,
            'event_type' => $e->event_type,
            'object_type' => $e->object_type,
            'object_id' => $e->object_id,
            'actor_type' => $e->actor_type,
            'actor_id' => $e->actor_id,
            'session_id' => $e->session_id,
            'dwell_seconds' => $e->dwell_seconds !== null ? (int) $e->dwell_seconds : null,
            'language' => $e->language,
            'media_source' => $e->media_source,
            'section_tag' => $e->section_tag,
            'properties' => json_decode((string) $e->properties_json, true),
            'ip_address' => $canUnmask ? $ip : ($e->ip_address_ciphertext !== null ? '[masked]' : null),
        ];
    }
}
