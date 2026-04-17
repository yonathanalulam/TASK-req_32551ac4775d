<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Moderation;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Domain\Auth\User;
use Meridian\Tests\Integration\IntegrationTestCase;

final class AppealAuthorizationTest extends IntegrationTestCase
{
    /**
     * Creates a fully-resolved case owned by a content author with a prior report from
     * $reporter. The returned array has the content_id, case_id, and both user ids so the
     * individual tests can exercise scope-based authorization.
     */
    private function resolveCase(User $reporter, string $authorName = 'author'): array
    {
        $author = $this->createUser($authorName, 'instructor');
        $authorToken = $this->login($authorName);
        $parse = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => 'Appeal test article',
            'payload' => $this->englishBody(),
            'media_source' => 'article',
        ], $this->bearer($authorToken));
        $contentId = $this->decode($parse)['data']['content']['content_id'];

        $reporterToken = $this->login($reporter->username);
        $reportResp = $this->request('POST', '/api/v1/moderation/reports', [
            'content_id' => $contentId,
            'reason_code' => 'spam',
        ], $this->bearer($reporterToken));
        self::assertSame(201, $reportResp->getStatusCode(), (string) $reportResp->getBody());

        // Administrator creates a moderation case tied to the content and records a decision
        // so the case becomes eligible for appeal.
        $this->createUser('caseadmin', 'administrator');
        $adminToken = $this->login('caseadmin');
        $caseResp = $this->request('POST', '/api/v1/moderation/cases', [
            'content_id' => $contentId,
            'reason_code' => 'manual_review',
        ], $this->bearer($adminToken));
        $caseId = $this->decode($caseResp)['data']['id'];

        // Link the prior report to this case so reporter-scope authorization works.
        DB::table('moderation_reports')->where('content_id', $contentId)->update(['case_id' => $caseId]);

        // Admin assigns + decides so the case transitions to resolved.
        $this->request('POST', "/api/v1/moderation/cases/{$caseId}/assign", [
            'reviewer_user_id' => DB::table('users')->where('username', 'caseadmin')->value('id'),
        ], $this->bearer($adminToken));
        $decResp = $this->request('POST', "/api/v1/moderation/cases/{$caseId}/decisions", [
            'decision' => 'approved',
            'reason' => 'fine',
        ], $this->bearer($adminToken));
        self::assertSame(201, $decResp->getStatusCode(), (string) $decResp->getBody());

        return [
            'content_id' => $contentId,
            'case_id' => $caseId,
            'author_id' => (int) $author->id,
            'reporter_id' => (int) $reporter->id,
        ];
    }

    public function testUnauthenticatedAppealReturns401(): void
    {
        $resp = $this->request('POST', '/api/v1/moderation/cases/does-not-exist/appeal', [
            'rationale' => 'n/a',
        ]);
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testAuthenticatedWithoutPermissionReturns403(): void
    {
        $reporter = $this->createUser('reporter', 'learner');
        $ctx = $this->resolveCase($reporter);

        // Strip appeal.create from the learner role after setup so we can exercise the
        // capability gate specifically.
        DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.key', 'learner')
            ->where('permissions.key', 'moderation.appeal.create')
            ->delete();
        \Meridian\Domain\Auth\UserPermissions::clearCacheForUser($ctx['reporter_id']);

        $token = $this->login('reporter');
        $resp = $this->request('POST', "/api/v1/moderation/cases/{$ctx['case_id']}/appeal", [
            'rationale' => 'disagree',
        ], $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
        self::assertTrue(DB::table('audit_logs')->where('action', 'moderation.appeal_denied')->exists());
    }

    public function testOutOfScopeActorIsDenied(): void
    {
        $reporter = $this->createUser('reporter', 'learner');
        $ctx = $this->resolveCase($reporter);
        // A different learner that was not involved in the case at all.
        $this->createUser('stranger', 'learner');
        $token = $this->login('stranger');
        $resp = $this->request('POST', "/api/v1/moderation/cases/{$ctx['case_id']}/appeal", [
            'rationale' => 'not mine',
        ], $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame(0, DB::table('moderation_appeals')->count());
    }

    public function testOriginalReporterCanAppeal(): void
    {
        $reporter = $this->createUser('reporter', 'learner');
        $ctx = $this->resolveCase($reporter);
        $token = $this->login('reporter');
        $resp = $this->request('POST', "/api/v1/moderation/cases/{$ctx['case_id']}/appeal", [
            'rationale' => 'please reconsider',
        ], $this->bearer($token));
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame(1, DB::table('moderation_appeals')->count());
    }

    public function testContentOwnerCanAppeal(): void
    {
        $reporter = $this->createUser('reporter', 'learner');
        $ctx = $this->resolveCase($reporter, authorName: 'owner');
        $token = $this->login('owner');
        $resp = $this->request('POST', "/api/v1/moderation/cases/{$ctx['case_id']}/appeal", [
            'rationale' => 'my content, I appeal',
        ], $this->bearer($token));
        self::assertSame(201, $resp->getStatusCode());
    }

    public function testAppealOnIneligibleStateReturnsConflict(): void
    {
        $reporter = $this->createUser('reporter', 'learner');
        $ctx = $this->resolveCase($reporter);
        // Move case out of resolved state so the appeal call hits the eligibility check.
        DB::table('moderation_cases')->where('id', $ctx['case_id'])->update(['status' => 'in_review']);
        $token = $this->login('reporter');
        $resp = $this->request('POST', "/api/v1/moderation/cases/{$ctx['case_id']}/appeal", [
            'rationale' => 'nope',
        ], $this->bearer($token));
        self::assertSame(409, $resp->getStatusCode());
        self::assertSame('CASE_NOT_RESOLVED', $this->decode($resp)['error']['code']);
    }

    public function testDuplicateActiveAppealReturnsConflict(): void
    {
        $reporter = $this->createUser('reporter', 'learner');
        $ctx = $this->resolveCase($reporter);
        $token = $this->login('reporter');
        $first = $this->request('POST', "/api/v1/moderation/cases/{$ctx['case_id']}/appeal", [
            'rationale' => 'first',
        ], $this->bearer($token));
        self::assertSame(201, $first->getStatusCode());
        $second = $this->request('POST', "/api/v1/moderation/cases/{$ctx['case_id']}/appeal", [
            'rationale' => 'second',
        ], $this->bearer($token));
        self::assertSame(409, $second->getStatusCode());
        self::assertSame('APPEAL_ACTIVE', $this->decode($second)['error']['code']);
    }
}
