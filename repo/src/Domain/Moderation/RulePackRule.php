<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Illuminate\Database\Eloquent\Model;

final class RulePackRule extends Model
{
    protected $table = 'rule_pack_rules';
    public $timestamps = false;
    protected $fillable = ['rule_pack_version_id', 'rule_kind', 'pattern', 'threshold', 'severity', 'description', 'created_at'];
}
