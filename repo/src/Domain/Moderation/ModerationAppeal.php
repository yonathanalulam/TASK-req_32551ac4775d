<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Illuminate\Database\Eloquent\Model;

final class ModerationAppeal extends Model
{
    protected $table = 'moderation_appeals';
    public $timestamps = false;
    protected $fillable = [
        'case_id', 'appellant_user_id', 'status', 'rationale',
        'submitted_at', 'resolved_at', 'resolved_by_user_id', 'resolution_reason',
    ];
}
