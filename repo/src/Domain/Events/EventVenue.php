<?php

declare(strict_types=1);

namespace Meridian\Domain\Events;

use Illuminate\Database\Eloquent\Model;

final class EventVenue extends Model
{
    protected $table = 'event_venues';
    public $timestamps = true;
    protected $fillable = ['key', 'name', 'location_description', 'capacity'];
}
