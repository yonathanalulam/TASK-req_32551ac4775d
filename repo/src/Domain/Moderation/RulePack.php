<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RulePack extends Model
{
    protected $table = 'rule_packs';
    public $timestamps = true;
    protected $fillable = ['key', 'description'];

    public function versions(): HasMany
    {
        return $this->hasMany(RulePackVersion::class, 'rule_pack_id');
    }
}
