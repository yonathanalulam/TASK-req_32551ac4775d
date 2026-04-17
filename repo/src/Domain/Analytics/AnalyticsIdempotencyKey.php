<?php

declare(strict_types=1);

namespace Meridian\Domain\Analytics;

use Illuminate\Database\Eloquent\Model;

final class AnalyticsIdempotencyKey extends Model
{
    protected $table = 'analytics_idempotency_keys';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'idempotency_key';
    protected $keyType = 'string';
    protected $fillable = [
        'idempotency_key', 'actor_identity', 'first_seen_at',
        'expires_at', 'analytics_event_id', 'status_code', 'response_fingerprint',
    ];
}
