<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Ops;

use Meridian\Domain\Jobs\JobRun;
use Meridian\Domain\Ops\Jobs\MetricsSnapshotJob;
use Meridian\Infrastructure\Metrics\MetricsWriter;
use Meridian\Tests\Integration\IntegrationTestCase;

/**
 * Exercises Fix B — metrics export is backed by a real writer that lands NDJSON
 * files under storage/metrics (or the configured equivalent). Writers must never
 * emit sensitive fields and repeated writes must follow a deterministic shape.
 */
final class MetricsExportTest extends IntegrationTestCase
{
    public function testMetricsWriterLandsNdjsonInConfiguredRoot(): void
    {
        /** @var MetricsWriter $writer */
        $writer = $this->container->get(MetricsWriter::class);
        $writer->record('http.request.count', 1, ['method' => 'GET', 'status' => 200]);
        $writer->record('http.request.duration_ms', 42, ['method' => 'GET', 'status' => 200]);

        $file = $this->findMetricsFile();
        self::assertNotNull($file, 'expected a metrics-*.ndjson file under ' . $this->metricsRoot());
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file)), static fn($l) => $l !== ''));
        self::assertGreaterThanOrEqual(2, count($lines));
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            self::assertIsArray($record, 'each line must be valid JSON');
            self::assertArrayHasKey('ts', $record);
            self::assertArrayHasKey('name', $record);
            self::assertArrayHasKey('value', $record);
            self::assertArrayHasKey('labels', $record);
        }
    }

    public function testWriterRedactsSecretLookingStringLabels(): void
    {
        /** @var MetricsWriter $writer */
        $writer = $this->container->get(MetricsWriter::class);
        $writer->record('token.touch', 1, [
            // 64-char hex string — treated as a secret-fingerprint and redacted.
            'fingerprint' => str_repeat('a', 64),
            'safe' => 'ok',
        ]);
        $file = $this->findMetricsFile();
        self::assertNotNull($file);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file)), static fn($l) => $l !== ''));
        $found = false;
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (($record['name'] ?? '') === 'token.touch') {
                self::assertSame('[redacted]', $record['labels']['fingerprint']);
                self::assertSame('ok', $record['labels']['safe']);
                $found = true;
            }
        }
        self::assertTrue($found, 'expected the token.touch record to appear in the metrics file');
    }

    public function testHttpRequestsEmitMetricsViaMiddleware(): void
    {
        $this->request('GET', '/api/v1/health');
        $file = $this->findMetricsFile();
        self::assertNotNull($file, 'middleware should have emitted a metrics line for /health');
        $contents = (string) file_get_contents($file);
        self::assertStringContainsString('"http.request.count"', $contents);
        self::assertStringContainsString('"http.request.duration_ms"', $contents);
    }

    public function testSnapshotJobWritesAggregateCounters(): void
    {
        /** @var MetricsSnapshotJob $job */
        $job = $this->container->get(MetricsSnapshotJob::class);
        $run = JobRun::query()->create([
            'job_key' => 'metrics.snapshot',
            'status' => 'running',
            'attempt' => 1,
            'max_attempts' => 1,
            'queued_at' => gmdate('Y-m-d H:i:s'),
            'next_run_at' => gmdate('Y-m-d H:i:s'),
            'actor_identity' => 'test',
            'payload_json' => '[]',
        ]);
        $job->handle($run, []);

        $file = $this->findMetricsFile();
        self::assertNotNull($file);
        $contents = (string) file_get_contents($file);
        self::assertStringContainsString('"blacklists.active_total"', $contents);
        self::assertStringContainsString('"analytics.events_total"', $contents);
        self::assertStringContainsString('"jobs.runs_by_status"', $contents);
        self::assertStringContainsString('"source":"metrics_snapshot_job"', $contents);
    }

    public function testSnapshotNeverLeaksSensitiveFields(): void
    {
        /** @var MetricsSnapshotJob $job */
        $job = $this->container->get(MetricsSnapshotJob::class);
        $run = JobRun::query()->create([
            'job_key' => 'metrics.snapshot',
            'status' => 'running',
            'attempt' => 1,
            'max_attempts' => 1,
            'queued_at' => gmdate('Y-m-d H:i:s'),
            'next_run_at' => gmdate('Y-m-d H:i:s'),
            'actor_identity' => 'test',
            'payload_json' => '[]',
        ]);
        $job->handle($run, []);
        $file = $this->findMetricsFile();
        self::assertNotNull($file);
        $contents = (string) file_get_contents($file);
        foreach (['password', 'token', 'ciphertext', 'security_answer', 'master_key'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $contents, "metrics export must not leak {$forbidden}");
        }
    }

    private function findMetricsFile(): ?string
    {
        $dir = $this->metricsRoot();
        if (!is_dir($dir)) {
            return null;
        }
        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'metrics-*.ndjson') ?: [];
        return $files === [] ? null : $files[0];
    }
}
