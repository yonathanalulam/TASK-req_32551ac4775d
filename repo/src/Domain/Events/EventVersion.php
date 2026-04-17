<?php

declare(strict_types=1);

namespace Meridian\Domain\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class EventVersion extends Model
{
    protected $table = 'event_versions';
    public $timestamps = true;
    protected $fillable = [
        'event_id', 'version', 'status',
        'effective_from', 'effective_to', 'config_snapshot_json',
        'draft_updated_at', 'draft_version_number',
        'published_at', 'published_by_user_id', 'supersedes_version_id',
    ];
    protected $casts = [
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'draft_updated_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function ruleSet(): HasOne
    {
        return $this->hasOne(EventRuleSet::class, 'event_version_id');
    }

    public function advancementRules(): HasMany
    {
        return $this->hasMany(EventAdvancementRule::class, 'event_version_id');
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(EventBinding::class, 'event_version_id');
    }
}
