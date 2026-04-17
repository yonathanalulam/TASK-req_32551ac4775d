<?php

declare(strict_types=1);

namespace Meridian\Domain\Audit\Verification;

use Meridian\Domain\Audit\AuditHashChain;
use Meridian\Domain\Audit\AuditLog;

/**
 * Walks audit_logs and recomputes each row_hash; cross-checks against audit_hash_chain
 * for the given day (or every day, when no day specified).
 */
final class AuditChainVerifier
{
    /**
     * @return array<string,mixed>
     */
    public function verify(?string $day = null): array
    {
        $results = [];
        $query = AuditHashChain::query()->orderBy('chain_date');
        if ($day !== null) {
            $query->where('chain_date', $day);
        }
        /** @var array<int,AuditHashChain> $chains */
        $chains = $query->get()->all();
        $prevHash = null;
        foreach ($chains as $chain) {
            $logQuery = AuditLog::query()
                ->whereBetween('occurred_at', [
                    $chain->chain_date . ' 00:00:00',
                    $chain->chain_date . ' 23:59:59',
                ])
                ->orderBy('id');
            $rows = $logQuery->get()->all();
            $runningPrev = $prevHash ?? str_repeat('0', 64);
            $rowCount = 0;
            $mismatch = null;
            foreach ($rows as $row) {
                $recomputed = hash('sha256', implode('|', [
                    $row->previous_row_hash ?? str_repeat('0', 64),
                    (new \DateTimeImmutable((string) $row->occurred_at))->format('Y-m-d\TH:i:s\Z'),
                    (string) $row->actor_type,
                    (string) $row->actor_id,
                    (string) $row->action,
                    (string) $row->object_type,
                    (string) $row->object_id,
                    (string) $row->payload_json,
                ]));
                if (!hash_equals((string) $row->row_hash, $recomputed)) {
                    $mismatch = ['log_id' => (int) $row->id, 'reason' => 'row_hash_mismatch'];
                    break;
                }
                $runningPrev = (string) $row->row_hash;
                $rowCount++;
            }
            $expectedChainHash = hash('sha256', implode('|', [
                $chain->previous_day_hash ?? str_repeat('0', 64),
                (string) $chain->chain_date,
                (string) $runningPrev,
                (string) $rowCount,
            ]));
            $ok = $mismatch === null && hash_equals((string) $chain->chain_hash, $expectedChainHash);
            $results[] = [
                'chain_date' => $chain->chain_date,
                'ok' => $ok,
                'row_count' => $rowCount,
                'mismatch' => $mismatch,
            ];
            $prevHash = $chain->chain_hash;
        }
        $allOk = array_reduce($results, static fn(bool $acc, array $r) => $acc && (bool) $r['ok'], true);
        return ['ok' => $allOk, 'days' => $results];
    }
}
