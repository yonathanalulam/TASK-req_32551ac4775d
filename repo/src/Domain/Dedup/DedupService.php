<?php

declare(strict_types=1);

namespace Meridian\Domain\Dedup;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\ConflictException;
use Meridian\Application\Exceptions\NotFoundException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Domain\Authorization\Policy;
use Meridian\Domain\Blacklist\BlacklistService;
use Meridian\Domain\Content\Content;
use Meridian\Domain\Content\ContentFingerprint;
use Meridian\Domain\Content\ContentMergeHistory;
use Meridian\Domain\Content\ContentSource;
use Meridian\Infrastructure\Clock\Clock;

/**
 * Deduplication merge/unmerge and candidate generation.
 *
 * Thresholds (from config):
 *   auto_merge_similarity: >= 0.92 & author match & duration match -> auto_mergeable
 *   review_similarity_min: 0.85 .. (auto-1) OR author conflict -> pending_review
 */
final class DedupService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly FingerprintService $fingerprint,
        private readonly AuditLogger $audit,
        private readonly array $config,
        private readonly Policy $policy,
        private readonly BlacklistService $blacklist,
    ) {
    }

    /**
     * Full recompute. For a single-host offline deployment we use an O(N^2) pass bounded
     * by recent non-merged records. Larger deployments can swap in a blocking key strategy
     * without API change.
     */
    public function recompute(?int $limit = null): int
    {
        $q = Content::query()->whereNull('merged_into_content_id');
        if ($limit !== null) {
            $q->orderByDesc('ingested_at')->limit($limit);
        }
        $contents = $q->get(['content_id', 'title_normalized', 'author', 'duration_seconds'])->all();
        $candidates = 0;
        DedupCandidate::query()->where('status', 'pending_review')->delete();
        DedupCandidate::query()->where('status', 'auto_mergeable')->delete();
        for ($i = 0; $i < count($contents); $i++) {
            for ($j = $i + 1; $j < count($contents); $j++) {
                $a = $contents[$i];
                $b = $contents[$j];
                $sim = $this->fingerprint->similarity((string) $a->title_normalized, (string) $b->title_normalized);
                if ($sim < (float) $this->config['review_similarity_min']) {
                    continue;
                }
                $authorMatch = $a->author !== null && $b->author !== null
                    && $this->fingerprint->normalizeTitle((string) $a->author) === $this->fingerprint->normalizeTitle((string) $b->author);
                $durationMatch = $a->duration_seconds !== null && $b->duration_seconds !== null
                    && (int) $a->duration_seconds === (int) $b->duration_seconds;

                $status = 'pending_review';
                // Author conflict (both authors present, different) forbids auto-merge.
                $authorConflict = ($a->author !== null && $b->author !== null) && !$authorMatch;
                if ($sim >= (float) $this->config['auto_merge_similarity'] && !$authorConflict) {
                    $status = 'auto_mergeable';
                }
                [$left, $right] = $a->content_id < $b->content_id ? [$a, $b] : [$b, $a];
                DedupCandidate::query()->create([
                    'left_content_id' => $left->content_id,
                    'right_content_id' => $right->content_id,
                    'title_similarity' => round($sim, 4),
                    'author_match' => $authorMatch,
                    'duration_match' => $durationMatch,
                    'status' => $status,
                    'created_at' => $this->clock->nowUtc()->format('Y-m-d H:i:s'),
                ]);
                $candidates++;
            }
        }
        $this->audit->record('dedup.recomputed', 'dedup', null, ['candidates' => $candidates], actorType: 'system', actorId: 'dedup');
        return $candidates;
    }

    public function listCandidates(User $actor, ?string $status, int $page, int $pageSize): array
    {
        if (!UserPermissions::hasPermission($actor, 'content.merge') && !UserPermissions::hasPermission($actor, 'moderation.review')) {
            throw new AuthorizationException('Missing permission to view dedup candidates.');
        }
        $q = DedupCandidate::query();
        if ($status !== null) {
            $q->where('status', $status);
        }
        $q->orderByDesc('title_similarity');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $pageSize)->get()->all();
        return ['items' => array_map(static fn(DedupCandidate $c) => [
            'id' => (int) $c->id,
            'left_content_id' => $c->left_content_id,
            'right_content_id' => $c->right_content_id,
            'title_similarity' => (float) $c->title_similarity,
            'author_match' => (bool) $c->author_match,
            'duration_match' => (bool) $c->duration_match,
            'status' => $c->status,
        ], $rows), 'total' => $total];
    }

    public function merge(User $actor, string $primaryId, string $secondaryId, ?string $reason = null): array
    {
        if (!$this->policy->canMergeContent($actor)) {
            throw new AuthorizationException('Missing permission: content.merge');
        }
        if ($primaryId === $secondaryId) {
            throw new ValidationException('Cannot merge a record into itself.');
        }
        return DB::connection()->transaction(function () use ($actor, $primaryId, $secondaryId, $reason) {
            $primary = Content::query()->find($primaryId);
            $secondary = Content::query()->find($secondaryId);
            if (!$primary instanceof Content || !$secondary instanceof Content) {
                throw new NotFoundException('Both records must exist.');
            }
            if ($secondary->merged_into_content_id !== null) {
                throw new ConflictException('Secondary record is already merged.', 'ALREADY_MERGED');
            }
            if ($primary->merged_into_content_id !== null) {
                throw new ConflictException('Primary record is itself merged; choose the root.', 'PRIMARY_IS_MERGED');
            }
            // Fix C: merging onto or from blacklisted content is forbidden; it would
            // propagate blacklisted identity onto a clean record (or hide the
            // blacklisted record behind the merge chain).
            if ($this->blacklist->isBlacklisted('content', $primary->content_id)
                || $this->blacklist->isBlacklisted('content', $secondary->content_id)
            ) {
                $this->audit->record('dedup.merge_blocked_blacklisted', 'content', $primary->content_id, [
                    'primary_content_id' => $primary->content_id,
                    'secondary_content_id' => $secondary->content_id,
                ], actorType: 'user', actorId: (string) $actor->id);
                throw new ConflictException('Blacklisted content cannot participate in merge.', 'BLACKLISTED_CONTENT');
            }

            $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');
            $secondary->merged_into_content_id = $primary->content_id;
            $secondary->merged_at = $now;
            $secondary->risk_state = $secondary->risk_state; // unchanged; merge does not alter risk state
            $secondary->save();

            ContentSource::query()
                ->where('content_id', $secondary->content_id)
                ->update(['content_id' => $primary->content_id, 'last_seen_at' => $now]);

            ContentMergeHistory::query()->create([
                'primary_content_id' => $primary->content_id,
                'secondary_content_id' => $secondary->content_id,
                'action' => 'merge',
                'actor_user_id' => (int) $actor->id,
                'actor_type' => 'user',
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'evidence_json' => json_encode([
                    'primary_checksum' => $primary->body_checksum,
                    'secondary_checksum' => $secondary->body_checksum,
                ]),
                'created_at' => $now,
            ]);

            DedupCandidate::query()
                ->where(function ($q) use ($primary, $secondary) {
                    $q->where(function ($qq) use ($primary, $secondary) {
                        $qq->where('left_content_id', $primary->content_id)->where('right_content_id', $secondary->content_id);
                    })->orWhere(function ($qq) use ($primary, $secondary) {
                        $qq->where('left_content_id', $secondary->content_id)->where('right_content_id', $primary->content_id);
                    });
                })
                ->update([
                    'status' => 'reviewed_merged',
                    'reviewed_at' => $now,
                    'reviewed_by_user_id' => (int) $actor->id,
                ]);

            $this->audit->record('dedup.merge', 'content', $primary->content_id, [
                'primary_content_id' => $primary->content_id,
                'secondary_content_id' => $secondary->content_id,
                'reason' => $reason,
            ], actorType: 'user', actorId: (string) $actor->id);

            return ['primary_content_id' => $primary->content_id, 'secondary_content_id' => $secondary->content_id];
        });
    }

    public function unmerge(User $actor, string $secondaryId, ?string $reason = null): array
    {
        if (!$this->policy->canUnmergeContent($actor)) {
            throw new AuthorizationException('Administrator role required for unmerge.');
        }
        return DB::connection()->transaction(function () use ($actor, $secondaryId, $reason) {
            $secondary = Content::query()->find($secondaryId);
            if (!$secondary instanceof Content || $secondary->merged_into_content_id === null) {
                throw new ConflictException('Record is not merged.', 'NOT_MERGED');
            }
            $previousPrimary = (string) $secondary->merged_into_content_id;

            $latestMerge = ContentMergeHistory::query()
                ->where('primary_content_id', $previousPrimary)
                ->where('secondary_content_id', $secondaryId)
                ->where('action', 'merge')
                ->orderByDesc('id')
                ->first();
            $originalSources = [];
            if ($latestMerge instanceof ContentMergeHistory) {
                $originalSources = json_decode((string) $latestMerge->evidence_json, true) ?: [];
            }

            $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');
            $secondary->merged_into_content_id = null;
            $secondary->merged_at = null;
            $secondary->save();

            // Reattach sources whose original_checksum matches the secondary's checksum
            ContentSource::query()
                ->where('content_id', $previousPrimary)
                ->where('original_checksum', $secondary->body_checksum)
                ->update(['content_id' => $secondary->content_id, 'last_seen_at' => $now]);

            ContentMergeHistory::query()->create([
                'primary_content_id' => $previousPrimary,
                'secondary_content_id' => $secondary->content_id,
                'action' => 'unmerge',
                'actor_user_id' => (int) $actor->id,
                'actor_type' => 'user',
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'evidence_json' => json_encode(['reverts_merge_id' => $latestMerge?->id]),
                'created_at' => $now,
            ]);

            $this->audit->record('dedup.unmerge', 'content', $secondaryId, [
                'previous_primary_content_id' => $previousPrimary,
                'reason' => $reason,
            ], actorType: 'user', actorId: (string) $actor->id);

            return ['secondary_content_id' => $secondary->content_id, 'previous_primary_content_id' => $previousPrimary];
        });
    }
}
