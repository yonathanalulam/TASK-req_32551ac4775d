<?php

declare(strict_types=1);

namespace Meridian\Domain\Dedup\Jobs;

use Meridian\Domain\Dedup\DedupService;
use Meridian\Domain\Jobs\JobHandler;
use Meridian\Domain\Jobs\JobRun;

final class RecomputeDedupCandidatesJob implements JobHandler
{
    public function __construct(private readonly DedupService $dedup)
    {
    }

    public function handle(JobRun $run, array $payload): void
    {
        $limit = isset($payload['limit']) ? (int) $payload['limit'] : 500;
        $count = $this->dedup->recompute($limit);
        $run->resume_marker = 'candidates=' . $count;
        $run->save();
    }
}
