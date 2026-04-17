<?php

declare(strict_types=1);

namespace Meridian\Domain\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Event extends Model
{
    protected $table = 'events';
    protected $primaryKey = 'event_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = true;
    protected $fillable = ['event_id', 'name', 'event_family_key', 'template_id', 'active_version_id', 'created_by_user_id'];

    public function versions(): HasMany
    {
        return $this->hasMany(EventVersion::class, 'event_id', 'event_id');
    }
}
