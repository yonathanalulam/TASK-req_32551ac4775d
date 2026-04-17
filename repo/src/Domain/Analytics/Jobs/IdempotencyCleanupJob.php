<?php

declare(strict_types=1);

namespace Meridian\Domain\Analytics\Jobs;

use Meridian\Domain\Analytics\AnalyticsIdempotencyKey;
use Meridian\Domain\Jobs\JobHandler;
use Meridian\Domain\Jobs\JobRun;
use Meridian\Infrastructure\Clock\Clock;

/**
 * Deletes idempotency key records whose expires_at is in the past. Retains a 48h buffer
 * beyond the 24h protection window by default, per PRD section 8.5.
 */
final class IdempotencyCleanupJob implements JobHandler
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function handle(JobRun $run, array $payload): void
    {
        $cutoff = $this->clock->nowUtc()->modify('-24 hours')->format('Y-m-d H:i:s');
        $deleted = AnalyticsIdempotencyKey::query()->where('expires_at', '<', $cutoff)->delete();
        $run->resume_marker = 'deleted=' . $deleted;
        $run->save();
    }
}
