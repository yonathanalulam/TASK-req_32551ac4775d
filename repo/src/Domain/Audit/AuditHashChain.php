<?php

declare(strict_types=1);

namespace Meridian\Domain\Audit;

use Illuminate\Database\Eloquent\Model;

final class AuditHashChain extends Model
{
    protected $table = 'audit_hash_chain';
    public $timestamps = false;
    protected $primaryKey = 'chain_date';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'chain_date', 'previous_day_hash', 'first_log_id', 'last_log_id',
        'row_count', 'chain_hash', 'finalized_at', 'finalized_by',
    ];
}
