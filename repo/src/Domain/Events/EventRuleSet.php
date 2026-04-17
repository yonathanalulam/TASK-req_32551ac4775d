<?php

declare(strict_types=1);

namespace Meridian\Domain\Events;

use Illuminate\Database\Eloquent\Model;

final class EventRuleSet extends Model
{
    protected $table = 'event_rule_sets';
    public $timestamps = false;
    protected $primaryKey = 'event_version_id';
    public $incrementing = false;
    protected $keyType = 'int';
    protected $fillable = [
        'event_version_id', 'attempt_limit',
        'checkin_open_minutes_before', 'late_cutoff_minutes_after', 'overrides_json',
    ];
}
