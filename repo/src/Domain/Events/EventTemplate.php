<?php

declare(strict_types=1);

namespace Meridian\Domain\Events;

use Illuminate\Database\Eloquent\Model;

final class EventTemplate extends Model
{
    protected $table = 'event_templates';
    public $timestamps = true;
    protected $fillable = [
        'key', 'template_type', 'description',
        'default_attempt_limit', 'default_checkin_open_minutes_before', 'default_late_cutoff_minutes_after',
    ];
}
