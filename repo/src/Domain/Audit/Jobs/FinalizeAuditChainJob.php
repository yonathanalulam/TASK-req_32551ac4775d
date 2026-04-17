<?php

declare(strict_types=1);

namespace Meridian\Domain\Audit\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Meridian\Domain\Audit\AuditHashChain;
use Meridian\Domain\Audit\AuditLog;
use Meridian\Domain\Jobs\JobHandler;
use Meridian\Domain\Jobs\JobRun;
use Meridian\Infrastructure\Clock\Clock;

/**
 * Daily job: seal yesterday's audit chain (UTC) into a tamper-evident roll-up.
 *
 * chain_hash = sha256( previous_day_hash || YYYY-MM-DD || last_row_hash_of_day || row_count )
 *
 * Fails loudly if the previous day's chain record is missing. This implements the
 * "daily chain generation job must fail loudly if previous day chain missing" rule.
 */
final class FinalizeAuditChainJob implements JobHandler
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function handle(JobRun $run, array $payload): void
    {
        $now = $this->clock->nowUtc();
        $target = isset($payload['date'])
            ? new DateTimeImmutable((string) $payload['date'], new DateTimeZone('UTC'))
            : $now->modify('-1 day');
        $dayKey = $target->format('Y-m-d');

        if (AuditHashChain::query()->where('chain_date', $dayKey)->exists()) {
            $run->resume_marker = 'already_sealed:' . $dayKey;
            $run->save();
            return;
        }

        // previous day chain must exist unless there are simply no prior days
        $prior = $target->modify('-1 day')->format('Y-m-d');
        $previous = AuditHashChain::query()->where('chain_date', $prior)->first();
        $earliestLog = AuditLog::query()->orderBy('id')->first();
        if ($previous === null && $earliestLog !== null) {
            $earliestDate = substr((string) $earliestLog->occurred_at, 0, 10);
            if ($earliestDate < $dayKey) {
                throw new \RuntimeException(
                    'Previous day audit chain missing for ' . $prior . '; refusing to seal ' . $dayKey,
                );
            }
        }
        $prevHash = $previous !== null ? (string) $previous->chain_hash : str_repeat('0', 64);

        $rows = AuditLog::query()
            ->whereBetween('occurred_at', [$dayKey . ' 00:00:00', $dayKey . ' 23:59:59'])
            ->orderBy('id');
        $count = (clone $rows)->count();
        $first = (clone $rows)->first();
        $last = (clone $rows)->orderByDesc('id')->first();
        $lastHash = $last?->row_hash ?? $prevHash;

        $chainHash = hash('sha256', implode('|', [$prevHash, $dayKey, $lastHash, (string) $count]));

        AuditHashChain::query()->create([
            'chain_date' => $dayKey,
            'previous_day_hash' => $previous !== null ? $prevHash : null,
            'first_log_id' => $first?->id,
            'last_log_id' => $last?->id,
            'row_count' => $count,
            'chain_hash' => $chainHash,
            'finalized_at' => $now->format('Y-m-d H:i:s'),
            'finalized_by' => 'scheduled_job',
        ]);
        $run->resume_marker = 'sealed:' . $dayKey;
        $run->save();
    }
}
