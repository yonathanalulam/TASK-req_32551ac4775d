<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RulePackVersion extends Model
{
    protected $table = 'rule_pack_versions';
    public $timestamps = true;
    protected $fillable = ['rule_pack_id', 'version', 'status', 'published_at', 'published_by_user_id', 'notes'];

    public function rules(): HasMany
    {
        return $this->hasMany(RulePackRule::class, 'rule_pack_version_id');
    }
}
