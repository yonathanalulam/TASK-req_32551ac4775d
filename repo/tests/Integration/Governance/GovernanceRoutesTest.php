<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Governance;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Audit\AuditHashChain;
use Meridian\Tests\Integration\IntegrationTestCase;

/**
 * HTTP coverage for the governance-facing GET routes:
 *   - GET /api/v1/blacklists
 *   - GET /api/v1/audit/logs
 *   - GET /api/v1/audit/chain
 *   - GET /api/v1/audit/chain/verify
 *
 * Each test drives a real request through the full Slim pipeline and checks both the
 * response envelope and the observable persistence state for the probed surface.
 */
final class GovernanceRoutesTest extends IntegrationTestCase
{
    public function testListBlacklistsShowsActiveEntries(): void
    {
        $this->createUser('admin', 'administrator');
        $token = $this->login('admin');

        // Seed an entry via the real write path.
        $add = $this->request('POST', '/api/v1/blacklists', [
            'entry_type' => 'source',
            'target_key' => 'bad_source_feed',
            'reason' => 'repeated policy violations',
        ], $this->bearer($token));
        self::assertSame(201, $add->getStatusCode());

        $list = $this->request('GET', '/api/v1/blacklists?type=source', null, $this->bearer($token));
        self::assertSame(200, $list->getStatusCode());
        $items = $this->decode($list)['data'];
        $keys = array_column($items, 'target_key');
        self::assertContains('bad_source_feed', $keys);
    }

    public function testListBlacklistsRequiresGovernancePermission(): void
    {
        $this->createUser('learner', 'learner');
        $token = $this->login('learner');
        $resp = $this->request('GET', '/api/v1/blacklists', null, $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('NOT_AUTHORIZED', $this->decode($resp)['error']['code']);
    }

    public function testAuditLogsReadableByAdminAndFilterable(): void
    {
        $admin = $this->createUser('admin', 'administrator');
        $token = $this->login('admin');

        // Create some audited side-effect via a blacklist write.
        $this->request('POST', '/api/v1/blacklists', [
            'entry_type' => 'source', 'target_key' => 'audit_source',
        ], $this->bearer($token));

        $resp = $this->request('GET', '/api/v1/audit/logs?action=blacklist.added', null, $this->bearer($token));
        self::assertSame(200, $resp->getStatusCode());
        $items = $this->decode($resp)['data'];
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertSame('blacklist.added', $item['action']);
            self::assertArrayHasKey('row_hash', $item);
        }
    }

    public function testAuditLogsDeniedToLearner(): void
    {
        $this->createUser('learner', 'learner');
        $token = $this->login('learner');
        $resp = $this->request('GET', '/api/v1/audit/logs', null, $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testAuditChainReturnsSealedDays(): void
    {
        $this->createUser('admin', 'administrator');
        $token = $this->login('admin');

        // Seed a single chain row so the endpoint has something to return.
        AuditHashChain::query()->create([
            'chain_date' => '2026-04-16',
            'previous_day_hash' => null,
            'first_log_id' => null,
            'last_log_id' => null,
            'row_count' => 0,
            'chain_hash' => str_repeat('a', 64),
            'finalized_at' => '2026-04-17 01:00:00',
            'finalized_by' => 'unit_test',
        ]);

        $resp = $this->request('GET', '/api/v1/audit/chain?limit=5', null, $this->bearer($token));
        self::assertSame(200, $resp->getStatusCode());
        $items = $this->decode($resp)['data'];
        self::assertNotEmpty($items);
        $dates = array_column($items, 'chain_date');
        self::assertContains('2026-04-16', $dates);
    }

    public function testAuditChainVerifyAdminOnly(): void
    {
        // Learner -> 403 (not admin role).
        $this->createUser('learner', 'learner');
        $learnerToken = $this->login('learner');
        $denied = $this->request('GET', '/api/v1/audit/chain/verify', null, $this->bearer($learnerToken));
        self::assertSame(403, $denied->getStatusCode());

        // Admin -> 200 with deterministic shape; empty chain verifies trivially.
        $this->createUser('admin', 'administrator');
        $adminToken = $this->login('admin');
        $ok = $this->request('GET', '/api/v1/audit/chain/verify', null, $this->bearer($adminToken));
        self::assertSame(200, $ok->getStatusCode());
        $data = $this->decode($ok)['data'];
        self::assertArrayHasKey('ok', $data);
        self::assertArrayHasKey('days', $data);
        self::assertIsBool($data['ok']);
        self::assertIsArray($data['days']);
    }
}
