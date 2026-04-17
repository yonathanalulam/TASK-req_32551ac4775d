<?php

declare(strict_types=1);

namespace Meridian\Domain\Events;

use Illuminate\Database\Eloquent\Model;

final class EventBinding extends Model
{
    protected $table = 'event_bindings';
    public $timestamps = false;
    protected $fillable = ['event_version_id', 'binding_type', 'venue_id', 'equipment_id', 'quantity', 'notes'];
}
