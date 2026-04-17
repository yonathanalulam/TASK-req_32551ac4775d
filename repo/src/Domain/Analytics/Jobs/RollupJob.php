<?php

declare(strict_types=1);

namespace Meridian\Domain\Analytics\Jobs;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Domain\Jobs\JobHandler;
use Meridian\Domain\Jobs\JobRun;
use Meridian\Infrastructure\Clock\Clock;

/**
 * Rolls up analytics events into daily per-dimension aggregates so standard reports
 * stay fast. Rollups are derived: rebuilding is always possible from raw events.
 */
final class RollupJob implements JobHandler
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function handle(JobRun $run, array $payload): void
    {
        $now = $this->clock->nowUtc();
        $windowStart = $now->modify('-2 days')->format('Y-m-d 00:00:00');
        $windowEnd = $now->format('Y-m-d 23:59:59');
        $nowFmt = $now->format('Y-m-d H:i:s');

        $rows = DB::table('analytics_events')
            ->selectRaw('DATE(occurred_at) AS rollup_day, event_type, language, media_source, section_tag, COUNT(*) AS c, COALESCE(SUM(dwell_seconds), 0) AS dwell')
            ->whereBetween('occurred_at', [$windowStart, $windowEnd])
            ->groupBy('rollup_day', 'event_type', 'language', 'media_source', 'section_tag')
            ->get();

        $inserted = 0;
        foreach ($rows as $r) {
            // language dimension
            $this->upsertRollup($r->rollup_day, $r->event_type, 'language', (string) ($r->language ?? ''), (int) $r->c, (int) $r->dwell, $nowFmt);
            $this->upsertRollup($r->rollup_day, $r->event_type, 'media_source', (string) ($r->media_source ?? ''), (int) $r->c, (int) $r->dwell, $nowFmt);
            $this->upsertRollup($r->rollup_day, $r->event_type, 'section_tag', (string) ($r->section_tag ?? ''), (int) $r->c, (int) $r->dwell, $nowFmt);
            $inserted++;
        }

        $run->resume_marker = 'groups=' . $inserted;
        $run->save();
    }

    private function upsertRollup(string $day, string $eventType, string $dimKey, string $dimValue, int $count, int $dwell, string $nowFmt): void
    {
        $row = DB::table('analytics_rollups')
            ->where('rollup_day', $day)
            ->where('event_type', $eventType)
            ->where('dimension_key', $dimKey)
            ->where('dimension_value', $dimValue)
            ->first();
        if ($row === null) {
            DB::table('analytics_rollups')->insert([
                'rollup_day' => $day,
                'event_type' => $eventType,
                'dimension_key' => $dimKey,
                'dimension_value' => $dimValue,
                'count_value' => $count,
                'sum_dwell_seconds' => $dwell,
                'updated_at' => $nowFmt,
            ]);
        } else {
            DB::table('analytics_rollups')
                ->where('id', $row->id)
                ->update([
                    'count_value' => $count,
                    'sum_dwell_seconds' => $dwell,
                    'updated_at' => $nowFmt,
                ]);
        }
    }
}
