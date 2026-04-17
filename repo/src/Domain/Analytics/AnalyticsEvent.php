<?php

declare(strict_types=1);

namespace Meridian\Domain\Analytics;

use Illuminate\Database\Eloquent\Model;

final class AnalyticsEvent extends Model
{
    protected $table = 'analytics_events';
    public $timestamps = false;
    protected $fillable = [
        'occurred_at', 'received_at', 'actor_type', 'actor_id', 'session_id',
        'event_type', 'object_type', 'object_id', 'dwell_seconds', 'idempotency_key',
        'properties_json', 'role_context', 'language', 'media_source', 'section_tag',
        'ip_address_ciphertext',
    ];
}
