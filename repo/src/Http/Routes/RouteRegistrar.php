<?php

declare(strict_types=1);

namespace Meridian\Http\Routes;

use Meridian\Http\Controllers\AnalyticsController;
use Meridian\Http\Controllers\AuditController;
use Meridian\Http\Controllers\AuthController;
use Meridian\Http\Controllers\BlacklistController;
use Meridian\Http\Controllers\ContentController;
use Meridian\Http\Controllers\DedupController;
use Meridian\Http\Controllers\EventController;
use Meridian\Http\Controllers\HealthController;
use Meridian\Http\Controllers\ModerationController;
use Meridian\Http\Controllers\ReportController;
use Meridian\Http\Controllers\RulePackController;
use Meridian\Http\Controllers\UserAdminController;
use Slim\App;

final class RouteRegistrar
{
    public static function register(App $app): void
    {
        $app->group('/api/v1', function ($g) {
            $g->get('/health', [HealthController::class, 'get']);

            $g->get('/auth/security-questions', [AuthController::class, 'publicSecurityQuestions']);
            $g->post('/auth/signup', [AuthController::class, 'signup']);
            $g->post('/auth/login', [AuthController::class, 'login']);
            $g->post('/auth/logout', [AuthController::class, 'logout']);
            $g->post('/auth/password-reset/begin', [AuthController::class, 'beginReset']);
            $g->post('/auth/password-reset/complete', [AuthController::class, 'completeReset']);
            $g->get('/auth/me', [AuthController::class, 'me']);

            // Admin: users and roles
            $g->post('/admin/users', [UserAdminController::class, 'create']);
            $g->get('/admin/users', [UserAdminController::class, 'list']);
            $g->get('/admin/users/{id}', [UserAdminController::class, 'get']);
            $g->patch('/admin/users/{id}', [UserAdminController::class, 'update']);
            $g->post('/admin/users/{id}/role-bindings', [UserAdminController::class, 'assignRole']);
            $g->delete('/admin/users/{id}/role-bindings/{bindingId}', [UserAdminController::class, 'removeRole']);
            $g->post('/admin/users/{id}/password-reset', [UserAdminController::class, 'adminReset']);
            $g->post('/admin/users/{id}/security-answers', [UserAdminController::class, 'setSecurityAnswers']);
            $g->get('/admin/security-questions', [UserAdminController::class, 'listSecurityQuestions']);

            // Blacklists
            $g->get('/blacklists', [BlacklistController::class, 'list']);
            $g->post('/blacklists', [BlacklistController::class, 'add']);
            $g->delete('/blacklists/{id}', [BlacklistController::class, 'revoke']);

            // Audit
            $g->get('/audit/logs', [AuditController::class, 'list']);
            $g->get('/audit/chain', [AuditController::class, 'chain']);
            $g->get('/audit/chain/verify', [AuditController::class, 'verify']);

            // Content
            $g->post('/content/parse', [ContentController::class, 'parse']);
            $g->get('/content', [ContentController::class, 'search']);
            $g->get('/content/{id}', [ContentController::class, 'get']);
            $g->patch('/content/{id}', [ContentController::class, 'updateMetadata']);

            // Dedup
            $g->get('/dedup/candidates', [DedupController::class, 'list']);
            $g->post('/dedup/merge', [DedupController::class, 'merge']);
            $g->post('/dedup/unmerge', [DedupController::class, 'unmerge']);
            $g->post('/dedup/recompute', [DedupController::class, 'recompute']);

            // Rule packs
            $g->get('/rule-packs', [RulePackController::class, 'list']);
            $g->post('/rule-packs', [RulePackController::class, 'create']);
            $g->post('/rule-packs/{id}/versions', [RulePackController::class, 'createVersion']);
            $g->post('/rule-packs/versions/{versionId}/rules', [RulePackController::class, 'addRule']);
            $g->post('/rule-packs/versions/{versionId}/publish', [RulePackController::class, 'publishVersion']);
            $g->post('/rule-packs/versions/{versionId}/archive', [RulePackController::class, 'archiveVersion']);
            $g->get('/rule-packs/versions/{versionId}', [RulePackController::class, 'getVersion']);

            // Moderation
            $g->get('/moderation/cases', [ModerationController::class, 'list']);
            $g->get('/moderation/cases/{id}', [ModerationController::class, 'get']);
            $g->post('/moderation/cases', [ModerationController::class, 'create']);
            $g->post('/moderation/cases/{id}/assign', [ModerationController::class, 'assign']);
            $g->post('/moderation/cases/{id}/transition', [ModerationController::class, 'transition']);
            $g->post('/moderation/cases/{id}/decisions', [ModerationController::class, 'decide']);
            $g->post('/moderation/cases/{id}/notes', [ModerationController::class, 'addNote']);
            $g->get('/moderation/cases/{id}/notes', [ModerationController::class, 'listNotes']);
            $g->post('/moderation/reports', [ModerationController::class, 'submitReport']);
            $g->post('/moderation/cases/{id}/appeal', [ModerationController::class, 'submitAppeal']);
            $g->post('/moderation/cases/{id}/appeal/resolve', [ModerationController::class, 'resolveAppeal']);

            // Events
            $g->post('/events', [EventController::class, 'create']);
            $g->get('/events', [EventController::class, 'list']);
            $g->get('/events/{id}', [EventController::class, 'get']);
            $g->post('/events/{id}/versions', [EventController::class, 'createDraftVersion']);
            $g->patch('/events/{id}/versions/{versionId}', [EventController::class, 'updateDraft']);
            $g->post('/events/{id}/versions/{versionId}/publish', [EventController::class, 'publishVersion']);
            $g->post('/events/{id}/versions/{versionId}/rollback', [EventController::class, 'rollbackVersion']);
            $g->post('/events/{id}/versions/{versionId}/cancel', [EventController::class, 'cancelVersion']);
            $g->get('/events/{id}/versions/{versionId}', [EventController::class, 'getVersion']);
            $g->post('/events/{id}/versions/{versionId}/bindings', [EventController::class, 'addBinding']);

            // Analytics
            $g->post('/analytics/events', [AnalyticsController::class, 'ingest']);
            $g->get('/analytics/events', [AnalyticsController::class, 'query']);
            $g->post('/analytics/funnel', [AnalyticsController::class, 'funnel']);
            $g->get('/analytics/kpis', [AnalyticsController::class, 'kpis']);

            // Reports
            $g->post('/reports/scheduled', [ReportController::class, 'createScheduled']);
            $g->get('/reports/scheduled', [ReportController::class, 'listScheduled']);
            $g->post('/reports/scheduled/{id}/run', [ReportController::class, 'runNow']);
            $g->get('/reports/generated', [ReportController::class, 'listGenerated']);
            $g->get('/reports/generated/{id}', [ReportController::class, 'getGenerated']);
            $g->get('/reports/generated/{id}/download', [ReportController::class, 'download']);
        });
    }
}
