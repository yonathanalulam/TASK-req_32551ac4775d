<?php

declare(strict_types=1);

namespace Meridian\Domain\Events;

use Illuminate\Database\Eloquent\Model;

final class EventAdvancementRule extends Model
{
    protected $table = 'event_advancement_rules';
    public $timestamps = false;
    protected $fillable = ['event_version_id', 'precedence', 'criterion', 'criterion_config_json', 'description'];
}
