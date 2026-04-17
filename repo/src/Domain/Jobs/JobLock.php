<?php

declare(strict_types=1);

namespace Meridian\Domain\Jobs;

use Illuminate\Database\Eloquent\Model;

final class JobLock extends Model
{
    protected $table = 'job_locks';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'lock_key';
    protected $keyType = 'string';
    protected $fillable = ['lock_key', 'holder', 'acquired_at', 'expires_at'];
}
