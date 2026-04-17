<?php

declare(strict_types=1);

namespace Meridian\Domain\Events;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\ConflictException;
use Meridian\Application\Exceptions\NotFoundException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Domain\Authorization\Policy;
use Meridian\Infrastructure\Clock\Clock;
use Ramsey\Uuid\Uuid;

/**
 * Event schedule management.
 *
 * Invariants:
 * - Published versions are immutable (config_snapshot_json, bindings, rules may not be edited)
 * - Rollback creates an EventPublication(action=rollback) and activates a prior published version
 * - Effective windows of active published versions per event may not overlap
 * - Optimistic locking via draft_version_number ensures concurrent draft edits don't silently clobber
 */
final class EventService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly Policy $policy,
    ) {
    }

    public function createEvent(User $actor, array $input): array
    {
        $this->requireDraftPerm($actor);
        $name = trim((string) ($input['name'] ?? ''));
        $templateKey = (string) ($input['template_key'] ?? '');
        $familyKey = (string) ($input['event_family_key'] ?? '');
        if ($name === '' || $templateKey === '' || $familyKey === '') {
            throw new ValidationException('name, template_key, event_family_key are required.');
        }
        $template = EventTemplate::query()->where('key', $templateKey)->first();
        if (!$template instanceof EventTemplate) {
            throw new NotFoundException('Template not found: ' . $templateKey);
        }

        return DB::connection()->transaction(function () use ($actor, $name, $familyKey, $template) {
            $eventId = Uuid::uuid4()->toString();
            /** @var Event $event */
            $event = Event::query()->create([
                'event_id' => $eventId,
                'name' => mb_substr($name, 0, 191),
                'event_family_key' => mb_substr($familyKey, 0, 96),
                'template_id' => (int) $template->id,
                'created_by_user_id' => (int) $actor->id,
            ]);
            $version = $this->createDraftVersionInternal($actor, $event, 1, null);
            $this->audit->record('events.event_created', 'event', $eventId, [
                'name' => $name,
                'event_family_key' => $familyKey,
                'template_key' => $template->key,
            ], actorType: 'user', actorId: (string) $actor->id);
            return ['event' => $event, 'initial_version' => $version];
        });
    }

    public function createDraftVersion(User $actor, string $eventId, ?array $baseConfig = null): EventVersion
    {
        $event = $this->findEventOr404($eventId);
        if (!$this->policy->canEditEvent($actor, $event)) {
            throw new AuthorizationException('Not authorized to draft on this event.');
        }
        $next = (int) EventVersion::query()->where('event_id', $eventId)->max('version') + 1;
        return $this->createDraftVersionInternal($actor, $event, $next, $baseConfig);
    }

    public function updateDraft(User $actor, string $eventId, int $versionId, array $input): EventVersion
    {
        $event = $this->findEventOr404($eventId);
        if (!$this->policy->canEditEvent($actor, $event)) {
            throw new AuthorizationException('Not authorized to edit this event.');
        }
        $version = $this->findVersionOr404($eventId, $versionId);
        if ($version->status !== 'draft') {
            throw new ConflictException('Only draft versions can be updated.', 'VERSION_NOT_DRAFT');
        }
        $expectedLock = isset($input['expected_draft_version_number']) ? (int) $input['expected_draft_version_number'] : null;
        if ($expectedLock !== null && $expectedLock !== (int) $version->draft_version_number) {
            throw new ConflictException('Optimistic lock conflict.', 'DRAFT_LOCK_CONFLICT');
        }

        $now = $this->clock->nowUtc();
        return DB::connection()->transaction(function () use ($actor, $event, $version, $input, $now) {
            $config = json_decode((string) $version->config_snapshot_json, true) ?: [];
            foreach (['name', 'description', 'effective_from', 'effective_to'] as $field) {
                if (array_key_exists($field, $input)) {
                    $config[$field] = $input[$field];
                }
            }
            $version->config_snapshot_json = json_encode($config);
            $version->draft_updated_at = $now->format('Y-m-d H:i:s');
            $version->draft_version_number = (int) $version->draft_version_number + 1;
            if (array_key_exists('effective_from', $input)) {
                $version->effective_from = $input['effective_from'] !== null ? (new DateTimeImmutable((string) $input['effective_from']))->format('Y-m-d H:i:s') : null;
            }
            if (array_key_exists('effective_to', $input)) {
                $version->effective_to = $input['effective_to'] !== null ? (new DateTimeImmutable((string) $input['effective_to']))->format('Y-m-d H:i:s') : null;
            }
            $version->save();

            if (isset($input['rule_set']) && is_array($input['rule_set'])) {
                $rs = EventRuleSet::query()->where('event_version_id', $version->id)->first();
                if (!$rs instanceof EventRuleSet) {
                    throw new \RuntimeException('Rule set missing for draft version.');
                }
                if (array_key_exists('attempt_limit', $input['rule_set'])) {
                    $rs->attempt_limit = max(1, (int) $input['rule_set']['attempt_limit']);
                }
                if (array_key_exists('checkin_open_minutes_before', $input['rule_set'])) {
                    $rs->checkin_open_minutes_before = max(0, (int) $input['rule_set']['checkin_open_minutes_before']);
                }
                if (array_key_exists('late_cutoff_minutes_after', $input['rule_set'])) {
                    $rs->late_cutoff_minutes_after = max(0, (int) $input['rule_set']['late_cutoff_minutes_after']);
                }
                $rs->overrides_json = isset($input['rule_set']['overrides']) ? json_encode($input['rule_set']['overrides']) : null;
                $rs->save();
            }

            if (isset($input['advancement_rules']) && is_array($input['advancement_rules'])) {
                $this->replaceAdvancementRules($version, $input['advancement_rules']);
            }

            $this->audit->record('events.draft_updated', 'event_version', (string) $version->id, [
                'event_id' => $event->event_id,
                'version' => $version->version,
                'new_draft_version_number' => $version->draft_version_number,
            ], actorType: 'user', actorId: (string) $actor->id);
            return $version;
        });
    }

    public function addBinding(User $actor, string $eventId, int $versionId, array $input): EventBinding
    {
        $event = $this->findEventOr404($eventId);
        if (!$this->policy->canManageEventBindings($actor, $event)) {
            throw new AuthorizationException('Not authorized to manage bindings on this event.');
        }
        $version = $this->findVersionOr404($eventId, $versionId);
        if ($version->status !== 'draft') {
            throw new ConflictException('Bindings only editable in draft.', 'VERSION_NOT_DRAFT');
        }
        $type = (string) ($input['binding_type'] ?? '');
        if (!in_array($type, ['venue', 'equipment'], true)) {
            throw new ValidationException('binding_type must be venue or equipment.');
        }
        if ($type === 'venue') {
            $venueId = (int) ($input['venue_id'] ?? 0);
            if (!EventVenue::query()->where('id', $venueId)->exists()) {
                throw new NotFoundException('venue_id invalid.');
            }
        } else {
            $equipmentId = (int) ($input['equipment_id'] ?? 0);
            if (!EventEquipment::query()->where('id', $equipmentId)->exists()) {
                throw new NotFoundException('equipment_id invalid.');
            }
        }
        /** @var EventBinding $b */
        $b = EventBinding::query()->create([
            'event_version_id' => $version->id,
            'binding_type' => $type,
            'venue_id' => $type === 'venue' ? (int) $input['venue_id'] : null,
            'equipment_id' => $type === 'equipment' ? (int) $input['equipment_id'] : null,
            'quantity' => max(1, (int) ($input['quantity'] ?? 1)),
            'notes' => isset($input['notes']) ? mb_substr((string) $input['notes'], 0, 255) : null,
        ]);
        $this->audit->record('events.binding_added', 'event_version', (string) $version->id, [
            'binding_type' => $type,
            'venue_id' => $b->venue_id,
            'equipment_id' => $b->equipment_id,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $b;
    }

    public function publishVersion(User $actor, string $eventId, int $versionId): EventVersion
    {
        if (!$this->policy->canPublishEvent($actor)) {
            throw new AuthorizationException('Administrator role (or events.publish) required.');
        }
        $event = $this->findEventOr404($eventId);
        return DB::connection()->transaction(function () use ($actor, $event, $versionId) {
            $version = $this->findVersionOr404($event->event_id, $versionId);
            if ($version->status !== 'draft') {
                throw new ConflictException('Only draft versions can be published.', 'VERSION_NOT_DRAFT');
            }
            $this->assertNoOverlap($event->event_id, $version);

            $now = $this->clock->nowUtc();
            // supersede existing active version if any
            $supersededId = null;
            if ($event->active_version_id !== null) {
                $prev = EventVersion::query()->find((int) $event->active_version_id);
                if ($prev instanceof EventVersion && $prev->status === 'published') {
                    $prev->status = 'superseded';
                    $prev->save();
                    $supersededId = (int) $prev->id;
                }
            }
            $version->status = 'published';
            $version->published_at = $now->format('Y-m-d H:i:s');
            $version->published_by_user_id = (int) $actor->id;
            $version->supersedes_version_id = $supersededId;
            $version->save();
            $event->active_version_id = (int) $version->id;
            $event->save();

            EventPublication::query()->create([
                'event_id' => $event->event_id,
                'event_version_id' => $version->id,
                'action' => 'publish',
                'actor_user_id' => (int) $actor->id,
                'rationale' => null,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $this->audit->record('events.version_published', 'event_version', (string) $version->id, [
                'event_id' => $event->event_id,
                'version' => $version->version,
                'superseded_version_id' => $supersededId,
            ], actorType: 'user', actorId: (string) $actor->id);
            return $version;
        });
    }

    public function rollback(User $actor, string $eventId, int $targetVersionId, ?string $rationale): EventVersion
    {
        if (!$this->policy->canRollbackEvent($actor)) {
            throw new AuthorizationException('Administrator role (or events.rollback) required.');
        }
        $event = $this->findEventOr404($eventId);
        return DB::connection()->transaction(function () use ($actor, $event, $targetVersionId, $rationale) {
            $target = $this->findVersionOr404($event->event_id, $targetVersionId);
            if (!in_array($target->status, ['superseded', 'rolled_back'], true)) {
                throw new ConflictException('Rollback target must be a prior superseded/rolled-back version.', 'ROLLBACK_TARGET_INVALID');
            }
            $now = $this->clock->nowUtc();
            // Current active -> rolled_back
            if ($event->active_version_id !== null) {
                $current = EventVersion::query()->find((int) $event->active_version_id);
                if ($current instanceof EventVersion && $current->status === 'published') {
                    $current->status = 'rolled_back';
                    $current->save();
                }
            }
            $target->status = 'published';
            $target->save();
            $event->active_version_id = (int) $target->id;
            $event->save();

            EventPublication::query()->create([
                'event_id' => $event->event_id,
                'event_version_id' => $target->id,
                'action' => 'rollback',
                'actor_user_id' => (int) $actor->id,
                'rationale' => $rationale !== null ? mb_substr($rationale, 0, 512) : null,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $this->audit->record('events.version_rolled_back', 'event_version', (string) $target->id, [
                'event_id' => $event->event_id,
                'activated_version' => $target->version,
                'rationale' => $rationale,
            ], actorType: 'user', actorId: (string) $actor->id);
            return $target;
        });
    }

    public function cancel(User $actor, string $eventId, int $versionId, ?string $rationale): EventVersion
    {
        if (!$this->policy->canCancelEvent($actor)) {
            throw new AuthorizationException('Administrator role (or events.cancel) required.');
        }
        $event = $this->findEventOr404($eventId);
        $version = $this->findVersionOr404($event->event_id, $versionId);
        if ($version->status !== 'published') {
            throw new ConflictException('Only published versions can be cancelled.', 'VERSION_NOT_PUBLISHED');
        }
        $version->status = 'cancelled';
        $version->save();
        if ((int) $event->active_version_id === (int) $version->id) {
            $event->active_version_id = null;
            $event->save();
        }
        EventPublication::query()->create([
            'event_id' => $event->event_id,
            'event_version_id' => $version->id,
            'action' => 'cancel',
            'actor_user_id' => (int) $actor->id,
            'rationale' => $rationale !== null ? mb_substr($rationale, 0, 512) : null,
            'created_at' => $this->clock->nowUtc()->format('Y-m-d H:i:s'),
        ]);
        $this->audit->record('events.version_cancelled', 'event_version', (string) $version->id, [
            'event_id' => $event->event_id,
            'rationale' => $rationale,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $version;
    }

    public function getVersion(User $actor, string $eventId, int $versionId): array
    {
        $event = $this->findEventOr404($eventId);
        if (!$this->policy->canViewEvent($actor, $event)) {
            throw new AuthorizationException('Not authorized to view this event.');
        }
        $v = $this->findVersionOr404($eventId, $versionId);
        $rs = EventRuleSet::query()->where('event_version_id', $v->id)->first();
        $adv = EventAdvancementRule::query()->where('event_version_id', $v->id)->orderBy('precedence')->get()->all();
        $bind = EventBinding::query()->where('event_version_id', $v->id)->orderBy('id')->get()->all();
        return [
            'id' => (int) $v->id,
            'event_id' => $v->event_id,
            'version' => (int) $v->version,
            'status' => $v->status,
            'effective_from' => $v->effective_from?->format(DATE_ATOM),
            'effective_to' => $v->effective_to?->format(DATE_ATOM),
            'published_at' => $v->published_at?->format(DATE_ATOM),
            'published_by_user_id' => $v->published_by_user_id,
            'config_snapshot' => json_decode((string) $v->config_snapshot_json, true),
            'draft_version_number' => (int) $v->draft_version_number,
            'rule_set' => $rs instanceof EventRuleSet ? [
                'attempt_limit' => (int) $rs->attempt_limit,
                'checkin_open_minutes_before' => (int) $rs->checkin_open_minutes_before,
                'late_cutoff_minutes_after' => (int) $rs->late_cutoff_minutes_after,
                'overrides' => json_decode((string) $rs->overrides_json, true),
            ] : null,
            'advancement_rules' => array_map(static fn($a) => [
                'precedence' => (int) $a->precedence,
                'criterion' => $a->criterion,
                'criterion_config' => json_decode((string) $a->criterion_config_json, true),
                'description' => $a->description,
            ], $adv),
            'bindings' => array_map(static fn($b) => [
                'id' => (int) $b->id,
                'binding_type' => $b->binding_type,
                'venue_id' => $b->venue_id,
                'equipment_id' => $b->equipment_id,
                'quantity' => (int) $b->quantity,
                'notes' => $b->notes,
            ], $bind),
        ];
    }

    public function listEvents(User $actor, array $filters, int $page, int $size): array
    {
        $q = Event::query()->orderBy('name');
        if (!empty($filters['event_family_key'])) {
            $q->where('event_family_key', (string) $filters['event_family_key']);
        }
        // Non-admins/non-analytics see only events they created or are scoped into.
        if (!$this->policy->isAdministrator($actor) && !UserPermissions::hasPermission($actor, 'analytics.query')) {
            $scopedFamilies = DB::table('user_role_bindings')
                ->where('user_id', (int) $actor->id)
                ->where('scope_type', 'event_family')
                ->pluck('scope_ref');
            $q->where(function ($qq) use ($actor, $scopedFamilies) {
                $qq->where('created_by_user_id', (int) $actor->id)
                    ->orWhereIn('event_family_key', $scopedFamilies);
            });
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $size)->get()->all();
        return ['items' => array_map(static fn(Event $e) => [
            'event_id' => $e->event_id,
            'name' => $e->name,
            'event_family_key' => $e->event_family_key,
            'template_id' => $e->template_id,
            'active_version_id' => $e->active_version_id,
        ], $rows), 'total' => $total];
    }

    public function getEvent(User $actor, string $eventId): array
    {
        $e = $this->findEventOr404($eventId);
        if (!$this->policy->canViewEvent($actor, $e)) {
            throw new AuthorizationException('Not authorized to view this event.');
        }
        $versions = EventVersion::query()->where('event_id', $eventId)->orderBy('version')->get()->all();
        return [
            'event_id' => $e->event_id,
            'name' => $e->name,
            'event_family_key' => $e->event_family_key,
            'template_id' => $e->template_id,
            'active_version_id' => $e->active_version_id,
            'versions' => array_map(static fn(EventVersion $v) => [
                'id' => (int) $v->id,
                'version' => (int) $v->version,
                'status' => $v->status,
                'effective_from' => $v->effective_from?->format(DATE_ATOM),
                'effective_to' => $v->effective_to?->format(DATE_ATOM),
                'published_at' => $v->published_at?->format(DATE_ATOM),
            ], $versions),
        ];
    }

    private function createDraftVersionInternal(User $actor, Event $event, int $versionNumber, ?array $baseConfig): EventVersion
    {
        $template = EventTemplate::query()->find((int) $event->template_id);
        $attemptLimit = $template instanceof EventTemplate ? (int) $template->default_attempt_limit : 3;
        $checkin = $template instanceof EventTemplate ? (int) $template->default_checkin_open_minutes_before : 60;
        $late = $template instanceof EventTemplate ? (int) $template->default_late_cutoff_minutes_after : 10;
        $now = $this->clock->nowUtc();

        /** @var EventVersion $v */
        $v = EventVersion::query()->create([
            'event_id' => $event->event_id,
            'version' => $versionNumber,
            'status' => 'draft',
            'config_snapshot_json' => json_encode($baseConfig ?? ['name' => $event->name]),
            'draft_updated_at' => $now->format('Y-m-d H:i:s'),
            'draft_version_number' => 1,
        ]);
        EventRuleSet::query()->create([
            'event_version_id' => (int) $v->id,
            'attempt_limit' => $attemptLimit,
            'checkin_open_minutes_before' => $checkin,
            'late_cutoff_minutes_after' => $late,
        ]);
        $this->audit->record('events.draft_created', 'event_version', (string) $v->id, [
            'event_id' => $event->event_id,
            'version' => $versionNumber,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $v;
    }

    private function replaceAdvancementRules(EventVersion $version, array $rules): void
    {
        EventAdvancementRule::query()->where('event_version_id', (int) $version->id)->delete();
        $seenPrecedence = [];
        foreach ($rules as $i => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $precedence = (int) ($rule['precedence'] ?? ($i + 1));
            if (isset($seenPrecedence[$precedence])) {
                throw new ValidationException('Duplicate advancement precedence: ' . $precedence);
            }
            $seenPrecedence[$precedence] = true;
            $criterion = (string) ($rule['criterion'] ?? '');
            if (!in_array($criterion, ['explicit_rank', 'score_metric', 'time_metric', 'tie_breaker', 'manual_adjudication'], true)) {
                throw new ValidationException('Invalid advancement criterion.', ['criterion' => $criterion]);
            }
            EventAdvancementRule::query()->create([
                'event_version_id' => (int) $version->id,
                'precedence' => $precedence,
                'criterion' => $criterion,
                'criterion_config_json' => isset($rule['criterion_config']) ? json_encode($rule['criterion_config']) : null,
                'description' => isset($rule['description']) ? mb_substr((string) $rule['description'], 0, 255) : null,
            ]);
        }
    }

    private function assertNoOverlap(string $eventId, EventVersion $candidate): void
    {
        if ($candidate->effective_from === null || $candidate->effective_to === null) {
            return; // open-ended; cannot overlap by default
        }
        $overlap = EventVersion::query()
            ->where('event_id', $eventId)
            ->where('id', '!=', $candidate->id)
            ->where('status', 'published')
            ->whereNotNull('effective_from')
            ->whereNotNull('effective_to')
            ->where('effective_from', '<', $candidate->effective_to)
            ->where('effective_to', '>', $candidate->effective_from)
            ->exists();
        if ($overlap) {
            throw new ConflictException('Effective window overlaps an active published version.', 'EFFECTIVE_OVERLAP');
        }
    }

    private function findEventOr404(string $eventId): Event
    {
        $e = Event::query()->find($eventId);
        if (!$e instanceof Event) {
            throw new NotFoundException('Event not found.');
        }
        return $e;
    }

    private function findVersionOr404(string $eventId, int $versionId): EventVersion
    {
        $v = EventVersion::query()->where('event_id', $eventId)->where('id', $versionId)->first();
        if (!$v instanceof EventVersion) {
            throw new NotFoundException('Version not found.');
        }
        return $v;
    }

    private function requireDraftPerm(User $actor): void
    {
        if (UserPermissions::hasPermission($actor, 'events.draft') || $this->policy->isAdministrator($actor)) {
            return;
        }
        throw new AuthorizationException('Missing permission: events.draft');
    }
}
