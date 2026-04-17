<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

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
 * Moderation case lifecycle, notes, decisions, reports, appeals.
 *
 * Enforces:
 * - single active appeal per case (moderation_cases.has_active_appeal flag + immutable decision history)
 * - immutable decision history (every transition appends a new moderation_decisions row)
 * - SLA due-at computed using business-hours settings from config.
 */
final class ModerationService
{
    /** @param array<string,mixed> $slaConfig */
    public function __construct(
        private readonly Clock $clock,
        private readonly RuleEvaluator $evaluator,
        private readonly AuditLogger $audit,
        private readonly array $slaConfig,
        private readonly Policy $policy,
    ) {
    }

    public function createAutomatedCase(
        string $contentId,
        int $rulePackVersionId,
        array $flagEvidence,
        string $reasonCode = 'rule_match',
    ): ModerationCase {
        $now = $this->clock->nowUtc();
        $caseId = Uuid::uuid4()->toString();
        return DB::connection()->transaction(function () use ($now, $caseId, $contentId, $rulePackVersionId, $flagEvidence, $reasonCode) {
            /** @var ModerationCase $case */
            $case = ModerationCase::query()->create([
                'id' => $caseId,
                'content_id' => $contentId,
                'case_type' => 'automated_flag',
                'status' => 'open',
                'reason_code' => $reasonCode,
                'decision' => 'pending',
                'rule_pack_version_id' => $rulePackVersionId,
                'opened_at' => $now->format('Y-m-d H:i:s'),
                'sla_due_at' => $this->computeSlaDueAt($now)->format('Y-m-d H:i:s'),
            ]);
            foreach ($flagEvidence as $flag) {
                ModerationCaseFlag::query()->create([
                    'case_id' => $caseId,
                    'rule_pack_version_id' => $rulePackVersionId,
                    'rule_id' => $flag['rule_id'] ?? null,
                    'rule_kind' => (string) ($flag['rule_kind'] ?? ''),
                    'evidence_json' => json_encode($flag['evidence'] ?? []),
                    'created_at' => $now->format('Y-m-d H:i:s'),
                ]);
            }
            $this->audit->record('moderation.case_opened', 'moderation_case', $caseId, [
                'content_id' => $contentId,
                'case_type' => 'automated_flag',
                'rule_pack_version_id' => $rulePackVersionId,
                'flag_count' => count($flagEvidence),
            ], actorType: 'system', actorId: 'moderation');
            return $case;
        });
    }

