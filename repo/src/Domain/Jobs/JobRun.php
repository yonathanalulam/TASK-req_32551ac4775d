<?php

declare(strict_types=1);

namespace Meridian\Domain\Jobs;

use Illuminate\Database\Eloquent\Model;

final class JobRun extends Model
{
    protected $table = 'job_runs';
    public $timestamps = false;
    protected $fillable = [
        'job_key', 'status', 'attempt', 'max_attempts',
        'started_at', 'ended_at', 'queued_at', 'next_run_at',
        'actor_identity', 'resume_marker', 'failure_reason', 'payload_json',
    ];
}
