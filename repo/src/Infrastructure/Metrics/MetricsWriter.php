<?php

declare(strict_types=1);

namespace Meridian\Infrastructure\Metrics;

use Meridian\Infrastructure\Clock\Clock;

/**
 * Deterministic local metrics export.
 *
 * Writes one NDJSON record per call to `{metricsRoot}/metrics-YYYY-MM-DD.ndjson`.
 * Files are rotated daily by virtue of the date-suffixed filename. Every record
 * has the fixed shape:
 *
 *   {"ts":"<ISO8601 UTC>","name":"<metric>","value":<number>,"labels":{...}}
 *
 * Offline guarantees: writes are append-only to the local filesystem under
 * storage/metrics; no network handlers are ever attached. Labels are sanitised
 * to scalar values only so structured payloads (which might leak secrets) cannot
 * flow through this path.
 */
final class MetricsWriter
{
    public function __construct(
        private readonly Clock $clock,
        private readonly string $metricsRoot,
    ) {
    }

    /**
     * @param array<string,scalar|null> $labels
     */
    public function record(string $name, int|float $value, array $labels = []): void
    {
        $dir = rtrim($this->metricsRoot, '/\\');
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }
        }
        if (!is_writable($dir)) {
            return;
        }
        $now = $this->clock->nowUtc();
        $payload = [
            'ts' => $now->format('Y-m-d\TH:i:s\Z'),
            'name' => $this->sanitizeName($name),
            'value' => is_int($value) ? $value : (float) $value,
            'labels' => $this->sanitizeLabels($labels),
        ];
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
        if ($line === "\n" || $line === false) {
            return;
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'metrics-' . $now->format('Y-m-d') . '.ndjson';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public function metricsRoot(): string
    {
        return $this->metricsRoot;
    }

    private function sanitizeName(string $name): string
    {
        $trimmed = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $name) ?? 'metric';
        return substr($trimmed, 0, 96);
    }

    /**
     * @param array<string,mixed> $labels
     * @return array<string,scalar|null>
     */
    private function sanitizeLabels(array $labels): array
    {
        $out = [];
        foreach ($labels as $k => $v) {
            $key = preg_replace('/[^A-Za-z0-9_.\-]/', '_', (string) $k) ?? '';
            if ($key === '') {
                continue;
            }
            if ($v === null || is_bool($v) || is_int($v) || is_float($v)) {
                $out[$key] = $v;
                continue;
            }
            if (is_string($v)) {
                // Truncate and reject obvious secret fingerprints (hex strings longer than 40 chars look
                // like tokens, hashes, or ciphertext). This is defence in depth; callers should not
                // pass such fields in the first place.
                if (strlen($v) > 40 && preg_match('/^[A-Fa-f0-9+\/=]+$/', $v) === 1) {
                    $out[$key] = '[redacted]';
                    continue;
                }
                $out[$key] = substr($v, 0, 120);
            }
        }
        ksort($out);
        return $out;
    }
}
