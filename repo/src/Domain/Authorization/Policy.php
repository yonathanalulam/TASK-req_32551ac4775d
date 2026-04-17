<?php

declare(strict_types=1);

namespace Meridian\Domain\Authorization;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Domain\Content\Content;
use Meridian\Domain\Events\Event;
use Meridian\Domain\Events\EventVersion;
use Meridian\Domain\Moderation\ModerationCase;
use Meridian\Domain\Reports\GeneratedReport;

/**
 * Centralized object-level authorization.
 *
 * Responsibility (fix-prompt 4.1):
 *   Decide whether a given user may perform a given action against a specific object.
 *
 * Two-layer check:
 *   1. capability check (delegates to UserPermissions::hasPermission)
 *   2. scope check (resolves user_role_bindings.scope_type/scope_ref against the object)
 *
 * Scope semantics:
 *   - administrator role has global scope across every protected resource
 *   - bindings with scope_type = null (or 'global') grant the capability globally
 *   - bindings with scope_type = 'content' grant per-content access; scope_ref = content_id
 *   - bindings with scope_type = 'event_family' grant access to events whose
 *     event_family_key matches scope_ref
 *   - bindings with scope_type = 'moderation_reviewer' grant access to cases assigned to
 *     the user (scope_ref ignored; binding presence is the signal)
 *   - bindings with scope_type = 'report' grant access to a specific generated_report_id
 *
 * List filtering:
 *   filterContentIds / filterEventIds / filterModerationCaseIds return the subset the user
 *   may see so list/search endpoints never leak unauthorized objects.
 *
 * Deny-by-default:
 *   every `can*` method returns false unless an explicit allow is found. Write capabilities
 *   additionally require that authentication is real (anonymous users return false).
 */
final class Policy
{
    /** @param array<int,string> $needAny any of these permissions satisfies the capability check */
    public function hasCapability(User $user, array $needAny): bool
    {
        foreach ($needAny as $perm) {
            if (UserPermissions::hasPermission($user, $perm)) {
                return true;
            }
        }
        return false;
    }

    public function isAdministrator(User $user): bool
    {
        return UserPermissions::hasRole($user, 'administrator');
    }

    // ---- Content ----------------------------------------------------------------

    public function canViewContent(User $user, Content $content): bool
    {
        if (!UserPermissions::hasPermission($user, 'content.view')) {
            return false;
        }
        if ($this->isAdministrator($user)) {
            return true;
        }
        // Learners only see content that is safe to surface.
        $readableRiskStates = ['normalized', 'published_safe'];
        if (in_array($content->risk_state, $readableRiskStates, true)) {
            return true;
        }
        // Reviewers/instructors with a content-scoped binding can see restricted items too.
        return $this->userHasScopeBinding($user, 'content', $content->content_id)
            || UserPermissions::hasPermission($user, 'moderation.view_cases');
    }

    public function canEditContent(User $user, Content $content): bool
    {
        if (!UserPermissions::hasPermission($user, 'content.edit_metadata')) {
            return false;
        }
        if ($this->isAdministrator($user)) {
            return true;
        }
        if ($this->userHasScopeBinding($user, 'content', $content->content_id)) {
            return true;
        }
        // Ownership shortcut: the ingesting user may edit metadata on records they created
        // until they transition out of normalized/flagged.
        return $content->created_by_user_id !== null
            && (int) $content->created_by_user_id === (int) $user->id
            && in_array($content->risk_state, ['normalized', 'flagged', 'under_review'], true);
    }

