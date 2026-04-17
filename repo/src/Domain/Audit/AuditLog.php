<?php

declare(strict_types=1);

namespace Meridian\Domain\Audit;

use Illuminate\Database\Eloquent\Model;

final class AuditLog extends Model
{
    protected $table = 'audit_logs';
    public $timestamps = false;
    protected $fillable = [
        'occurred_at', 'actor_type', 'actor_id', 'action',
        'object_type', 'object_id', 'request_id', 'ip_address_ciphertext',
        'payload_json', 'previous_row_hash', 'row_hash',
    ];
}
