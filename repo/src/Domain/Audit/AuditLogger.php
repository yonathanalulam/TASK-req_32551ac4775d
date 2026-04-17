<?php

declare(strict_types=1);

namespace Meridian\Domain\Audit;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Infrastructure\Clock\Clock;

/**
 * Append-only audit writer with per-row SHA-256 hash chain.
 *
 * row_hash = sha256( previous_row_hash || occurred_at || actor_type || actor_id || action ||
 *                    object_type || object_id || payload_json )
 *
 * This chain is finalized daily by FinalizeAuditChainJob which rolls up the final hash
 * into audit_hash_chain with the prior day hash included, giving per-day tamper evidence.
 */
class AuditLogger
{
    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * Records a privileged or state-changing action.
     *
     * @param array<string,mixed> $payload
     */
    public function record(
        string $action,
        ?string $objectType = null,
        ?string $objectId = null,
        array $payload = [],
        string $actorType = 'system',
        ?string $actorId = null,
        ?string $requestId = null,
        ?string $ipAddressCiphertext = null,
    ): AuditLog {
        $now = $this->clock->nowUtc();
        $requestId = $requestId ?? ($GLOBALS['MERIDIAN_REQUEST_ID'] ?? null);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return DB::connection()->transaction(function () use (
            $now, $action, $objectType, $objectId, $payloadJson,
            $actorType, $actorId, $requestId, $ipAddressCiphertext
        ): AuditLog {
            $prev = AuditLog::query()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            $prevHash = $prev instanceof AuditLog ? (string) $prev->row_hash : str_repeat('0', 64);

            $hashInput = implode('|', [
                $prevHash,
                $now->format('Y-m-d\TH:i:s\Z'),
                $actorType,
                (string) $actorId,
                $action,
                (string) $objectType,
                (string) $objectId,
                (string) $payloadJson,
            ]);
            $rowHash = hash('sha256', $hashInput);

            /** @var AuditLog $log */
            $log = AuditLog::query()->create([
                'occurred_at' => $now->format('Y-m-d H:i:s'),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => mb_substr($action, 0, 96),
                'object_type' => $objectType,
                'object_id' => $objectId,
                'request_id' => $requestId,
                'ip_address_ciphertext' => $ipAddressCiphertext,
                'payload_json' => $payloadJson,
                'previous_row_hash' => $prevHash,
                'row_hash' => $rowHash,
            ]);
            return $log;
        });
    }
}
