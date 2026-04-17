<?php

declare(strict_types=1);

namespace Meridian\Domain\Events;

use Illuminate\Database\Eloquent\Model;

final class EventPublication extends Model
{
    protected $table = 'event_publications';
    public $timestamps = false;
    protected $fillable = ['event_id', 'event_version_id', 'action', 'actor_user_id', 'rationale', 'created_at'];
}
