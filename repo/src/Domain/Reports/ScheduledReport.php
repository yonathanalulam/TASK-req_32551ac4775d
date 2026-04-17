<?php

declare(strict_types=1);

namespace Meridian\Domain\Reports;

use Illuminate\Database\Eloquent\Model;

final class ScheduledReport extends Model
{
    protected $table = 'scheduled_reports';
    public $timestamps = true;
    protected $fillable = [
        'key', 'description', 'report_kind', 'parameters_json',
        'output_format', 'cron_expression', 'is_active', 'created_by_user_id',
    ];
    protected $casts = ['is_active' => 'boolean'];
}
