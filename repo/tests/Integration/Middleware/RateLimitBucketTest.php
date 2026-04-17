<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Middleware;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Tests\Integration\IntegrationTestCase;

final class RateLimitBucketTest extends IntegrationTestCase
{
    public function testAuthenticatedUsersBucketedByUserId(): void
    {
        $alice = $this->createUser('alice', 'learner');
        $bob = $this->createUser('bob', 'learner');
        $tokenA = $this->login('alice');
        $tokenB = $this->login('bob');

        // Hit /auth/me once for each user.
        $ra = $this->request('GET', '/api/v1/auth/me', null, $this->bearer($tokenA));
        self::assertSame(200, $ra->getStatusCode());
        $rb = $this->request('GET', '/api/v1/auth/me', null, $this->bearer($tokenB));
        self::assertSame(200, $rb->getStatusCode());

        $rows = DB::table('rate_limit_windows')->get();
        $keys = array_map(static fn($r) => $r->bucket_key, $rows->all());
        self::assertContains('u:' . $alice->id, $keys);
        self::assertContains('u:' . $bob->id, $keys);
    }

    public function testAnonymousBucketsByIpAndPath(): void
    {
        $resp1 = $this->request('GET', '/api/v1/health');
        self::assertSame(200, $resp1->getStatusCode());
        // The bucket key for anonymous health should be path-scoped and IP-prefixed.
        $rows = DB::table('rate_limit_windows')->get();
        $found = false;
        foreach ($rows as $r) {
            if (str_starts_with($r->bucket_key, 'ip:') && str_ends_with($r->bucket_key, '/api/v1/health')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'anonymous bucket must include IP + path');
    }
}
