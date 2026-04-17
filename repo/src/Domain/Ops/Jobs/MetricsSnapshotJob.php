<?php

declare(strict_types=1);

namespace Meridian\Domain\Ops\Jobs;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Domain\Jobs\JobHandler;
use Meridian\Domain\Jobs\JobRun;
use Meridian\Infrastructure\Clock\Clock;
use Meridian\Infrastructure\Metrics\MetricsWriter;

/**
 * Periodic snapshot of aggregate platform counters into storage/metrics/*.ndjson.
 *
 * The snapshot emits one NDJSON record per metric sample. No per-user or per-content
 * payloads are written; only aggregate counters that are safe to export. The job is
 * idempotent: re-running it appends a new sample with the current timestamp and never
 * mutates domain tables.
 */
final class MetricsSnapshotJob implements JobHandler
{
    public function __construct(
        private readonly Clock $clock,
        private readonly MetricsWriter $metrics,
    ) {
    }

    public function handle(JobRun $run, array $payload): void
    {
        $labels = ['source' => 'metrics_snapshot_job'];

        foreach ($this->countBy('contents', 'risk_state') as $row) {
            $this->metrics->record('content.by_risk_state', (int) $row->c, [
                'risk_state' => (string) ($row->risk_state ?? 'unknown'),
            ] + $labels);
        }

        foreach ($this->countBy('moderation_cases', 'decision') as $row) {
            $this->metrics->record('moderation.cases_by_decision', (int) $row->c, [
                'decision' => (string) ($row->decision ?? 'pending'),
            ] + $labels);
        }

        foreach ($this->countBy('job_runs', 'status') as $row) {
            $this->metrics->record('jobs.runs_by_status', (int) $row->c, [
                'status' => (string) ($row->status ?? 'unknown'),
            ] + $labels);
        }

        $blacklistActive = (int) DB::table('blacklists')->whereNull('revoked_at')->count();
        $this->metrics->record('blacklists.active_total', $blacklistActive, $labels);

        $analyticsTotal = (int) DB::table('analytics_events')->count();
        $this->metrics->record('analytics.events_total', $analyticsTotal, $labels);

        $generatedReports = (int) DB::table('generated_reports')->count();
        $this->metrics->record('reports.generated_total', $generatedReports, $labels);

        $run->resume_marker = 'snapshot_at=' . $this->clock->nowUtc()->format('Y-m-d\TH:i:s\Z');
        $run->save();
    }

    /** @return array<int,object{c:int}> */
    private function countBy(string $table, string $column): array
    {
        return DB::table($table)
            ->selectRaw($column . ', COUNT(*) as c')
            ->groupBy($column)
            ->get()
            ->all();
    }
}
