<?php

declare(strict_types=1);

namespace Meridian\Domain\Reports;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\NotFoundException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Domain\Authorization\Policy;
use Meridian\Infrastructure\Clock\Clock;

/**
 * Report scheduling and local file generation.
 *
 * Supported report_kind values: content_summary, moderation_summary, event_summary, analytics_daily.
 *
 * Generation path:
 *   1. resolve report_kind -> rows (masked/unmasked based on caller permission)
 *   2. write to temp file in report_root
 *   3. compute SHA-256 checksum
 *   4. atomic rename to final path
 *   5. record report_files metadata + audit entry
 *   6. set expires_at = now + retention_days
 */
final class ReportService
{
    /** @var list<string> */
    private const REPORT_KINDS = ['content_summary', 'moderation_summary', 'event_summary', 'analytics_daily'];

    public function __construct(
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly array $config,
        private readonly Policy $policy,
    ) {
    }

    public function createScheduled(User $actor, array $input): ScheduledReport
    {
        if (!$this->policy->canCreateScheduledReport($actor)) {
            throw new AuthorizationException('Missing permission: governance.export_reports');
        }
        $key = (string) ($input['key'] ?? '');
        $kind = (string) ($input['report_kind'] ?? '');
        $format = (string) ($input['output_format'] ?? 'csv');
        if ($key === '' || !in_array($kind, self::REPORT_KINDS, true)) {
            throw new ValidationException('Invalid report definition.', ['fields' => ['key', 'report_kind']]);
        }
        if (!in_array($format, ['csv', 'json'], true)) {
            throw new ValidationException('output_format must be csv or json.', ['field' => 'output_format']);
        }
        if (ScheduledReport::query()->where('key', $key)->exists()) {
            throw new ValidationException('Scheduled report key already exists.', ['field' => 'key']);
        }
        /** @var ScheduledReport $s */
        $s = ScheduledReport::query()->create([
            'key' => $key,
            'description' => isset($input['description']) ? (string) $input['description'] : null,
            'report_kind' => $kind,
            'parameters_json' => isset($input['parameters']) ? json_encode($input['parameters']) : null,
            'output_format' => $format,
            'cron_expression' => isset($input['cron']) ? (string) $input['cron'] : null,
            'is_active' => true,
            'created_by_user_id' => (int) $actor->id,
        ]);
        $this->audit->record('reports.scheduled_created', 'scheduled_report', (string) $s->id, [
            'key' => $key,
            'report_kind' => $kind,
            'format' => $format,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $s;
    }

    public function listScheduled(): array
    {
        return ScheduledReport::query()->orderBy('key')->get()->map(static fn(ScheduledReport $r) => [
            'id' => (int) $r->id,
            'key' => $r->key,
            'description' => $r->description,
            'report_kind' => $r->report_kind,
            'output_format' => $r->output_format,
            'cron_expression' => $r->cron_expression,
            'is_active' => (bool) $r->is_active,
        ])->all();
    }

    public function runNow(User $actor, int $scheduledId): GeneratedReport
    {
        if (!$this->policy->canCreateScheduledReport($actor)) {
            throw new AuthorizationException('Missing permission: governance.export_reports');
        }
        $def = ScheduledReport::query()->find($scheduledId);
        if (!$def instanceof ScheduledReport) {
            throw new NotFoundException('Scheduled report not found.');
        }
        $unmasked = $this->policy->canUnmaskAnalytics($actor);
        return $this->generate($actor, $def->key, $def->report_kind, $def->output_format, json_decode((string) $def->parameters_json, true) ?: [], $unmasked, (int) $def->id);
    }

    public function listGenerated(User $actor, int $page, int $size): array
    {
        $q = GeneratedReport::query()->orderByDesc('id');
        // Non-administrators only see reports they requested or are bound to by scope.
        if (!$this->policy->isAdministrator($actor)) {
            $scopedIds = DB::table('user_role_bindings')
                ->where('user_id', (int) $actor->id)
                ->where('scope_type', 'report')
                ->pluck('scope_ref')
                ->all();
            $q->where(function ($qq) use ($actor, $scopedIds) {
                $qq->where('requested_by_user_id', (int) $actor->id);
                if ($scopedIds !== []) {
                    $qq->orWhereIn('id', $scopedIds);
                }
            });
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $size)->get()->all();
        return ['items' => array_map(static fn(GeneratedReport $r) => [
            'id' => (int) $r->id,
            'scheduled_report_id' => $r->scheduled_report_id,
            'status' => $r->status,
            'report_key' => $r->report_key,
            'started_at' => $r->started_at,
            'completed_at' => $r->completed_at,
            'expires_at' => $r->expires_at,
            'row_count' => $r->row_count,
            'unmasked' => (bool) $r->unmasked,
        ], $rows), 'total' => $total];
    }

    public function getGenerated(User $actor, int $id): array
    {
        $r = GeneratedReport::query()->find($id);
        if (!$r instanceof GeneratedReport) {
            throw new NotFoundException('Generated report not found.');
        }
        if (!$this->policy->canViewReport($actor, $r)) {
            throw new AuthorizationException('Not authorized to view this report.');
        }
        $file = ReportFile::query()->where('generated_report_id', $id)->first();
        return [
            'id' => (int) $r->id,
            'scheduled_report_id' => $r->scheduled_report_id,
            'status' => $r->status,
            'report_key' => $r->report_key,
            'started_at' => $r->started_at,
            'completed_at' => $r->completed_at,
            'expires_at' => $r->expires_at,
            'row_count' => $r->row_count,
            'unmasked' => (bool) $r->unmasked,
            'file' => $file instanceof ReportFile ? [
                'relative_path' => $file->relative_path,
                'checksum_sha256' => $file->checksum_sha256,
                'size_bytes' => (int) $file->size_bytes,
                'format' => $file->format,
            ] : null,
        ];
    }

    public function downloadPath(User $actor, int $id): array
    {
        $r = GeneratedReport::query()->find($id);
        if (!$r instanceof GeneratedReport || $r->status !== 'completed') {
            throw new NotFoundException('Report not available.');
        }
        if (!$this->policy->canDownloadReport($actor, $r)) {
            // Separate out the unmasked gating from the base view gate for clearer errors.
            if ((bool) $r->unmasked && !UserPermissions::hasPermission($actor, 'sensitive.unmask')) {
                throw new AuthorizationException('Unmasked report requires sensitive.unmask permission.');
            }
            throw new AuthorizationException('Not authorized to download this report.');
        }
        $file = ReportFile::query()->where('generated_report_id', $id)->firstOrFail();
        $root = rtrim((string) $this->config['report_root'], '/');
        $full = $root . '/' . ltrim((string) $file->relative_path, '/');
        if (!is_file($full)) {
            throw new NotFoundException('Report file missing on disk.');
        }
        $this->audit->record('reports.export_downloaded', 'generated_report', (string) $r->id, [
            'report_key' => $r->report_key,
            'unmasked' => (bool) $r->unmasked,
        ], actorType: 'user', actorId: (string) $actor->id);
        return [
            'absolute_path' => $full,
            'relative_path' => $file->relative_path,
            'checksum' => $file->checksum_sha256,
            'format' => $file->format,
            'size_bytes' => (int) $file->size_bytes,
        ];
    }

    public function generate(User $actor, string $key, string $kind, string $format, array $parameters, bool $unmasked, ?int $scheduledId = null): GeneratedReport
    {
        $now = $this->clock->nowUtc();
        $expires = $now->modify('+' . (int) $this->config['retention']['generated_reports_days'] . ' days');
        /** @var GeneratedReport $rep */
        $rep = GeneratedReport::query()->create([
            'scheduled_report_id' => $scheduledId,
            'status' => 'running',
            'report_key' => $key,
            'parameters_json' => json_encode($parameters),
            'started_at' => $now->format('Y-m-d H:i:s'),
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'requested_by_user_id' => (int) $actor->id,
            'unmasked' => $unmasked,
        ]);
        try {
            [$headers, $rows] = $this->computeRows($kind, $parameters, $unmasked);
            $root = rtrim((string) $this->config['report_root'], '/');
            if (!is_dir($root)) {
                @mkdir($root, 0770, true);
            }
            $fileName = $this->sanitizeFileName($key) . '-' . $now->format('Ymd\THis') . '-r' . $rep->id . '.' . $format;
            $tempPath = $root . '/.' . $fileName . '.tmp';
            $finalPath = $root . '/' . $fileName;
            if ($format === 'csv') {
                $this->writeCsv($tempPath, $headers, $rows);
            } else {
                $this->writeJson($tempPath, $headers, $rows);
            }
            $checksum = hash_file('sha256', $tempPath);
            if ($checksum === false) {
                throw new \RuntimeException('failed to checksum report file');
            }
            if (!rename($tempPath, $finalPath)) {
                throw new \RuntimeException('atomic rename failed');
            }
            $size = filesize($finalPath) ?: 0;
            ReportFile::query()->create([
                'generated_report_id' => $rep->id,
                'relative_path' => $fileName,
                'checksum_sha256' => $checksum,
                'size_bytes' => $size,
                'format' => $format,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $rep->status = 'completed';
            $rep->completed_at = $this->clock->nowUtc()->format('Y-m-d H:i:s');
            $rep->row_count = count($rows);
            $rep->save();
            $this->audit->record('reports.generated', 'generated_report', (string) $rep->id, [
                'report_key' => $key,
                'format' => $format,
                'rows' => count($rows),
                'unmasked' => $unmasked,
            ], actorType: 'user', actorId: (string) $actor->id);
        } catch (\Throwable $e) {
            $rep->status = 'failed';
            $rep->completed_at = $this->clock->nowUtc()->format('Y-m-d H:i:s');
            $rep->error_reason = mb_substr($e::class . ': ' . $e->getMessage(), 0, 500);
            $rep->save();
            throw $e;
        }
        return $rep;
    }

    /** @return array{0:array<int,string>,1:array<int,array<string,mixed>>} */
    private function computeRows(string $kind, array $parameters, bool $unmasked): array
    {
        switch ($kind) {
            case 'content_summary':
                $rows = DB::table('contents')->selectRaw("DATE(ingested_at) AS day, language, media_source, risk_state, COUNT(*) AS c")
                    ->groupBy('day', 'language', 'media_source', 'risk_state')
                    ->orderBy('day')
                    ->get()->all();
                return [
                    ['day', 'language', 'media_source', 'risk_state', 'count'],
                    array_map(static fn($r) => [
                        'day' => $r->day,
                        'language' => $r->language,
                        'media_source' => $r->media_source,
                        'risk_state' => $r->risk_state,
                        'count' => (int) $r->c,
                    ], $rows),
                ];
            case 'moderation_summary':
                $rows = DB::table('moderation_cases')->selectRaw("DATE(opened_at) AS day, status, decision, COUNT(*) AS c")
                    ->groupBy('day', 'status', 'decision')
                    ->orderBy('day')
                    ->get()->all();
                return [
                    ['day', 'status', 'decision', 'count'],
                    array_map(static fn($r) => [
                        'day' => $r->day,
                        'status' => $r->status,
                        'decision' => $r->decision,
                        'count' => (int) $r->c,
                    ], $rows),
                ];
            case 'event_summary':
                $rows = DB::table('event_publications')->selectRaw("DATE(created_at) AS day, action, COUNT(*) AS c")
                    ->groupBy('day', 'action')
                    ->orderBy('day')
                    ->get()->all();
                return [
                    ['day', 'action', 'count'],
                    array_map(static fn($r) => [
                        'day' => $r->day,
                        'action' => $r->action,
                        'count' => (int) $r->c,
                    ], $rows),
                ];
            case 'analytics_daily':
                $rows = DB::table('analytics_events')->selectRaw("DATE(occurred_at) AS day, event_type, language, media_source, section_tag, COUNT(*) AS c, COALESCE(SUM(dwell_seconds),0) AS dwell")
                    ->groupBy('day', 'event_type', 'language', 'media_source', 'section_tag')
                    ->orderBy('day')
                    ->get()->all();
                return [
                    ['day', 'event_type', 'language', 'media_source', 'section_tag', 'count', 'total_dwell_seconds'],
                    array_map(static fn($r) => [
                        'day' => $r->day,
                        'event_type' => $r->event_type,
                        'language' => $r->language,
                        'media_source' => $r->media_source,
                        'section_tag' => $r->section_tag,
                        'count' => (int) $r->c,
                        'total_dwell_seconds' => (int) $r->dwell,
                    ], $rows),
                ];
        }
        throw new ValidationException('Unknown report_kind: ' . $kind);
    }

    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('failed to open report temp file');
        }
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, array_values($row));
        }
        fclose($fp);
    }

    private function writeJson(string $path, array $headers, array $rows): void
    {
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('failed to open report temp file');
        }
        $payload = [
            'headers' => $headers,
            'rows' => $rows,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'row_count' => count($rows),
        ];
        fwrite($fp, json_encode($payload, JSON_UNESCAPED_SLASHES));
        fclose($fp);
    }

    private function sanitizeFileName(string $name): string
    {
        $clean = preg_replace('/[^a-z0-9_\-]+/i', '_', $name) ?? '';
        return trim($clean, '_') ?: 'report';
    }
}
