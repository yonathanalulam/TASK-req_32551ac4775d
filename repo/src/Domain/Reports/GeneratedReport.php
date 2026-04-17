<?php

declare(strict_types=1);

namespace Meridian\Domain\Reports;

use Illuminate\Database\Eloquent\Model;

final class GeneratedReport extends Model
{
    protected $table = 'generated_reports';
    public $timestamps = true;
    protected $fillable = [
        'scheduled_report_id', 'status', 'report_key', 'parameters_json',
        'started_at', 'completed_at', 'expires_at', 'requested_by_user_id',
        'row_count', 'error_reason', 'unmasked',
    ];
    protected $casts = ['unmasked' => 'boolean'];
}
