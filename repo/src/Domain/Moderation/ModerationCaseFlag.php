<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Illuminate\Database\Eloquent\Model;

final class ModerationCaseFlag extends Model
{
    protected $table = 'moderation_case_flags';
    public $timestamps = false;
    protected $fillable = ['case_id', 'rule_pack_version_id', 'rule_id', 'rule_kind', 'evidence_json', 'created_at'];
}
