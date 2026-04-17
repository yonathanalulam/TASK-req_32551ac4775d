<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Analytics;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

/**
 * Round-3 Fix D coverage.
 *
 * Within the 24-hour window the same idempotency key still produces
 * 409 ANALYTICS_DUPLICATE, but once the window has elapsed the key must be reusable even
 * though the stale row still exists in `analytics_idempotency_keys`.
 */
final class IdempotencyReuseTest extends IntegrationTestCase
{
    private function ingester(): string
    {
        $this->createUser('ingester', 'reviewer');
        return $this->login('ingester');
    }

    private function payload(string $idem): array
    {
        return [
            'event_type' => 'content_view',
            'object_type' => 'content',
            'object_id' => 'sample',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'idempotency_key' => $idem,
        ];
    }

    public function testWithinWindowDuplicateIsRejected(): void
    {
        $token = $this->ingester();
        $first = $this->request('POST', '/api/v1/analytics/events', $this->payload('k-live'), $this->bearer($token));
        self::assertSame(201, $first->getStatusCode());
        $second = $this->request('POST', '/api/v1/analytics/events', $this->payload('k-live'), $this->bearer($token));
        self::assertSame(409, $second->getStatusCode());
        self::assertSame('ANALYTICS_DUPLICATE', $this->decode($second)['error']['code']);
    }

    public function testAfterWindowReuseIsAccepted(): void
    {
        $token = $this->ingester();
        $first = $this->request('POST', '/api/v1/analytics/events', $this->payload('k-expire'), $this->bearer($token));
        self::assertSame(201, $first->getStatusCode());

        // Simulate the protection window elapsing by rewriting expires_at to a past value.
        $past = gmdate('Y-m-d H:i:s', time() - 3600);
        $updated = DB::table('analytics_idempotency_keys')
            ->where('idempotency_key', 'k-expire')
            ->update(['expires_at' => $past]);
        self::assertSame(1, $updated);

        // Reuse must now succeed even though the stale row is still present.
        $reuse = $this->request('POST', '/api/v1/analytics/events', $this->payload('k-expire'), $this->bearer($token));
        self::assertSame(201, $reuse->getStatusCode(), (string) $reuse->getBody());

        // Only one idempotency row remains (the freshly-inserted one).
        $rows = DB::table('analytics_idempotency_keys')->where('idempotency_key', 'k-expire')->get();
        self::assertCount(1, $rows);
        self::assertGreaterThan(gmdate('Y-m-d H:i:s'), (string) $rows[0]->expires_at);

        // Two analytics events exist overall (original + reused key produced distinct rows).
        self::assertSame(2, DB::table('analytics_events')->where('idempotency_key', 'k-expire')->count());
    }

    public function testExpiredRowDoesNotBlockLegalReuseAcrossActors(): void
    {
        $token = $this->ingester();
        $first = $this->request('POST', '/api/v1/analytics/events', $this->payload('k-shared'), $this->bearer($token));
        self::assertSame(201, $first->getStatusCode());

        $past = gmdate('Y-m-d H:i:s', time() - 7200);
        DB::table('analytics_idempotency_keys')->where('idempotency_key', 'k-shared')->update(['expires_at' => $past]);

        // A second actor should also be able to claim the expired key.
        $this->createUser('second', 'reviewer');
        $token2 = $this->login('second');
        $resp = $this->request('POST', '/api/v1/analytics/events', $this->payload('k-shared'), $this->bearer($token2));
        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
    }
}
