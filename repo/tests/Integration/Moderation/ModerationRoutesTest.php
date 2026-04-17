<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Moderation;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

/**
 * Fills in moderation routes not already exercised by existing tests:
 *   GET  /api/v1/moderation/cases
 *   GET  /api/v1/moderation/cases/{id}
 *   POST /api/v1/moderation/cases/{id}/transition
 *   POST /api/v1/moderation/cases/{id}/notes
 *   GET  /api/v1/moderation/cases/{id}/notes
 *   POST /api/v1/moderation/cases/{id}/appeal/resolve
 */
final class ModerationRoutesTest extends IntegrationTestCase
{
    /**
     * @return array{case_id:string,admin_token:string,reporter_id:int,reporter_token:string}
     */
    private function resolvedCase(): array
    {
        $author = $this->createUser('author', 'instructor');
        $authorToken = $this->login('author');
        $parse = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => 'Moderation subject',
            'payload' => $this->englishBody(),
            'media_source' => 'article',
        ], $this->bearer($authorToken));
        $contentId = (string) $this->decode($parse)['data']['content']['content_id'];

        $reporter = $this->createUser('reporter', 'learner');
        $reporterToken = $this->login('reporter');
        $this->request('POST', '/api/v1/moderation/reports', [
            'content_id' => $contentId,
            'reason_code' => 'spam',
        ], $this->bearer($reporterToken));

        $this->createUser('admin', 'administrator');
        $adminToken = $this->login('admin');
        $caseResp = $this->request('POST', '/api/v1/moderation/cases', [
            'content_id' => $contentId,
            'reason_code' => 'manual_review',
        ], $this->bearer($adminToken));
        $caseId = (string) $this->decode($caseResp)['data']['id'];

        // Link the earlier report so reporter-scope gating works for appeals.
        DB::table('moderation_reports')->where('content_id', $contentId)->update(['case_id' => $caseId]);

        $this->request('POST', "/api/v1/moderation/cases/{$caseId}/assign", [
            'reviewer_user_id' => DB::table('users')->where('username', 'admin')->value('id'),
        ], $this->bearer($adminToken));
        $this->request('POST', "/api/v1/moderation/cases/{$caseId}/decisions", [
            'decision' => 'approved',
            'reason' => 'fine',
        ], $this->bearer($adminToken));

        return [
            'case_id' => $caseId,
            'admin_token' => $adminToken,
            'reporter_id' => (int) $reporter->id,
            'reporter_token' => $this->login('reporter'),
        ];
    }

    public function testListAndGetCases(): void
    {
        $ctx = $this->resolvedCase();

        $list = $this->request('GET', '/api/v1/moderation/cases', null, $this->bearer($ctx['admin_token']));
        self::assertSame(200, $list->getStatusCode());
        $items = $this->decode($list)['data'];
        self::assertNotEmpty($items);
        $ids = array_column($items, 'id');
        self::assertContains($ctx['case_id'], $ids);

        $single = $this->request('GET', '/api/v1/moderation/cases/' . $ctx['case_id'], null, $this->bearer($ctx['admin_token']));
        self::assertSame(200, $single->getStatusCode());
        $detail = $this->decode($single)['data'];
        self::assertSame($ctx['case_id'], $detail['id']);
        self::assertArrayHasKey('decisions', $detail);
        self::assertArrayHasKey('flags', $detail);
    }

    public function testListCasesDeniedForLearner(): void
    {
        $this->createUser('learner', 'learner');
        $token = $this->login('learner');
        $resp = $this->request('GET', '/api/v1/moderation/cases', null, $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('NOT_AUTHORIZED', $this->decode($resp)['error']['code']);
    }

    public function testTransitionAndNotesEndpoints(): void
    {
        // Open a fresh case (not resolved) so we can exercise transition -> in_review -> dismissed.
        $this->createUser('admin', 'administrator');
        $adminToken = $this->login('admin');
        $caseResp = $this->request('POST', '/api/v1/moderation/cases', [
            'reason_code' => 'manual',
        ], $this->bearer($adminToken));
        $caseId = (string) $this->decode($caseResp)['data']['id'];

        // POST /moderation/cases/{id}/transition  open -> in_review
        $t1 = $this->request('POST', "/api/v1/moderation/cases/{$caseId}/transition", [
            'status' => 'in_review',
        ], $this->bearer($adminToken));
        self::assertSame(200, $t1->getStatusCode());
        self::assertSame('in_review', $this->decode($t1)['data']['status']);

        // POST /moderation/cases/{id}/notes  (private note)
        $noteResp = $this->request('POST', "/api/v1/moderation/cases/{$caseId}/notes", [
            'note' => 'reviewer privately flagged this',
            'is_private' => true,
        ], $this->bearer($adminToken));
        self::assertSame(201, $noteResp->getStatusCode());
        $noteId = (int) $this->decode($noteResp)['data']['id'];
        self::assertTrue((bool) $this->decode($noteResp)['data']['is_private']);
        self::assertTrue(DB::table('moderation_notes')->where('id', $noteId)->exists());

        // GET /moderation/cases/{id}/notes — admin sees private notes
        $adminNotes = $this->request('GET', "/api/v1/moderation/cases/{$caseId}/notes", null, $this->bearer($adminToken));
        self::assertSame(200, $adminNotes->getStatusCode());
        $adminItems = $this->decode($adminNotes)['data'];
        self::assertNotEmpty($adminItems);
        $adminNoteIds = array_column($adminItems, 'id');
        self::assertContains($noteId, $adminNoteIds);

        // A reviewer without view_private_notes permission should NOT see private notes.
        $this->createUser('plain_reviewer', 'reviewer');
        DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.key', 'reviewer')
            ->where('permissions.key', 'moderation.view_private_notes')
            ->delete();
        \Meridian\Domain\Auth\UserPermissions::resetCache();
        $plainToken = $this->login('plain_reviewer');
        $plainNotes = $this->request('GET', "/api/v1/moderation/cases/{$caseId}/notes", null, $this->bearer($plainToken));
        self::assertSame(200, $plainNotes->getStatusCode());
        self::assertSame([], $this->decode($plainNotes)['data']);
    }

    public function testResolveAppealEndpoint(): void
    {
        $ctx = $this->resolvedCase();

        // Submit an appeal as the original reporter.
        $appeal = $this->request('POST', '/api/v1/moderation/cases/' . $ctx['case_id'] . '/appeal', [
            'rationale' => 'please reconsider',
        ], $this->bearer($ctx['reporter_token']));
        self::assertSame(201, $appeal->getStatusCode());

        // POST /moderation/cases/{id}/appeal/resolve  as admin -> upheld
        $resolve = $this->request('POST', '/api/v1/moderation/cases/' . $ctx['case_id'] . '/appeal/resolve', [
            'outcome' => 'upheld',
            'reason' => 'evidence insufficient',
        ], $this->bearer($ctx['admin_token']));
        self::assertSame(200, $resolve->getStatusCode());
        self::assertSame('upheld', $this->decode($resolve)['data']['status']);

        // Persistence: case status transitioned to appeal_upheld.
        $case = DB::table('moderation_cases')->where('id', $ctx['case_id'])->first();
        self::assertSame('appeal_upheld', $case->status);
        self::assertSame(0, (int) $case->has_active_appeal);
    }

    public function testResolveAppealRequiresPermission(): void
    {
        $ctx = $this->resolvedCase();
        $this->request('POST', '/api/v1/moderation/cases/' . $ctx['case_id'] . '/appeal', [
            'rationale' => 'please reconsider',
        ], $this->bearer($ctx['reporter_token']));

        // Reporter does NOT have moderation.appeal_resolve → 403.
        $resolve = $this->request(
            'POST',
            '/api/v1/moderation/cases/' . $ctx['case_id'] . '/appeal/resolve',
            ['outcome' => 'upheld'],
            $this->bearer($ctx['reporter_token']),
        );
        self::assertSame(403, $resolve->getStatusCode());
        self::assertSame('NOT_AUTHORIZED', $this->decode($resolve)['error']['code']);
    }
}
