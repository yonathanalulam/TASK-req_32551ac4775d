<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Authorization;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

final class ObjectScopeAuthorizationTest extends IntegrationTestCase
{
    public function testWriteRequiresCapability(): void
    {
        $this->createUser('learner', 'learner');
        $token = $this->login('learner');
        // Learner does NOT have content.parse permission.
        $response = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => 'x',
            'payload' => $this->englishBody(),
            'media_source' => 'article',
        ], $this->bearer($token));
        self::assertSame(403, $response->getStatusCode());
    }

    public function testSearchExcludesRestrictedContentFromLearner(): void
    {
        $author = $this->createUser('author', 'instructor');
        $token = $this->login('author');
        // Ingest and then restrict via direct DB update (simulating moderator decision).
        $resp = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text', 'title' => 'Article', 'payload' => $this->englishBody(), 'media_source' => 'article',
        ], $this->bearer($token));
        $contentId = $this->decode($resp)['data']['content']['content_id'];
        DB::table('contents')->where('content_id', $contentId)->update(['risk_state' => 'restricted']);

        $this->createUser('learner', 'learner');
        $learnerToken = $this->login('learner');
        $list = $this->request('GET', '/api/v1/content', null, $this->bearer($learnerToken));
        self::assertSame(200, $list->getStatusCode());
        $items = $this->decode($list)['data'];
        foreach ($items as $item) {
            self::assertNotSame($contentId, $item['content_id']);
        }

        // Direct single-object access is also denied for learner
        $one = $this->request('GET', '/api/v1/content/' . $contentId, null, $this->bearer($learnerToken));
        self::assertSame(403, $one->getStatusCode());
    }

    public function testCorrectContentScopeBindingAllowsAccess(): void
    {
        $author = $this->createUser('author', 'instructor');
        $token = $this->login('author');
        $resp = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text', 'title' => 'A', 'payload' => $this->englishBody(), 'media_source' => 'article',
        ], $this->bearer($token));
        $contentId = $this->decode($resp)['data']['content']['content_id'];
        DB::table('contents')->where('content_id', $contentId)->update(['risk_state' => 'restricted']);

        $scoped = $this->createUser('scoped', 'learner');
        $this->addScopedRole($scoped, 'learner', 'content', $contentId);

        $scopedToken = $this->login('scoped');
        $single = $this->request('GET', '/api/v1/content/' . $contentId, null, $this->bearer($scopedToken));
        self::assertSame(200, $single->getStatusCode());
    }

    public function testUnmergeIsAdminOnly(): void
    {
        $this->createUser('reviewer', 'reviewer');
        $token = $this->login('reviewer');
        $response = $this->request('POST', '/api/v1/dedup/unmerge', [
            'secondary_content_id' => 'nonexistent',
        ], $this->bearer($token));
        self::assertSame(403, $response->getStatusCode());
    }

    public function testOnlyAssignedReviewerMayDecideCase(): void
    {
        $admin = $this->createUser('admin', 'administrator');
        $adminToken = $this->login('admin');
        $reviewerA = $this->createUser('revA', 'reviewer');
        $reviewerB = $this->createUser('revB', 'reviewer');

        // Create a case via admin (who has moderation.review)
        $caseResp = $this->request('POST', '/api/v1/moderation/cases', [
            'reason_code' => 'manual_investigation',
        ], $this->bearer($adminToken));
        self::assertSame(201, $caseResp->getStatusCode());
        $caseId = $this->decode($caseResp)['data']['id'];

        // Assign to reviewer A
        $assign = $this->request('POST', '/api/v1/moderation/cases/' . $caseId . '/assign', [
            'reviewer_user_id' => $reviewerA->id,
        ], $this->bearer($adminToken));
        self::assertSame(200, $assign->getStatusCode());

        // Reviewer B tries to decide: denied
        $tokenB = $this->login('revB');
        $badDecide = $this->request('POST', '/api/v1/moderation/cases/' . $caseId . '/decisions', [
            'decision' => 'approved', 'reason' => 'test',
        ], $this->bearer($tokenB));
        self::assertSame(403, $badDecide->getStatusCode());

        // Reviewer A decides: allowed
        $tokenA = $this->login('revA');
        $ok = $this->request('POST', '/api/v1/moderation/cases/' . $caseId . '/decisions', [
            'decision' => 'approved', 'reason' => 'ok',
        ], $this->bearer($tokenA));
        self::assertSame(201, $ok->getStatusCode());
    }
}
