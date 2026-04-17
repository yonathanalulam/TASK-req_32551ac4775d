<?php

declare(strict_types=1);

namespace Meridian\Domain\Content;

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
use Meridian\Domain\Content\Parsing\NormalizationPipeline;
use Meridian\Domain\Dedup\FingerprintService;
use Meridian\Domain\Moderation\AutomatedModerator;
use Meridian\Infrastructure\Clock\Clock;
use Ramsey\Uuid\Uuid;

/**
 * Coordinates parsing, persistence, and update of trusted content records.
 *
 * Guarantees:
 * - transactional: parse + persist + source mapping atomically committed
 * - idempotent on (source_key, source_record_id): same input never produces two content rows
 * - blacklist enforcement for both source and submitter
 * - audit log on every write
 */
final class ContentService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly NormalizationPipeline $pipeline,
        private readonly FingerprintService $fingerprint,
        private readonly AuditLogger $audit,
        private readonly BlacklistService $blacklist,
        private readonly AutomatedModerator $automatedModerator,
        private readonly Policy $policy,
    ) {
    }

    /**
     * @param array<string,mixed> $input parse request.
     * @param User|null $actor actor identity.
     */
    public function ingest(array $input, ?User $actor): array
    {
        $sourceKey = isset($input['source']) ? (string) $input['source'] : null;
        $sourceRecordId = isset($input['source_record_id']) ? (string) $input['source_record_id'] : null;

        if ($sourceKey !== null && $this->blacklist->isBlacklisted('source', $sourceKey)) {
            throw new ConflictException('Source is blacklisted.', 'BLACKLISTED_SOURCE');
        }
        if ($actor instanceof User && $this->blacklist->isBlacklisted('user', (string) $actor->id)) {
            throw new AuthorizationException('Actor is blacklisted.');
        }

        // Idempotency on (source_key, source_record_id)
        if ($sourceKey !== null && $sourceRecordId !== null) {
            $existing = ContentSource::query()
                ->where('source_key', $sourceKey)
                ->where('source_record_id', $sourceRecordId)
                ->first();
            if ($existing instanceof ContentSource) {
                $content = Content::query()->where('content_id', $existing->content_id)->first();
                if (!$content instanceof Content) {
                    throw new ConflictException('Source-content mapping broken.', 'SOURCE_ORPHANED');
                }
                return [
                    'content' => $content,
                    'duplicate' => true,
                    'source' => $existing,
                ];
            }
        }

        $actorPerms = $actor instanceof User ? UserPermissions::effective($actor) : [];
        $allowLangOverride = $actor instanceof User && isset($actorPerms['content.language_override']) && $actorPerms['content.language_override'];

        $normalized = $this->pipeline->normalize([
            'payload' => (string) ($input['payload'] ?? ''),
            'kind' => (string) ($input['kind'] ?? 'plain_text'),
            'title' => $input['title'] ?? null,
            'author' => $input['author'] ?? null,
            'media_source' => $input['media_source'] ?? null,
            'section_tags' => $input['section_tags'] ?? [],
            'duration_seconds' => $input['duration_seconds'] ?? null,
            'published_at' => $input['published_at'] ?? null,
            'language_override' => $input['language'] ?? null,
            'language_override_allowed' => $allowLangOverride,
        ]);

        $now = $this->clock->nowUtc();

        return DB::connection()->transaction(function () use ($normalized, $input, $sourceKey, $sourceRecordId, $now, $actor) {
            $requestId = Uuid::uuid4()->toString();
            ContentIngestRequest::query()->create([
                'id' => $requestId,
                'received_at' => $now->format('Y-m-d H:i:s'),
                'source_key' => $sourceKey,
                'source_record_id' => $sourceRecordId,
                'idempotency_key' => isset($input['idempotency_key']) ? (string) $input['idempotency_key'] : null,
                'submitted_by_user_id' => $actor?->id,
                'raw_payload_checksum' => $normalized->rawChecksum,
                'raw_payload_bytes' => $normalized->rawBytes,
                'payload_kind' => (string) ($input['kind'] ?? 'plain_text'),
                'status' => 'normalized',
                'resulting_content_id' => null,
            ]);

            $contentId = Uuid::uuid4()->toString();
            /** @var Content $content */
            $content = Content::query()->create([
                'content_id' => $contentId,
                'title' => mb_substr($normalized->title, 0, 191, 'UTF-8'),
                'title_normalized' => mb_substr($this->fingerprint->normalizeTitle($normalized->title), 0, 191, 'UTF-8'),
                'body' => $normalized->body,
                'body_checksum' => $normalized->bodyChecksum,
                'language' => $normalized->language,
                'author' => $normalized->author,
                'duration_seconds' => $normalized->durationSeconds,
                'media_source' => $normalized->mediaSource,
                'published_at' => $normalized->publishedAt?->format('Y-m-d H:i:s'),
                'ingested_at' => $now->format('Y-m-d H:i:s'),
                'risk_state' => 'normalized',
                'created_by_user_id' => $actor?->id,
                'last_modified_by_user_id' => $actor?->id,
                'version' => 1,
            ]);

            foreach ($normalized->sectionTags as $tag) {
                ContentSection::query()->firstOrCreate([
                    'content_id' => $contentId,
                    'tag_slug' => $tag,
                ]);
            }

            foreach ($normalized->mediaCandidates as $m) {
                ContentMediaRef::query()->create([
                    'content_id' => $contentId,
                    'media_type' => $m['media_type'] ?? 'other',
                    'local_path' => null,
                    'reference_hash' => hash('sha256', (string) $m['src']),
                    'external_url' => mb_substr((string) $m['src'], 0, 512, 'UTF-8'),
                    'caption' => isset($m['alt']) ? mb_substr((string) $m['alt'], 0, 255, 'UTF-8') : null,
                    'order_index' => 0,
                ]);
            }

            $this->fingerprint->recompute($content);

            $source = null;
            if ($sourceKey !== null && $sourceRecordId !== null) {
                /** @var ContentSource $source */
                $source = ContentSource::query()->create([
                    'source_key' => mb_substr($sourceKey, 0, 64, 'UTF-8'),
                    'source_record_id' => mb_substr($sourceRecordId, 0, 191, 'UTF-8'),
                    'content_id' => $contentId,
                    'original_url' => isset($input['original_url']) ? mb_substr((string) $input['original_url'], 0, 512, 'UTF-8') : null,
                    'original_checksum' => $normalized->rawChecksum,
                    'first_seen_at' => $now->format('Y-m-d H:i:s'),
                    'last_seen_at' => $now->format('Y-m-d H:i:s'),
                    'is_active' => true,
                ]);
            }

            ContentIngestRequest::query()->where('id', $requestId)->update([
                'resulting_content_id' => $contentId,
            ]);

            $this->audit->record('content.ingested', 'content', $contentId, [
                'source_key' => $sourceKey,
                'source_record_id' => $sourceRecordId,
                'language' => $normalized->language,
                'media_source' => $normalized->mediaSource,
                'body_length' => mb_strlen($normalized->body, 'UTF-8'),
                'ad_link_count' => $normalized->adLinkCount,
            ], actorType: $actor instanceof User ? 'user' : 'system', actorId: $actor instanceof User ? (string) $actor->id : 'system');

            // Automated moderation runs within the ingest transaction so any flag + content
            // state transition + audit entries commit atomically with the trusted record.
            $moderation = $this->automatedModerator->moderate(
                $content,
                $normalized->adLinkCount,
                $normalized->provenanceUrls,
            );

            return [
                'content' => $content,
                'duplicate' => false,
                'source' => $source,
                'normalized' => $normalized,
                'automated_moderation' => $moderation,
            ];
        });
    }

    public function updateMetadata(User $actor, string $contentId, array $patch): Content
    {
        $content = Content::query()->find($contentId);
        if (!$content instanceof Content) {
            throw new NotFoundException('Content not found.');
        }
        if (!$this->policy->canEditContent($actor, $content)) {
            throw new AuthorizationException('Not authorized to edit this content.');
        }
        // Fix C: metadata edits on blacklisted content are restricted to administrators,
        // who may need to adjust risk_state as part of governance remediation.
        if (!$this->policy->isAdministrator($actor) && $this->blacklist->isBlacklisted('content', $contentId)) {
            $this->audit->record('content.edit_blocked_blacklisted', 'content', $contentId, [
                'actor_user_id' => (int) $actor->id,
            ], actorType: 'user', actorId: (string) $actor->id);
            throw new AuthorizationException('Blacklisted content cannot be edited.');
        }
        $changed = [];
        if (array_key_exists('title', $patch)) {
            $t = trim((string) $patch['title']);
            if ($t === '' || mb_strlen($t) > 180) {
                throw new ValidationException('Invalid title length.', ['field' => 'title']);
            }
            $content->title = $t;
            $content->title_normalized = $this->fingerprint->normalizeTitle($t);
            $changed[] = 'title';
        }
        if (array_key_exists('author', $patch)) {
            $content->author = $patch['author'] !== null ? mb_substr((string) $patch['author'], 0, 180) : null;
            $changed[] = 'author';
        }
        if (array_key_exists('section_tags', $patch)) {
            $tags = is_array($patch['section_tags']) ? $patch['section_tags'] : [];
            $normalizedTags = (new \Meridian\Domain\Content\Parsing\SectionTagNormalizer())->normalize($tags);
            ContentSection::query()->where('content_id', $contentId)->delete();
            foreach ($normalizedTags as $tag) {
                ContentSection::query()->create(['content_id' => $contentId, 'tag_slug' => $tag]);
            }
            $changed[] = 'section_tags';
        }
        if (array_key_exists('risk_state', $patch)) {
            $new = (string) $patch['risk_state'];
            $this->validateRiskStateTransition((string) $content->risk_state, $new);
            $content->risk_state = $new;
            $changed[] = 'risk_state';
        }
        $content->last_modified_by_user_id = $actor->id;
        $content->version = (int) $content->version + 1;
        $content->save();
        if (in_array('title', $changed, true) || in_array('author', $changed, true)) {
            $this->fingerprint->recompute($content);
        }
        $this->audit->record('content.metadata_updated', 'content', $contentId, ['fields' => $changed], actorType: 'user', actorId: (string) $actor->id);
        return $content;
    }

    public function get(User $actor, string $contentId): Content
    {
        $content = Content::query()->find($contentId);
        if (!$content instanceof Content) {
            throw new NotFoundException('Content not found.');
        }
        if (!$this->policy->canViewContent($actor, $content)) {
            throw new AuthorizationException('Not authorized to view this content.');
        }
        // Fix C: blacklisted content is hidden from non-admin reads. Administrators may
        // still retrieve the record for governance (e.g., to revoke a blacklist entry).
        if (!$this->policy->isAdministrator($actor) && $this->blacklist->isBlacklisted('content', $contentId)) {
            $this->audit->record('content.view_blocked_blacklisted', 'content', $contentId, [
                'actor_user_id' => (int) $actor->id,
            ], actorType: 'user', actorId: (string) $actor->id);
            throw new NotFoundException('Content not found.');
        }
        return $content;
    }

    /**
     * Search within the caller's view scope. The minimum search filters implemented per PRD 12.1.
     *
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function search(User $actor, array $filters, int $page, int $pageSize): array
    {
        if (!UserPermissions::hasPermission($actor, 'content.view')) {
            throw new AuthorizationException('Missing permission: content.view');
        }
        $q = Content::query();
        // Apply baseline policy filter: non-privileged users only see safe content plus
        // explicit scope bindings + their own drafts. Administrators & reviewers skip the filter.
        if (!$this->policy->isAdministrator($actor) && !UserPermissions::hasPermission($actor, 'moderation.view_cases')) {
            $q->where(function ($qq) use ($actor) {
                $qq->whereIn('risk_state', ['normalized', 'published_safe'])
                    ->orWhere('created_by_user_id', (int) $actor->id)
                    ->orWhereIn('content_id', \Illuminate\Database\Capsule\Manager::table('user_role_bindings')
                        ->where('user_id', (int) $actor->id)
                        ->where('scope_type', 'content')
                        ->pluck('scope_ref'));
            });
        }
        if (!empty($filters['title'])) {
            $q->where('title', 'like', '%' . $this->escapeLike((string) $filters['title']) . '%');
        }
        if (!empty($filters['author'])) {
            $q->where('author', 'like', '%' . $this->escapeLike((string) $filters['author']) . '%');
        }
        if (!empty($filters['language'])) {
            $q->where('language', (string) $filters['language']);
        }
        if (!empty($filters['media_source'])) {
            $q->where('media_source', (string) $filters['media_source']);
        }
        if (!empty($filters['risk_state'])) {
            $q->where('risk_state', (string) $filters['risk_state']);
        }
        if (!empty($filters['published_from'])) {
            $q->where('published_at', '>=', (string) $filters['published_from']);
        }
        if (!empty($filters['published_to'])) {
            $q->where('published_at', '<=', (string) $filters['published_to']);
        }
        if (!empty($filters['section_tag'])) {
            $tag = (string) $filters['section_tag'];
            $q->whereIn('content_id', DB::table('content_sections')->where('tag_slug', $tag)->pluck('content_id'));
        }
        // Fix C: exclude blacklisted content from search results for non-admin callers.
        if (!$this->policy->isAdministrator($actor)) {
            $blocked = $this->blacklist->activeTargetKeys('content');
            if ($blocked !== []) {
                $q->whereNotIn('content_id', $blocked);
            }
        }
        $q->orderByDesc('ingested_at');

        $total = (clone $q)->count();
        $rows = $q->forPage($page, $pageSize)->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'content_id' => $row->content_id,
                'title' => $row->title,
                'author' => $row->author,
                'language' => $row->language,
                'media_source' => $row->media_source,
                'published_at' => $row->published_at?->format(DATE_ATOM),
                'ingested_at' => $row->ingested_at?->format(DATE_ATOM),
                'risk_state' => $row->risk_state,
                'snippet' => $this->snippet($row->body),
            ];
        }
        return ['items' => $items, 'total' => $total];
    }

    private function snippet(string $body): string
    {
        $s = mb_substr($body, 0, 240, 'UTF-8');
        return $s . (mb_strlen($body, 'UTF-8') > 240 ? '…' : '');
    }

    private function validateRiskStateTransition(string $from, string $to): void
    {
        $allowed = [
            'ingested' => ['normalized', 'rejected'],
            'normalized' => ['flagged', 'published_safe', 'quarantined'],
            'flagged' => ['under_review', 'published_safe', 'restricted', 'rejected'],
            'under_review' => ['published_safe', 'restricted', 'rejected', 'escalated'],
            'escalated' => ['published_safe', 'restricted', 'rejected'],
            'published_safe' => ['flagged', 'restricted'],
            'restricted' => ['under_review', 'rejected'],
            'quarantined' => ['under_review', 'rejected'],
            'rejected' => [],
        ];
        if (!isset($allowed[$from]) || !in_array($to, $allowed[$from], true)) {
            throw new ValidationException("Illegal content risk_state transition from {$from} to {$to}.", ['field' => 'risk_state']);
        }
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}
