<?php

declare(strict_types=1);

namespace Meridian\Domain\Analytics;

use Illuminate\Database\Eloquent\Model;

final class AnalyticsRollup extends Model
{
    protected $table = 'analytics_rollups';
    public $timestamps = false;
    protected $fillable = [
        'rollup_day', 'event_type', 'dimension_key', 'dimension_value',
        'count_value', 'sum_dwell_seconds', 'updated_at',
    ];
}
