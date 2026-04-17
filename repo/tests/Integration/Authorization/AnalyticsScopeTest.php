<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Authorization;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

/**
 * Exercises Fix A — protected analytics views must enforce scope-aware object
 * filtering, not just capability-level `analytics.query` permission.
 */
final class AnalyticsScopeTest extends IntegrationTestCase
{
    private function ingestContent(string $user, string $title = 'Scoped'): string
    {
        $token = $this->login($user);
        $resp = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => $title,
            'payload' => $this->englishBody(),
            'media_source' => 'article',
        ], $this->bearer($token));
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
        return (string) $this->decode($resp)['data']['content']['content_id'];
    }

    private function ingestAnalyticsEvent(string $username, string $contentId, string $key, string $eventType = 'content_view'): int
    {
        $token = $this->login($username);
        $resp = $this->request('POST', '/api/v1/analytics/events', [
            'event_type' => $eventType,
            'object_type' => 'content',
            'object_id' => $contentId,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'idempotency_key' => $key,
            'dwell_seconds' => 15,
            'language' => 'en',
            'media_source' => 'article',
        ], $this->bearer($token));
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
        return (int) $this->decode($resp)['data']['id'];
    }

    public function testAdministratorSeesAllAnalyticsEvents(): void
    {
        $this->createUser('author', 'instructor');
        $contentA = $this->ingestContent('author', 'A');
        DB::table('contents')->where('content_id', $contentA)->update(['risk_state' => 'restricted']);
        // Ingest analytic event via admin (admin has analytics.ingest implicitly through assignment)
        $admin = $this->createUser('admin', 'administrator');
        $this->ingestAnalyticsEvent('admin', $contentA, 'k-a-admin');

        $adminToken = $this->login('admin');
        $resp = $this->request('GET', '/api/v1/analytics/events', null, $this->bearer($adminToken));
        self::assertSame(200, $resp->getStatusCode());
        $ids = array_column($this->decode($resp)['data'], 'object_id');
        self::assertContains($contentA, $ids);
    }

    public function testInstructorWithoutScopeCannotSeeRestrictedContentEvents(): void
    {
        $author = $this->createUser('author', 'instructor');
        $contentId = $this->ingestContent('author');
        // Drive it into a non-safe risk_state so the author's own scope is the only path in.
        DB::table('contents')->where('content_id', $contentId)->update(['risk_state' => 'restricted']);
        $this->ingestAnalyticsEvent('author', $contentId, 'k-auth');

        // A different instructor without any scope binding must not see the event.
        $this->createUser('other_instr', 'instructor');
        $otherToken = $this->login('other_instr');
        $resp = $this->request(
            'GET',
            '/api/v1/analytics/events?object_type=content&object_id=' . $contentId,
            null,
            $this->bearer($otherToken),
        );
        self::assertSame(403, $resp->getStatusCode(), (string) $resp->getBody());
    }

    public function testScopedInstructorSeesOnlyAuthorizedAnalytics(): void
    {
        $author = $this->createUser('author', 'instructor');
        $contentId = $this->ingestContent('author');
        DB::table('contents')->where('content_id', $contentId)->update(['risk_state' => 'restricted']);
        $this->ingestAnalyticsEvent('author', $contentId, 'k-scoped-a');

        // Create a second content item the scoped user should NOT see.
        $otherAuthor = $this->createUser('author2', 'instructor');
        $contentB = $this->ingestContent('author2', 'Other');
        DB::table('contents')->where('content_id', $contentB)->update(['risk_state' => 'restricted']);
        $this->ingestAnalyticsEvent('author2', $contentB, 'k-scoped-b');

        $scoped = $this->createUser('scoped_instr', 'instructor');
        $this->addScopedRole($scoped, 'instructor', 'content', $contentId);
        $scopedToken = $this->login('scoped_instr');
        $resp = $this->request('GET', '/api/v1/analytics/events', null, $this->bearer($scopedToken));
        self::assertSame(200, $resp->getStatusCode());
        $objectIds = array_column($this->decode($resp)['data'], 'object_id');
        self::assertContains($contentId, $objectIds);
        self::assertNotContains($contentB, $objectIds);
    }

    public function testOutOfScopeSpecificRequestIsDenied(): void
    {
        $author = $this->createUser('author', 'instructor');
        $contentId = $this->ingestContent('author');
        DB::table('contents')->where('content_id', $contentId)->update(['risk_state' => 'restricted']);
        $this->ingestAnalyticsEvent('author', $contentId, 'k-oor');

        $stranger = $this->createUser('stranger', 'instructor');
        $strangerToken = $this->login('stranger');
        $resp = $this->request('GET', '/api/v1/analytics/events?object_type=content&object_id=' . $contentId, null, $this->bearer($strangerToken));
        self::assertSame(403, $resp->getStatusCode(), (string) $resp->getBody());

        $auditRow = DB::table('audit_logs')->where('action', 'analytics.query_denied_out_of_scope')->first();
        self::assertNotNull($auditRow, 'expected audit entry for out-of-scope analytics query');
    }

    public function testKpiSummaryCountsAreScopedBeforeAggregation(): void
    {
        $this->createUser('authorA', 'instructor');
        $contentA = $this->ingestContent('authorA', 'A');
        DB::table('contents')->where('content_id', $contentA)->update(['risk_state' => 'restricted']);
        $this->ingestAnalyticsEvent('authorA', $contentA, 'k-kpi-a');

        $this->createUser('authorB', 'instructor');
        $contentB = $this->ingestContent('authorB', 'B');
        DB::table('contents')->where('content_id', $contentB)->update(['risk_state' => 'restricted']);
        $this->ingestAnalyticsEvent('authorB', $contentB, 'k-kpi-b');

        $admin = $this->createUser('admin', 'administrator');
        $adminToken = $this->login('admin');
        $adminResp = $this->request('GET', '/api/v1/analytics/kpis', null, $this->bearer($adminToken));
        self::assertSame(200, $adminResp->getStatusCode());
        $adminTotal = (int) $this->decode($adminResp)['data']['content_views']['total'];
        self::assertGreaterThanOrEqual(2, $adminTotal);

        $scoped = $this->createUser('scoped_reviewer', 'reviewer');
        $this->addScopedRole($scoped, 'reviewer', 'content', $contentA);
        $scopedToken = $this->login('scoped_reviewer');
        $resp = $this->request('GET', '/api/v1/analytics/kpis', null, $this->bearer($scopedToken));
        self::assertSame(200, $resp->getStatusCode());
        $scopedTotal = (int) $this->decode($resp)['data']['content_views']['total'];
        self::assertLessThan($adminTotal, $scopedTotal, 'scoped reviewer must see fewer content views than admin');
    }

    public function testFunnelIsFilteredByScope(): void
    {
        $this->createUser('authorA', 'instructor');
        $contentA = $this->ingestContent('authorA', 'A');
        DB::table('contents')->where('content_id', $contentA)->update(['risk_state' => 'restricted']);
        $this->ingestAnalyticsEvent('authorA', $contentA, 'k-fun-a');

        $this->createUser('authorB', 'instructor');
        $contentB = $this->ingestContent('authorB', 'B');
        DB::table('contents')->where('content_id', $contentB)->update(['risk_state' => 'restricted']);
        $this->ingestAnalyticsEvent('authorB', $contentB, 'k-fun-b');

        $stranger = $this->createUser('stranger', 'instructor');
        $strangerToken = $this->login('stranger');
        $resp = $this->request('POST', '/api/v1/analytics/funnel', [
            'stages' => [
                ['event_type' => 'content_view', 'object_type' => 'content'],
                ['event_type' => 'content_view', 'object_type' => 'content'],
            ],
            'from' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            'to' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        ], $this->bearer($strangerToken));
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $stages = $this->decode($resp)['data']['stages'];
        foreach ($stages as $s) {
            self::assertSame(0, (int) $s['distinct_actors']);
        }
    }
}