    public function createCase(User $actor, array $input): ModerationCase
    {
        if (!UserPermissions::hasPermission($actor, 'moderation.review') && !$this->policy->isAdministrator($actor)) {
            throw new AuthorizationException('Missing permission: moderation.review');
        }
        $now = $this->clock->nowUtc();
        $caseId = Uuid::uuid4()->toString();
        /** @var ModerationCase $case */
        $case = ModerationCase::query()->create([
            'id' => $caseId,
            'content_id' => isset($input['content_id']) ? (string) $input['content_id'] : null,
            'source_record_id' => isset($input['source_record_id']) ? (string) $input['source_record_id'] : null,
            'case_type' => 'manual_submission',
            'status' => 'open',
            'reason_code' => (string) ($input['reason_code'] ?? 'manual'),
            'decision' => 'pending',
            'opened_at' => $now->format('Y-m-d H:i:s'),
            'sla_due_at' => $this->computeSlaDueAt($now)->format('Y-m-d H:i:s'),
        ]);
        $this->audit->record('moderation.case_opened', 'moderation_case', $caseId, [
            'content_id' => $case->content_id,
            'case_type' => 'manual_submission',
            'reason_code' => $case->reason_code,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $case;
    }

    public function assign(User $actor, string $caseId, int $reviewerUserId): ModerationCase
    {
        if (!UserPermissions::hasPermission($actor, 'moderation.review') && !$this->policy->isAdministrator($actor)) {
            throw new AuthorizationException('Missing permission: moderation.review');
        }
        $case = $this->findOr404($caseId);
        if (!$this->policy->canViewModerationCase($actor, $case)) {
            throw new AuthorizationException('Not authorized to modify this moderation case.');
        }
        $case->assigned_reviewer_id = $reviewerUserId;
        $case->save();
        $this->audit->record('moderation.case_assigned', 'moderation_case', $caseId, [
            'reviewer_id' => $reviewerUserId,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $case;
    }

    public function transition(User $actor, string $caseId, string $toStatus): ModerationCase
    {
        if (!UserPermissions::hasPermission($actor, 'moderation.review') && !$this->policy->isAdministrator($actor)) {
            throw new AuthorizationException('Missing permission: moderation.review');
        }
        $case = $this->findOr404($caseId);
        if (!$this->policy->canViewModerationCase($actor, $case)) {
            throw new AuthorizationException('Not authorized to modify this moderation case.');
        }
        $this->validateStatusTransition($case->status, $toStatus);
        $case->status = $toStatus;
        if (in_array($toStatus, ['resolved', 'dismissed'], true) && $case->resolved_at === null) {
            $case->resolved_at = $this->clock->nowUtc()->format('Y-m-d H:i:s');
        }
        $case->save();
        $this->audit->record('moderation.case_transitioned', 'moderation_case', $caseId, [
            'to_status' => $toStatus,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $case;
    }

    public function decide(User $actor, string $caseId, string $decision, ?string $reason, array $evidence = []): ModerationDecision
    {
        $case = $this->findOr404($caseId);
        if (!$this->policy->canDecideModerationCase($actor, $case)) {
            throw new AuthorizationException('Not authorized to decide on this moderation case.');
        }
        $this->validateDecision($decision);
        $now = $this->clock->nowUtc();
        return DB::connection()->transaction(function () use ($actor, $case, $decision, $reason, $evidence, $now) {
            /** @var ModerationDecision $record */
            $record = ModerationDecision::query()->create([
                'case_id' => $case->id,
                'decision' => $decision,
                'decision_source' => 'manual',
                'decided_by_user_id' => (int) $actor->id,
                'rule_pack_version_id' => $case->rule_pack_version_id,
                'reason' => $reason,
                'evidence_json' => json_encode($evidence),
                'decided_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $case->decision = in_array($decision, ['approved', 'rejected', 'restricted', 'escalated'], true)
                ? $decision
                : $case->decision;
            if (in_array($decision, ['approved', 'rejected', 'restricted'], true)) {
                $case->status = 'resolved';
                $case->resolved_at = $now->format('Y-m-d H:i:s');
            } elseif ($decision === 'escalated') {
                $case->status = 'escalated';
            } elseif ($decision === 'appeal_upheld') {
                $case->status = 'appeal_upheld';
                $case->resolved_at = $now->format('Y-m-d H:i:s');
                $case->has_active_appeal = false;
            } elseif ($decision === 'appeal_rejected') {
                $case->status = 'appeal_rejected';
                $case->resolved_at = $now->format('Y-m-d H:i:s');
                $case->has_active_appeal = false;
            }
            $case->save();
            $this->audit->record('moderation.decision_recorded', 'moderation_case', $case->id, [
                'decision' => $decision,
                'decision_source' => 'manual',
                'reason_present' => $reason !== null,
            ], actorType: 'user', actorId: (string) $actor->id);
            return $record;
        });
    }

    public function addNote(User $actor, string $caseId, string $note, bool $isPrivate = true): ModerationNote
    {
        if (!UserPermissions::hasPermission($actor, 'moderation.review') && !$this->policy->isAdministrator($actor)) {
            throw new AuthorizationException('Missing permission: moderation.review');
        }
        $case = $this->findOr404($caseId);
        if (!$this->policy->canViewModerationCase($actor, $case)) {
            throw new AuthorizationException('Not authorized to modify this moderation case.');
        }
        /** @var ModerationNote $row */
        $row = ModerationNote::query()->create([
            'case_id' => $caseId,
            'author_user_id' => (int) $actor->id,
            'note' => $note,
            'is_private' => $isPrivate,
            'created_at' => $this->clock->nowUtc()->format('Y-m-d H:i:s'),
        ]);
        $this->audit->record('moderation.note_added', 'moderation_case', $caseId, [
            'note_id' => (int) $row->id,
            'is_private' => $isPrivate,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $row;
    }

    public function listNotes(User $actor, string $caseId): array
    {
        $case = $this->findOr404($caseId);
        if (!$this->policy->canViewModerationCase($actor, $case)) {
            throw new AuthorizationException('Not authorized to view this moderation case.');
        }
        $rows = ModerationNote::query()->where('case_id', $caseId)->orderBy('id')->get()->all();
        $canReadPrivate = $this->policy->canReadPrivateNotes($actor);
        return array_values(array_filter(array_map(static function (ModerationNote $n) use ($canReadPrivate) {
            if ($n->is_private && !$canReadPrivate) {
                return null;
            }
            return [
                'id' => (int) $n->id,
                'author_user_id' => (int) $n->author_user_id,
                'note' => $n->note,
                'is_private' => (bool) $n->is_private,
                'created_at' => $n->created_at,
            ];
        }, $rows)));
    }

    public function submitReport(?User $actor, array $input): ModerationReport
    {
        // Authenticated submission is required for writes; anonymous system-routed reports
        // arrive via trusted internal paths, never via the HTTP layer (controller enforces this).
        if ($actor === null) {
            throw new AuthorizationException('Authentication required to submit a report.');
        }
        // Resolve the target content (when caller supplied one) so Policy can apply
        // object-scope checks before we materialize the row.
        $contentId = isset($input['content_id']) ? (string) $input['content_id'] : null;
        $targetContent = null;
        if ($contentId !== null && $contentId !== '') {
            $targetContent = \Meridian\Domain\Content\Content::query()->find($contentId);
            if (!$targetContent instanceof \Meridian\Domain\Content\Content) {
                throw new ValidationException(
                    'Reported content_id does not exist.',
                    ['field' => 'content_id'],
                );
            }
        }
        if (!$this->policy->canCreateModerationReport($actor, $targetContent)) {
            $this->audit->record('moderation.report_denied', 'moderation_report', null, [
                'actor_id' => (int) $actor->id,
                'content_id' => $contentId,
                'reason' => 'authorization_denied',
            ], actorType: 'user', actorId: (string) $actor->id);
            throw new AuthorizationException('Not authorized to submit a moderation report.');
        }
        $now = $this->clock->nowUtc();
        /** @var ModerationReport $r */
        $r = ModerationReport::query()->create([
            'case_id' => null,
            'content_id' => $contentId,
            'source_record_id' => isset($input['source_record_id']) ? (string) $input['source_record_id'] : null,
            'reporter_user_id' => $actor?->id,
            'reporter_type' => $actor instanceof User ? 'user' : 'system',
            'reason_code' => (string) ($input['reason_code'] ?? 'unspecified'),
            'details' => isset($input['details']) ? (string) $input['details'] : null,
            'sla_due_at' => $this->computeSlaDueAt($now)->format('Y-m-d H:i:s'),
            'status' => 'received',
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $this->audit->record('moderation.report_submitted', 'moderation_report', (string) $r->id, [
            'content_id' => $r->content_id,
            'reason_code' => $r->reason_code,
        ], actorType: $actor instanceof User ? 'user' : 'system', actorId: $actor instanceof User ? (string) $actor->id : 'anonymous');
        return $r;
    }

    public function submitAppeal(User $actor, string $caseId, string $rationale): ModerationAppeal
    {
        $case = $this->findOr404($caseId);
        // Authorization precedes lifecycle checks so callers without the capability or scope
        // linkage never learn whether the case is even appealable.
        if (!$this->policy->canCreateModerationAppeal($actor, $case)) {
            $this->audit->record('moderation.appeal_denied', 'moderation_case', $case->id, [
                'actor_id' => (int) $actor->id,
                'reason' => 'authorization_denied',
            ], actorType: 'user', actorId: (string) $actor->id);
            throw new AuthorizationException('Not authorized to appeal this moderation case.');
        }
        // Uniqueness wins over state: once an appeal is active the case has moved from
        // `resolved` to `appealed`, so a naive status check would mis-report CASE_NOT_RESOLVED
        // for a duplicate attempt. The uniqueness check runs first to preserve the
        // deterministic APPEAL_ACTIVE response.
        if ($case->has_active_appeal) {
            throw new ConflictException('An active appeal already exists for this case.', 'APPEAL_ACTIVE');
        }
        if (!in_array($case->status, ['resolved'], true)) {
            throw new ConflictException('Appeals require a resolved case.', 'CASE_NOT_RESOLVED');
        }
        $now = $this->clock->nowUtc();
        return DB::connection()->transaction(function () use ($actor, $case, $rationale, $now) {
            /** @var ModerationAppeal $a */
            $a = ModerationAppeal::query()->create([
                'case_id' => $case->id,
                'appellant_user_id' => (int) $actor->id,
                'status' => 'submitted',
                'rationale' => $rationale,
                'submitted_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $case->status = 'appealed';
            $case->has_active_appeal = true;
            $case->save();
            $this->audit->record('moderation.appeal_submitted', 'moderation_case', $case->id, [
                'appeal_id' => (int) $a->id,
            ], actorType: 'user', actorId: (string) $actor->id);
            return $a;
        });
    }

    public function resolveAppeal(User $actor, string $caseId, string $outcome, ?string $reason): ModerationAppeal
    {
        if (!$this->policy->canResolveAppeal($actor)) {
            throw new AuthorizationException('Missing permission: moderation.appeal_resolve');
        }
        if (!in_array($outcome, ['upheld', 'rejected'], true)) {
            throw new ValidationException('outcome must be upheld or rejected.', ['field' => 'outcome']);
        }
        $case = $this->findOr404($caseId);
        /** @var ModerationAppeal|null $appeal */
        $appeal = ModerationAppeal::query()
            ->where('case_id', $caseId)
            ->whereIn('status', ['submitted', 'in_review'])
            ->orderByDesc('id')
            ->first();
        if (!$appeal instanceof ModerationAppeal) {
            throw new NotFoundException('No active appeal.');
        }

        $now = $this->clock->nowUtc();
        return DB::connection()->transaction(function () use ($actor, $case, $appeal, $outcome, $reason, $now) {
            $appeal->status = $outcome;
            $appeal->resolved_at = $now->format('Y-m-d H:i:s');
            $appeal->resolved_by_user_id = (int) $actor->id;
            $appeal->resolution_reason = $reason !== null ? mb_substr($reason, 0, 1000, 'UTF-8') : null;
            $appeal->save();

            $decision = $outcome === 'upheld' ? 'appeal_upheld' : 'appeal_rejected';
            ModerationDecision::query()->create([
                'case_id' => $case->id,
                'decision' => $decision,
                'decision_source' => 'manual',
                'decided_by_user_id' => (int) $actor->id,
                'rule_pack_version_id' => $case->rule_pack_version_id,
                'reason' => $reason,
                'evidence_json' => json_encode(['appeal_id' => (int) $appeal->id]),
                'decided_at' => $now->format('Y-m-d H:i:s'),
            ]);

            $case->status = $decision;
            $case->resolved_at = $now->format('Y-m-d H:i:s');
            $case->has_active_appeal = false;
            $case->save();

            $this->audit->record('moderation.appeal_resolved', 'moderation_case', $case->id, [
                'appeal_id' => (int) $appeal->id,
                'outcome' => $outcome,
            ], actorType: 'user', actorId: (string) $actor->id);
            return $appeal;
        });
    }

    public function list(User $actor, array $filters, int $page, int $pageSize): array
    {
        if (!UserPermissions::hasPermission($actor, 'moderation.view_cases')) {
            throw new AuthorizationException('Missing permission: moderation.view_cases');
        }
        $q = ModerationCase::query()->orderByDesc('opened_at');
        // Non-admin reviewers without the broad review permission only see cases assigned to them.
        if (!$this->policy->isAdministrator($actor) && !UserPermissions::hasPermission($actor, 'moderation.review')) {
            $q->where('assigned_reviewer_id', (int) $actor->id);
        }
        if (!empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }
        if (!empty($filters['decision'])) {
            $q->where('decision', (string) $filters['decision']);
        }
        if (!empty($filters['assignee'])) {
            $q->where('assigned_reviewer_id', (int) $filters['assignee']);
        }
        if (!empty($filters['content_id'])) {
            $q->where('content_id', (string) $filters['content_id']);
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $pageSize)->get()->all();
        return ['items' => array_map(static fn(ModerationCase $c) => [
            'id' => $c->id,
            'content_id' => $c->content_id,
            'status' => $c->status,
            'decision' => $c->decision,
            'case_type' => $c->case_type,
            'rule_pack_version_id' => $c->rule_pack_version_id,
            'assigned_reviewer_id' => $c->assigned_reviewer_id,
            'opened_at' => $c->opened_at?->format(DATE_ATOM),
            'sla_due_at' => $c->sla_due_at?->format(DATE_ATOM),
            'resolved_at' => $c->resolved_at?->format(DATE_ATOM),
            'has_active_appeal' => (bool) $c->has_active_appeal,
        ], $rows), 'total' => $total];
    }

    public function get(User $actor, string $caseId): array
    {
        $case = $this->findOr404($caseId);
        if (!$this->policy->canViewModerationCase($actor, $case)) {
            throw new AuthorizationException('Not authorized to view this moderation case.');
        }
        $flags = ModerationCaseFlag::query()->where('case_id', $caseId)->orderBy('id')->get();
        $decisions = ModerationDecision::query()->where('case_id', $caseId)->orderBy('id')->get();
        return [
            'id' => $case->id,
            'content_id' => $case->content_id,
            'status' => $case->status,
            'decision' => $case->decision,
            'case_type' => $case->case_type,
            'rule_pack_version_id' => $case->rule_pack_version_id,
            'assigned_reviewer_id' => $case->assigned_reviewer_id,
            'opened_at' => $case->opened_at?->format(DATE_ATOM),
            'sla_due_at' => $case->sla_due_at?->format(DATE_ATOM),
            'resolved_at' => $case->resolved_at?->format(DATE_ATOM),
            'has_active_appeal' => (bool) $case->has_active_appeal,
            'flags' => $flags->map(static fn($f) => [
                'id' => (int) $f->id,
                'rule_id' => $f->rule_id,
                'rule_kind' => $f->rule_kind,
                'evidence' => json_decode((string) $f->evidence_json, true),
            ])->all(),
            'decisions' => $decisions->map(static fn($d) => [
                'id' => (int) $d->id,
                'decision' => $d->decision,
                'decision_source' => $d->decision_source,
                'decided_by_user_id' => $d->decided_by_user_id,
                'reason' => $d->reason,
                'decided_at' => $d->decided_at,
            ])->all(),
        ];
    }

    public function computeSlaDueAt(DateTimeImmutable $from): DateTimeImmutable
    {
        $hours = (int) ($this->slaConfig['moderation_initial_hours'] ?? 24);
        $bh = $this->slaConfig['business_hours'];
        $weekdays = array_map('intval', (array) $bh['weekdays']);
        [$sh, $sm] = array_map('intval', explode(':', (string) $bh['start']));
        [$eh, $em] = array_map('intval', explode(':', (string) $bh['end']));
        $bhPerDay = ($eh * 60 + $em) - ($sh * 60 + $sm);
        $hoursPerDay = $bhPerDay / 60;
        if ($hoursPerDay <= 0) {
            return $from->modify('+' . $hours . ' hours');
        }
        $remainingHours = (float) $hours;
        $cursor = $from;
        $tz = $from->getTimezone();
        while ($remainingHours > 0) {
            $dayOfWeek = (int) $cursor->format('w');
            if (!in_array($dayOfWeek, $weekdays, true)) {
                $cursor = $cursor->modify('+1 day')->setTime($sh, $sm, 0);
                continue;
            }
            $dayStart = $cursor->setTime($sh, $sm, 0);
            $dayEnd = $cursor->setTime($eh, $em, 0);
            if ($cursor < $dayStart) {
                $cursor = $dayStart;
            }
            if ($cursor >= $dayEnd) {
                $cursor = $cursor->modify('+1 day')->setTime($sh, $sm, 0);
                continue;
            }
            $availableSeconds = $dayEnd->getTimestamp() - $cursor->getTimestamp();
            $neededSeconds = (int) ($remainingHours * 3600);
            if ($neededSeconds <= $availableSeconds) {
                return $cursor->modify('+' . $neededSeconds . ' seconds');
            }
            $remainingHours -= $availableSeconds / 3600;
            $cursor = $cursor->modify('+1 day')->setTime($sh, $sm, 0);
        }
        return $cursor;
    }

    private function findOr404(string $caseId): ModerationCase
    {
        $c = ModerationCase::query()->find($caseId);
        if (!$c instanceof ModerationCase) {
            throw new NotFoundException('Moderation case not found.');
        }
        return $c;
    }

    private function validateStatusTransition(string $from, string $to): void
    {
        $allowed = [
            'open' => ['in_review', 'dismissed', 'resolved'],
            'in_review' => ['escalated', 'resolved', 'dismissed'],
            'escalated' => ['in_review', 'resolved'],
            'resolved' => ['appealed'],
            'appealed' => ['appeal_in_review', 'appeal_rejected', 'appeal_upheld'],
            'appeal_in_review' => ['appeal_rejected', 'appeal_upheld'],
            'dismissed' => [],
            'appeal_rejected' => [],
            'appeal_upheld' => [],
        ];
        if (!isset($allowed[$from]) || !in_array($to, $allowed[$from], true)) {
            throw new ValidationException("Illegal case transition {$from} -> {$to}.");
        }
    }

    private function validateDecision(string $decision): void
    {
        $allowed = ['approved', 'rejected', 'restricted', 'escalated', 'appeal_upheld', 'appeal_rejected'];
        if (!in_array($decision, $allowed, true)) {
            throw new ValidationException('Invalid decision.', ['field' => 'decision', 'allowed' => $allowed]);
        }
    }
}
