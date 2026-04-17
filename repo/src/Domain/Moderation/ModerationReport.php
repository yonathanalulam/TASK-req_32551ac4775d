<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Illuminate\Database\Eloquent\Model;

final class ModerationReport extends Model
{
    protected $table = 'moderation_reports';
    public $timestamps = false;
    protected $fillable = [
        'case_id', 'content_id', 'source_record_id', 'reporter_user_id',
        'reporter_type', 'reason_code', 'details', 'sla_due_at', 'status', 'created_at',
    ];
}
