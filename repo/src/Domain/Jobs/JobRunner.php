<?php

declare(strict_types=1);

namespace Meridian\Domain\Jobs;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Infrastructure\Clock\Clock;
use Meridian\Infrastructure\Metrics\MetricsWriter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Local job runner.
 *
 * Enforces:
 * - singleton locks via the job_locks table
 * - retry/backoff policy (1min, 5min, 15min up to 3 attempts)
 * - stale "running" reaper for records older than the configured window
 * - job_runs ledger with resume_marker, failure_reason, attempts
 */
final class JobRunner
{
    /** @param array<int,int> $backoffSeconds */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $audit,
        private readonly int $maxAttempts = 3,
        private readonly array $backoffSeconds = [60, 300, 900],
        private readonly int $staleRunningSeconds = 1800,
        private readonly ?MetricsWriter $metrics = null,
    ) {
    }

    /** Queues a new job run for the given job_key. */
    public function enqueue(string $jobKey, array $payload = [], ?string $actor = null): JobRun
    {
        $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');
        /** @var JobRun $run */
        $run = JobRun::query()->create([
            'job_key' => $jobKey,
            'status' => 'queued',
            'attempt' => 1,
            'max_attempts' => $this->maxAttempts,
            'queued_at' => $now,
            'next_run_at' => $now,
            'actor_identity' => $actor,
            'payload_json' => json_encode($payload),
        ]);
        return $run;
    }

    /**
     * Processes up to $max due runs. Claims each with a short-TTL lock when the
     * definition is singleton. Non-singleton jobs may run concurrently.
     */
    public function tick(int $max = 10): int
    {
        $this->reapStale();
        $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');

        $due = JobRun::query()
            ->whereIn('status', ['queued', 'retry_wait'])
            ->where(function ($q) use ($now) {
                $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            })
            ->orderBy('queued_at')
            ->limit($max)
            ->get();

        $processed = 0;
        foreach ($due as $run) {
            if ($this->tryProcess($run)) {
                $processed++;
            }
        }
        return $processed;
    }

    private function tryProcess(JobRun $run): bool
    {
        $def = JobDefinition::query()->where('key', $run->job_key)->first();
        if (!$def instanceof JobDefinition || !$def->is_enabled) {
            $this->markFailed($run, 'job_definition_missing_or_disabled');
            return false;
        }
        if ($def->is_singleton && !$this->acquireLock($def->key)) {
            return false;
        }
        try {
            $this->executeRun($run, $def);
            return true;
        } finally {
            if ($def->is_singleton) {
                $this->releaseLock($def->key);
            }
        }
    }

    private function executeRun(JobRun $run, JobDefinition $def): void
    {
        $start = $this->clock->nowUtc();
        $run->status = 'running';
        $run->started_at = $start->format('Y-m-d H:i:s');
        $run->save();

        try {
            $handler = $this->container->get($def->handler_class);
            if (!$handler instanceof JobHandler) {
                throw new \RuntimeException('Handler ' . $def->handler_class . ' must implement JobHandler');
            }
            $payload = json_decode((string) $run->payload_json, true) ?: [];
            $handler->handle($run, is_array($payload) ? $payload : []);
            $now = $this->clock->nowUtc();
            $run->status = 'succeeded';
            $run->ended_at = $now->format('Y-m-d H:i:s');
            $run->failure_reason = null;
            $run->save();
            $this->audit->record('jobs.run_succeeded', 'job_run', (string) $run->id, [
                'job_key' => $run->job_key,
                'attempt' => $run->attempt,
            ], actorType: 'scheduled_job', actorId: $run->job_key);
            $this->emitRunMetrics($run, $start, $now, 'succeeded');
        } catch (Throwable $e) {
            $this->logger->error('Job failed: ' . $run->job_key, [
                'job_key' => $run->job_key,
                'attempt' => $run->attempt,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $now = $this->clock->nowUtc();
            if ($run->attempt >= $run->max_attempts) {
                $run->status = 'failed';
                $run->ended_at = $now->format('Y-m-d H:i:s');
                $run->failure_reason = $this->summarizeError($e);
                $run->save();
                $this->audit->record('jobs.run_failed', 'job_run', (string) $run->id, [
                    'job_key' => $run->job_key,
                    'attempt' => $run->attempt,
                    'reason' => $run->failure_reason,
                ], actorType: 'scheduled_job', actorId: $run->job_key);
                $this->emitRunMetrics($run, $start, $now, 'failed');
            } else {
                $delay = $this->backoffSeconds[$run->attempt - 1] ?? end($this->backoffSeconds);
                $run->status = 'retry_wait';
                $run->ended_at = $now->format('Y-m-d H:i:s');
                $run->failure_reason = $this->summarizeError($e);
                $run->attempt += 1;
                $run->next_run_at = $now->modify('+' . (int) $delay . ' seconds')->format('Y-m-d H:i:s');
                $run->save();
                $this->emitRunMetrics($run, $start, $now, 'retry_wait');
            }
        }
    }

    private function emitRunMetrics(JobRun $run, \DateTimeImmutable $start, \DateTimeImmutable $end, string $status): void
    {
        if ($this->metrics === null) {
            return;
        }
        $durationMs = (int) round(((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000.0);
        if ($durationMs < 0) {
            $durationMs = 0;
        }
        $labels = ['job_key' => (string) $run->job_key, 'status' => $status, 'attempt' => (int) $run->attempt];
        $this->metrics->record('jobs.run.duration_ms', $durationMs, $labels);
        $this->metrics->record('jobs.run.count', 1, $labels);
    }

    private function acquireLock(string $key, int $ttlSeconds = 1800): bool
    {
        $now = $this->clock->nowUtc();
        $nowFmt = $now->format('Y-m-d H:i:s');
        $expFmt = $now->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s');

        return DB::connection()->transaction(function () use ($key, $now, $nowFmt, $expFmt): bool {
            $existing = JobLock::query()->lockForUpdate()->find($key);
            if ($existing instanceof JobLock) {
                $exp = new \DateTimeImmutable((string) $existing->expires_at);
                if ($exp > $now) {
                    return false;
                }
                $existing->holder = 'runner:' . gethostname();
                $existing->acquired_at = $nowFmt;
                $existing->expires_at = $expFmt;
                $existing->save();
                return true;
            }
            JobLock::query()->create([
                'lock_key' => $key,
                'holder' => 'runner:' . gethostname(),
                'acquired_at' => $nowFmt,
                'expires_at' => $expFmt,
            ]);
            return true;
        });
    }

    private function releaseLock(string $key): void
    {
        JobLock::query()->where('lock_key', $key)->delete();
    }

    private function reapStale(): void
    {
        $cutoff = $this->clock->nowUtc()->modify('-' . $this->staleRunningSeconds . ' seconds')->format('Y-m-d H:i:s');
        $stale = JobRun::query()
            ->where('status', 'running')
            ->where('started_at', '<', $cutoff)
            ->get();
        foreach ($stale as $run) {
            $run->status = 'failed';
            $run->failure_reason = 'stale_running_reaped';
            $run->ended_at = $this->clock->nowUtc()->format('Y-m-d H:i:s');
            $run->save();
        }
    }

    private function markFailed(JobRun $run, string $reason): void
    {
        $run->status = 'failed';
        $run->failure_reason = $reason;
        $run->ended_at = $this->clock->nowUtc()->format('Y-m-d H:i:s');
        $run->save();
    }

    private function summarizeError(Throwable $e): string
    {
        return mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 500);
    }
}
