<?php

declare(strict_types=1);

namespace Meridian\Domain\Reports\Jobs;

use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Jobs\JobHandler;
use Meridian\Domain\Jobs\JobRun;
use Meridian\Domain\Reports\GeneratedReport;
use Meridian\Domain\Reports\ReportFile;
use Meridian\Infrastructure\Clock\Clock;

/**
 * Deletes expired generated_reports and unlinks their files from disk. Records an
 * audit entry for each expiration so the retention lineage is verifiable.
 */
final class ReportRetentionJob implements JobHandler
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly string $reportRoot,
        private readonly int $retentionDays,
    ) {
    }

    public function handle(JobRun $run, array $payload): void
    {
        $now = $this->clock->nowUtc();
        $cutoff = $now->format('Y-m-d H:i:s');

        $expired = GeneratedReport::query()
            ->whereIn('status', ['completed', 'failed'])
            ->where('expires_at', '<', $cutoff)
            ->get()->all();

        $deletedFiles = 0;
        foreach ($expired as $rep) {
            $files = ReportFile::query()->where('generated_report_id', $rep->id)->get()->all();
            foreach ($files as $file) {
                $full = rtrim($this->reportRoot, '/') . '/' . ltrim((string) $file->relative_path, '/');
                if (is_file($full)) {
                    @unlink($full);
                }
                $file->delete();
                $deletedFiles++;
            }
            $rep->status = 'expired';
            $rep->save();
            $this->audit->record('reports.retention_expired', 'generated_report', (string) $rep->id, [
                'report_key' => $rep->report_key,
                'retention_days' => $this->retentionDays,
            ], actorType: 'scheduled_job', actorId: 'reports.retention');
        }

        $run->resume_marker = 'expired=' . count($expired) . ';files=' . $deletedFiles;
        $run->save();
    }
}
