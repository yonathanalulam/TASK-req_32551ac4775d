<?php

declare(strict_types=1);

namespace Meridian\Domain\Reports;

use Illuminate\Database\Eloquent\Model;

final class ReportFile extends Model
{
    protected $table = 'report_files';
    public $timestamps = false;
    protected $fillable = [
        'generated_report_id', 'relative_path', 'checksum_sha256',
        'size_bytes', 'format', 'created_at',
    ];
}
