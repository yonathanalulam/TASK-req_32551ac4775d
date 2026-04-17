<?php

declare(strict_types=1);

namespace Meridian\Application\Middleware;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Application\Exceptions\RateLimitException;
use Meridian\Domain\Auth\User;
use Meridian\Infrastructure\Clock\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DB-backed fixed-window rate limiter. Bucket per user (or per-IP anonymous fallback).
 *
 * System accounts bypass limiting when is_system=true. Per-user overrides can be added later
 * via system_settings without schema changes.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Clock $clock,
        private readonly int $defaultPerMinute,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user instanceof User && $user->is_system === true) {
            return $handler->handle($request);
        }
        $bucket = $this->bucketKey($request, $user);
        $now = $this->clock->nowUtc();
        $windowStart = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);
        $windowEnd = $windowStart->modify('+60 seconds');

        $row = DB::table('rate_limit_windows')
            ->where('bucket_key', $bucket)
            ->where('window_start', $windowStart->format('Y-m-d H:i:s'))
            ->first();

        if ($row === null) {
            DB::table('rate_limit_windows')->insert([
                'bucket_key' => mb_substr($bucket, 0, 191),
                'window_start' => $windowStart->format('Y-m-d H:i:s'),
                'counter' => 1,
                'expires_at' => $windowEnd->format('Y-m-d H:i:s'),
            ]);
        } else {
            $counter = ((int) $row->counter) + 1;
            if ($counter > $this->defaultPerMinute) {
                $retry = max(1, $windowEnd->getTimestamp() - $now->getTimestamp());
                throw new RateLimitException($retry);
            }
            DB::table('rate_limit_windows')
                ->where('bucket_key', $bucket)
                ->where('window_start', $windowStart->format('Y-m-d H:i:s'))
                ->update(['counter' => $counter]);
        }

        return $handler->handle($request);
    }

    /**
     * Rate-limit bucket strategy:
     *   - authenticated human user -> `u:<user_id>` (per-user bucket; two users from same IP are independent)
     *   - system account -> bypass (handled above)
     *   - unauthenticated caller -> `ip:<REMOTE_ADDR>:<route_path>` (path-scoped IP bucket so auth/health
     *     endpoints don't share a bucket with unrelated anonymous traffic)
     *
     * This middleware must run after AuthMiddleware so the user attribute is populated before
     * the bucket is derived. See AppFactory for the ordering that guarantees this.
     */
    private function bucketKey(ServerRequestInterface $req, ?User $user): string
    {
        if ($user instanceof User) {
            return 'u:' . $user->id;
        }
        $sp = $req->getServerParams();
        $ip = $sp['REMOTE_ADDR'] ?? 'anon';
        $path = $req->getUri()->getPath();
        return 'ip:' . $ip . ':' . $path;
    }
}
