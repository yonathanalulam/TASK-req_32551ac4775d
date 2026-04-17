<?php

declare(strict_types=1);

namespace Meridian\Domain\Events;

use Illuminate\Database\Eloquent\Model;

final class EventEquipment extends Model
{
    protected $table = 'event_equipment';
    public $timestamps = true;
    protected $fillable = ['key', 'name', 'category', 'description'];
}
