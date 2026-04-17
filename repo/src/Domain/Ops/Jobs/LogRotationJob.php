<?php

declare(strict_types=1);

namespace Meridian\Domain\Ops\Jobs;

use Meridian\Domain\Jobs\JobHandler;
use Meridian\Domain\Jobs\JobRun;

/**
 * Rotates storage/logs/*.log files by suffixing with the current UTC date when they grow
 * beyond 50 MB. No external logrotate dependency; keeps runtime offline.
 */
final class LogRotationJob implements JobHandler
{
    private const SIZE_LIMIT_BYTES = 50 * 1024 * 1024;

    public function __construct(private readonly string $storagePath)
    {
    }

    public function handle(JobRun $run, array $payload): void
    {
        $dir = rtrim($this->storagePath, '/') . '/logs';
        if (!is_dir($dir)) {
            $run->resume_marker = 'no_log_dir';
            $run->save();
            return;
        }
        $rotated = 0;
        foreach (glob($dir . '/*.log') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $size = filesize($file);
            if ($size !== false && $size >= self::SIZE_LIMIT_BYTES) {
                $suffix = gmdate('Y-m-d-His');
                $target = $file . '.' . $suffix;
                if (@rename($file, $target)) {
                    $rotated++;
                }
            }
        }
        $run->resume_marker = 'rotated=' . $rotated;
        $run->save();
    }
}
