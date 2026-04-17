<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Illuminate\Database\Eloquent\Model;

final class ModerationDecision extends Model
{
    protected $table = 'moderation_decisions';
    public $timestamps = false;
    protected $fillable = [
        'case_id', 'decision', 'decision_source', 'decided_by_user_id',
        'rule_pack_version_id', 'reason', 'evidence_json', 'decided_at',
    ];
}
