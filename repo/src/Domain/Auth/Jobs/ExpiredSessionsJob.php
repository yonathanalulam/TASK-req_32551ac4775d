<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth\Jobs;

use Meridian\Domain\Auth\UserSession;
use Meridian\Domain\Jobs\JobHandler;
use Meridian\Domain\Jobs\JobRun;
use Meridian\Infrastructure\Clock\Clock;

/**
 * Marks expired sessions as revoked (so they no longer appear in concurrent-active sets)
 * and purges records older than 30 days of retention.
 */
final class ExpiredSessionsJob implements JobHandler
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function handle(JobRun $run, array $payload): void
    {
        $now = $this->clock->nowUtc();
        $nowFmt = $now->format('Y-m-d H:i:s');
        $expired = UserSession::query()
            ->whereNull('revoked_at')
            ->where(function ($q) use ($nowFmt) {
                $q->where('absolute_expires_at', '<=', $nowFmt)
                  ->orWhere('idle_expires_at', '<=', $nowFmt);
            })
            ->update(['revoked_at' => $nowFmt, 'revoke_reason' => 'expired']);
        $purgeCutoff = $now->modify('-30 days')->format('Y-m-d H:i:s');
        $purged = UserSession::query()
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<', $purgeCutoff)
            ->delete();
        $run->resume_marker = 'expired=' . $expired . ';purged=' . $purged;
        $run->save();
    }
}
