<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Illuminate\Database\Eloquent\Model;

final class ModerationCase extends Model
{
    protected $table = 'moderation_cases';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = true;
    protected $fillable = [
        'id', 'content_id', 'source_record_id', 'case_type', 'status',
        'reason_code', 'decision', 'rule_pack_version_id', 'assigned_reviewer_id',
        'opened_at', 'sla_due_at', 'resolved_at', 'has_active_appeal',
    ];
    protected $casts = [
        'opened_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'resolved_at' => 'datetime',
        'has_active_appeal' => 'boolean',
    ];
}
