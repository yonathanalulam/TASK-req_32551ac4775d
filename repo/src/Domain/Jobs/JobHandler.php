<?php

declare(strict_types=1);

namespace Meridian\Domain\Jobs;

interface JobHandler
{
    /** @param array<string,mixed> $payload */
    public function handle(JobRun $run, array $payload): void;
}
