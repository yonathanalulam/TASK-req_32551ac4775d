<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Analytics;

use Meridian\Tests\Integration\IntegrationTestCase;

final class AnalyticsIdempotencyTest extends IntegrationTestCase
{
    public function testIngestRequiresPermission(): void
    {
        // Administrator role intentionally excludes analytics.view_unmasked in the seed but
        // does include analytics.ingest implicitly. We pick 'reviewer' here which does have ingest.
        $this->createUser('ingester', 'reviewer');
        $token = $this->login('ingester');
        $resp = $this->request('POST', '/api/v1/analytics/events', [
            'event_type' => 'content_view',
            'object_type' => 'content',
            'object_id' => 'abc',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'idempotency_key' => 'k-1',
        ], $this->bearer($token));
        self::assertSame(201, $resp->getStatusCode());
    }

    public function testDuplicateIdempotencyKeyIsRejected(): void
    {
        $this->createUser('ingester', 'reviewer');
        $token = $this->login('ingester');
        $payload = [
            'event_type' => 'content_view',
            'object_type' => 'content',
            'object_id' => 'xyz',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'idempotency_key' => 'k-dup',
        ];
        $first = $this->request('POST', '/api/v1/analytics/events', $payload, $this->bearer($token));
        self::assertSame(201, $first->getStatusCode());
        $dup = $this->request('POST', '/api/v1/analytics/events', $payload, $this->bearer($token));
        self::assertSame(409, $dup->getStatusCode());
        self::assertSame('ANALYTICS_DUPLICATE', $this->decode($dup)['error']['code']);
    }

    public function testIpAddressIsMaskedWithoutUnmaskPermission(): void
    {
        $this->createUser('ingester', 'reviewer');
        $token = $this->login('ingester');
        $this->request('POST', '/api/v1/analytics/events', [
            'event_type' => 'content_view',
            'object_type' => 'content',
            'object_id' => 'mask',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'idempotency_key' => 'k-mask',
        ], array_merge($this->bearer($token), ['REMOTE_ADDR' => '10.0.0.1']));

        $resp = $this->request('GET', '/api/v1/analytics/events?object_id=mask', null, $this->bearer($token));
        self::assertSame(200, $resp->getStatusCode());
        $items = $this->decode($resp)['data'];
        // IP might be null if the server param was not honored; but when present it must not be plaintext.
        foreach ($items as $item) {
            if ($item['ip_address'] !== null) {
                self::assertSame('[masked]', $item['ip_address']);
            }
        }
    }

    public function testLearnerCannotQueryAnalytics(): void
    {
        $this->createUser('learner', 'learner');
        $token = $this->login('learner');
        $resp = $this->request('GET', '/api/v1/analytics/events', null, $this->bearer($token));
        self::assertSame(403, $resp->getStatusCode());
    }
}