    public function canBlacklistContent(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'content.blacklist') || $this->isAdministrator($user);
    }

    public function canMergeContent(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'content.merge') || $this->isAdministrator($user);
    }

    public function canUnmergeContent(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    /**
     * Narrows a candidate list of content_id values to those the user may see.
     * @param array<int,string> $contentIds
     * @return array<int,string>
     */
    public function filterContentIds(User $user, array $contentIds): array
    {
        if ($this->isAdministrator($user) || UserPermissions::hasPermission($user, 'moderation.view_cases')) {
            return $contentIds;
        }
        if (!UserPermissions::hasPermission($user, 'content.view')) {
            return [];
        }
        $allowed = [];
        if ($contentIds !== []) {
            $rows = DB::table('contents')
                ->whereIn('content_id', $contentIds)
                ->get(['content_id', 'risk_state', 'created_by_user_id']);
            foreach ($rows as $row) {
                if (in_array($row->risk_state, ['normalized', 'published_safe'], true)) {
                    $allowed[] = $row->content_id;
                    continue;
                }
                if ((int) ($row->created_by_user_id ?? 0) === (int) $user->id) {
                    $allowed[] = $row->content_id;
                }
            }
        }
        // Add any explicitly scoped bindings.
        $scoped = DB::table('user_role_bindings')
            ->where('user_id', (int) $user->id)
            ->where('scope_type', 'content')
            ->whereIn('scope_ref', $contentIds)
            ->pluck('scope_ref')
            ->all();
        return array_values(array_unique(array_merge($allowed, $scoped)));
    }

    // ---- Moderation cases -------------------------------------------------------

    public function canViewModerationCase(User $user, ModerationCase $case): bool
    {
        if (!UserPermissions::hasPermission($user, 'moderation.view_cases')) {
            return false;
        }
        if ($this->isAdministrator($user)) {
            return true;
        }
        if ($case->assigned_reviewer_id !== null && (int) $case->assigned_reviewer_id === (int) $user->id) {
            return true;
        }
        // Reviewers without an assignment may still see open queues; gate by capability alone.
        return UserPermissions::hasPermission($user, 'moderation.review');
    }

    public function canDecideModerationCase(User $user, ModerationCase $case): bool
    {
        if (!UserPermissions::hasPermission($user, 'moderation.decide')) {
            return false;
        }
        if ($this->isAdministrator($user)) {
            return true;
        }
        // Only the assigned reviewer (or an administrator) may record a decision.
        return $case->assigned_reviewer_id !== null
            && (int) $case->assigned_reviewer_id === (int) $user->id;
    }

    public function canReadPrivateNotes(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'moderation.view_private_notes')
            || $this->isAdministrator($user);
    }

    public function canResolveAppeal(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'moderation.appeal_resolve')
            || $this->isAdministrator($user);
    }

    /**
     * Submitting a moderation report (fix round-2 B).
     *
     * Capability: `moderation.report.create` (explicit — no authentication-only fallback).
     *
     * Object scope: when the report names a `content_id`, the actor must either be an
     * administrator, the content's creator, or pass `canViewContent`. Reports against only
     * a `source_record_id` fall back to capability-only since no content object yet exists
     * to scope against.
     */
    public function canCreateModerationReport(User $user, ?Content $target): bool
    {
        if (!UserPermissions::hasPermission($user, 'moderation.report.create')) {
            return false;
        }
        if ($this->isAdministrator($user)) {
            return true;
        }
        if ($target === null) {
            return true;
        }
        if ($target->created_by_user_id !== null && (int) $target->created_by_user_id === (int) $user->id) {
            return true;
        }
        return $this->canViewContent($user, $target);
    }

    /**
     * Submitting a moderation appeal (fix round-2 C).
     *
     * Capability: `moderation.appeal.create` (explicit — no authentication-only fallback).
     *
     * Object scope (all evaluated against the specific case):
     *   - administrators may appeal any eligible case
     *   - the user that originally filed a report for this case may appeal it
     *   - the creator of the content referenced by the case may appeal it
     *   - a user with an explicit `content` scope binding for the case's content may appeal it
     *
     * Users outside those relationships are denied even if they hold the capability —
     * deny-by-default for object-level writes.
     */
    public function canCreateModerationAppeal(User $user, ModerationCase $case): bool
    {
        if (!UserPermissions::hasPermission($user, 'moderation.appeal.create')) {
            return false;
        }
        if ($this->isAdministrator($user)) {
            return true;
        }
        $reporterMatch = DB::table('moderation_reports')
            ->where('case_id', $case->id)
            ->where('reporter_user_id', (int) $user->id)
            ->exists();
        if ($reporterMatch) {
            return true;
        }
        if ($case->content_id !== null) {
            $contentOwnerId = DB::table('contents')
                ->where('content_id', (string) $case->content_id)
                ->value('created_by_user_id');
            if ($contentOwnerId !== null && (int) $contentOwnerId === (int) $user->id) {
                return true;
            }
            if ($this->userHasScopeBinding($user, 'content', (string) $case->content_id)) {
                return true;
            }
        }
        return false;
    }

    // ---- Events -----------------------------------------------------------------

    public function canViewEvent(User $user, Event $event): bool
    {
        if ($this->isAdministrator($user)) {
            return true;
        }
        if ($this->userHasScopeBinding($user, 'event_family', (string) $event->event_family_key)) {
            return true;
        }
        // All authenticated users may see the catalog of events; scope applies to mutations.
        return UserPermissions::hasPermission($user, 'events.draft')
            || UserPermissions::hasPermission($user, 'analytics.query')
            || UserPermissions::hasPermission($user, 'moderation.view_cases')
            || UserPermissions::hasPermission($user, 'content.view');
    }

    public function canEditEvent(User $user, Event $event): bool
    {
        if ($this->isAdministrator($user)) {
            return true;
        }
        if (!UserPermissions::hasPermission($user, 'events.draft')) {
            return false;
        }
        if ($this->userHasScopeBinding($user, 'event_family', (string) $event->event_family_key)) {
            return true;
        }
        // Fallback: the creator of the event draft may continue editing their own drafts.
        return $event->created_by_user_id !== null
            && (int) $event->created_by_user_id === (int) $user->id;
    }

    public function canPublishEvent(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'events.publish') || $this->isAdministrator($user);
    }

    public function canRollbackEvent(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'events.rollback') || $this->isAdministrator($user);
    }

    public function canCancelEvent(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'events.cancel') || $this->isAdministrator($user);
    }

    public function canManageEventBindings(User $user, Event $event): bool
    {
        if (!UserPermissions::hasPermission($user, 'events.manage_bindings') && !$this->isAdministrator($user)) {
            return false;
        }
        if ($this->isAdministrator($user)) {
            return true;
        }
        return $this->userHasScopeBinding($user, 'event_family', (string) $event->event_family_key)
            || ($event->created_by_user_id !== null && (int) $event->created_by_user_id === (int) $user->id);
    }

    public function canViewEventVersion(User $user, Event $event, EventVersion $_version): bool
    {
        return $this->canViewEvent($user, $event);
    }

    /** @param array<int,string> $eventIds @return array<int,string> */
    public function filterEventIds(User $user, array $eventIds): array
    {
        if ($this->isAdministrator($user) || UserPermissions::hasPermission($user, 'analytics.query')) {
            return $eventIds;
        }
        $allowed = DB::table('events')->whereIn('event_id', $eventIds)
            ->where('created_by_user_id', (int) $user->id)->pluck('event_id')->all();
        $scoped = DB::table('events')
            ->whereIn('event_id', $eventIds)
            ->whereIn('event_family_key', DB::table('user_role_bindings')
                ->where('user_id', (int) $user->id)
                ->where('scope_type', 'event_family')
                ->pluck('scope_ref'))
            ->pluck('event_id')->all();
        return array_values(array_unique(array_merge($allowed, $scoped)));
    }

    // ---- Reports & analytics ----------------------------------------------------

    public function canViewReport(User $user, GeneratedReport $report): bool
    {
        if ($this->isAdministrator($user)) {
            return true;
        }
        if (!UserPermissions::hasPermission($user, 'governance.export_reports')) {
            return false;
        }
        if ($report->requested_by_user_id !== null && (int) $report->requested_by_user_id === (int) $user->id) {
            return true;
        }
        return $this->userHasScopeBinding($user, 'report', (string) $report->id);
    }

    public function canDownloadReport(User $user, GeneratedReport $report): bool
    {
        if (!$this->canViewReport($user, $report)) {
            return false;
        }
        // Unmasked exports require either the explicit `sensitive.unmask` capability or the
        // administrator role. Admin is treated as an implicit unmasker because governance
        // routinely needs to audit content it authored but didn't explicitly bind a scope to.
        if ((bool) $report->unmasked
            && !$this->isAdministrator($user)
            && !UserPermissions::hasPermission($user, 'sensitive.unmask')
        ) {
            return false;
        }
        return true;
    }

    public function canCreateScheduledReport(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'governance.export_reports')
            || $this->isAdministrator($user);
    }

    public function canViewProtectedAnalytics(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'analytics.query') || $this->isAdministrator($user);
    }

    /**
     * Apply scope-aware filtering for analytics queries. Admins bypass; all other
     * callers see only events that (a) they authored as actor or (b) reference a
     * content/event object they may view. Call this BEFORE aggregation so counts
     * never include unauthorized objects.
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     */
    public function applyAnalyticsScope(User $user, $query): void
    {
        if ($this->isAdministrator($user)) {
            return;
        }
        $userId = (int) $user->id;
        $allowedContent = DB::table('contents')
            ->where(function ($cc) use ($userId) {
                $cc->whereIn('risk_state', ['normalized', 'published_safe'])
                    ->orWhere('created_by_user_id', $userId)
                    ->orWhereIn('content_id', DB::table('user_role_bindings')
                        ->where('user_id', $userId)
                        ->where('scope_type', 'content')
                        ->select('scope_ref'));
            })
            ->select('content_id');

        $allowedEvents = DB::table('events')
            ->where(function ($ee) use ($userId) {
                $ee->where('created_by_user_id', $userId)
                    ->orWhereIn('event_family_key', DB::table('user_role_bindings')
                        ->where('user_id', $userId)
                        ->where('scope_type', 'event_family')
                        ->select('scope_ref'));
            })
            ->select('event_id');

        $query->where(function ($q) use ($userId, $allowedContent, $allowedEvents) {
            $q->where(function ($qq) use ($userId) {
                $qq->where('actor_type', 'user')->where('actor_id', (string) $userId);
            });
            $q->orWhere(function ($qq) use ($allowedContent) {
                $qq->where('object_type', 'content')->whereIn('object_id', $allowedContent);
            });
            $q->orWhere(function ($qq) use ($allowedEvents) {
                $qq->where('object_type', 'event')->whereIn('object_id', $allowedEvents);
            });
        });
    }

    /**
     * Convenience: returns the list of content_ids this user is permitted to see
     * for analytics exclusion/inclusion decisions. Admin returns null (unrestricted).
     *
     * @return array<int,string>|null
     */
    public function visibleContentIdsForAnalytics(User $user): ?array
    {
        if ($this->isAdministrator($user)) {
            return null;
        }
        $userId = (int) $user->id;
        return DB::table('contents')
            ->where(function ($cc) use ($userId) {
                $cc->whereIn('risk_state', ['normalized', 'published_safe'])
                    ->orWhere('created_by_user_id', $userId)
                    ->orWhereIn('content_id', DB::table('user_role_bindings')
                        ->where('user_id', $userId)
                        ->where('scope_type', 'content')
                        ->select('scope_ref'));
            })
            ->pluck('content_id')->map(fn($v) => (string) $v)->all();
    }

    public function canUnmaskAnalytics(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'analytics.view_unmasked')
            || UserPermissions::hasPermission($user, 'sensitive.unmask')
            || $this->isAdministrator($user);
    }

    public function canIngestAnalytics(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'analytics.ingest')
            || $this->isAdministrator($user);
    }

    // ---- Governance -------------------------------------------------------------

    public function canManageBlacklists(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'governance.manage_blacklists')
            || $this->isAdministrator($user);
    }

    public function canViewAudit(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'governance.view_audit')
            || $this->isAdministrator($user);
    }

    public function canManageUsers(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'auth.manage_users')
            || $this->isAdministrator($user);
    }

    public function canManageRoles(User $user): bool
    {
        return UserPermissions::hasPermission($user, 'auth.manage_roles')
            || $this->isAdministrator($user);
    }

    // ---- Internals --------------------------------------------------------------

    private function userHasScopeBinding(User $user, string $scopeType, string $scopeRef): bool
    {
        return DB::table('user_role_bindings')
            ->where('user_id', (int) $user->id)
            ->where('scope_type', $scopeType)
            ->where('scope_ref', $scopeRef)
            ->exists();
    }
}
