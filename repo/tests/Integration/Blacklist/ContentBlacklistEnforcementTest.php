<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Blacklist;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

/**
 * Exercises Fix C — content blacklist entries must affect real runtime behavior
 * across reads, searches, analytics ingestion and query, and merge. Storage-only
 * enforcement is not sufficient.
 */
final class ContentBlacklistEnforcementTest extends IntegrationTestCase
{
    private function ingestContent(string $authorUser, string $title = 'Piece'): string
    {
        $token = $this->login($authorUser);
        $resp = $this->request('POST', '/api/v1/content/parse', [
            'kind' => 'plain_text',
            'title' => $title,
            'payload' => $this->englishBody(),
            'media_source' => 'article',
        ], $this->bearer($token));
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
        return (string) $this->decode($resp)['data']['content']['content_id'];
    }

    private function blacklistContent(string $contentId): void
    {
        $admin = $this->createUser('gov_admin_' . bin2hex(random_bytes(2)), 'administrator');
        $adminToken = $this->login($admin->username);
        $resp = $this->request('POST', '/api/v1/blacklists', [
            'entry_type' => 'content',
            'target_key' => $contentId,
            'reason' => 'unit test',
        ], $this->bearer($adminToken));
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
    }

    public function testBlacklistedContentIsHiddenFromSingleRead(): void
    {
        $this->createUser('author', 'instructor');
        $contentId = $this->ingestContent('author');
        $this->blacklistContent($contentId);

        $reader = $this->createUser('reader', 'instructor');
        $readerToken = $this->login('reader');
        $resp = $this->request('GET', '/api/v1/content/' . $contentId, null, $this->bearer($readerToken));
        self::assertSame(404, $resp->getStatusCode(), (string) $resp->getBody());

        $auditRow = DB::table('audit_logs')->where('action', 'content.view_blocked_blacklisted')->first();
        self::assertNotNull($auditRow, 'expected an audit entry for the blocked read');
    }

    public function testBlacklistedContentIsExcludedFromSearch(): void
    {
        $this->createUser('author', 'instructor');
        $contentId = $this->ingestContent('author', 'VisibleWord');
        $this->blacklistContent($contentId);

        $reader = $this->createUser('reader', 'instructor');
        $readerToken = $this->login('reader');
        $resp = $this->request('GET', '/api/v1/content', null, $this->bearer($readerToken));
        self::assertSame(200, $resp->getStatusCode());
        foreach ($this->decode($resp)['data'] as $item) {
            self::assertNotSame($contentId, $item['content_id']);
        }
    }

    public function testAdministratorBypassesBlacklistedReadForGovernance(): void
    {
        $this->createUser('author', 'instructor');
        $contentId = $this->ingestContent('author');
        $this->blacklistContent($contentId);

        $admin = $this->createUser('reviewer_admin', 'administrator');
        $adminToken = $this->login('reviewer_admin');
        $resp = $this->request('GET', '/api/v1/content/' . $contentId, null, $this->bearer($adminToken));
        self::assertSame(200, $resp->getStatusCode());
    }

    public function testAnalyticsIngestForBlacklistedContentIsRejected(): void
    {
        $this->createUser('author', 'instructor');
        $contentId = $this->ingestContent('author');
        $this->blacklistContent($contentId);

        $ingester = $this->createUser('ingester', 'reviewer');
        $token = $this->login('ingester');
        $resp = $this->request('POST', '/api/v1/analytics/events', [
            'event_type' => 'content_view',
            'object_type' => 'content',
            'object_id' => $contentId,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'idempotency_key' => 'k-blist-1',
        ], $this->bearer($token));
        self::assertSame(409, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame('BLACKLISTED_CONTENT', $this->decode($resp)['error']['code']);
    }

    public function testAnalyticsQueryExcludesBlacklistedContentForNonAdmin(): void
    {
        $this->createUser('author', 'instructor');
        $contentId = $this->ingestContent('author');

        $ingester = $this->createUser('ingester', 'reviewer');
        $token = $this->login('ingester');
        $first = $this->request('POST', '/api/v1/analytics/events', [
            'event_type' => 'content_view',
            'object_type' => 'content',
            'object_id' => $contentId,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'idempotency_key' => 'k-visible',
        ], $this->bearer($token));
        self::assertSame(201, $first->getStatusCode());

        // Blacklist the content now; the previously-ingested event must disappear from non-admin queries.
        $this->blacklistContent($contentId);

        // Targeted out-of-scope request: `object_type=content` + `object_id=<blacklisted>` is
        // the deterministic path the policy layer denies with 403. Without the object_type
        // hint the service falls back to filtering the result set (200 + empty list),
        // which is a weaker assertion — so we probe the strict path explicitly.
        $resp = $this->request(
            'GET',
            '/api/v1/analytics/events?object_type=content&object_id=' . $contentId,
            null,
            $this->bearer($token),
        );
        self::assertSame(403, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame('NOT_AUTHORIZED', $this->decode($resp)['error']['code']);

        // Administrator still sees it (governance view).
        $admin = $this->createUser('ops_admin', 'administrator');
        $adminToken = $this->login('ops_admin');
        $adminResp = $this->request('GET', '/api/v1/analytics/events?object_id=' . $contentId, null, $this->bearer($adminToken));
        self::assertSame(200, $adminResp->getStatusCode());
        $adminItems = $this->decode($adminResp)['data'];
        self::assertNotSame([], $adminItems);
    }

    public function testMergeRejectsBlacklistedContent(): void
    {
        $this->createUser('author', 'instructor');
        $primary = $this->ingestContent('author', 'Primary');
        $secondary = $this->ingestContent('author', 'Secondary');
        $this->blacklistContent($secondary);

        $admin = $this->createUser('merge_admin', 'administrator');
        $adminToken = $this->login('merge_admin');
        $resp = $this->request('POST', '/api/v1/dedup/merge', [
            'primary_content_id' => $primary,
            'secondary_content_id' => $secondary,
        ], $this->bearer($adminToken));
        self::assertSame(409, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame('BLACKLISTED_CONTENT', $this->decode($resp)['error']['code']);
    }
}
