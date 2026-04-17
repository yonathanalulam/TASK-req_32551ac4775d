<?php

declare(strict_types=1);

namespace Meridian\Domain\Jobs;

use Illuminate\Database\Eloquent\Model;

final class JobDefinition extends Model
{
    protected $table = 'job_definitions';
    public $timestamps = true;
    protected $fillable = ['key', 'description', 'handler_class', 'schedule_cron', 'is_singleton', 'is_enabled'];
    protected $casts = ['is_singleton' => 'boolean', 'is_enabled' => 'boolean'];
}
