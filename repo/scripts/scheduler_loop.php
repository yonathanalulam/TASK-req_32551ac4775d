<?php

declare(strict_types=1);

/**
 * Long-running, cooperative scheduler loop.
 *
 * Every 30 seconds:
 *   1. evaluates cron expressions in job_definitions.schedule_cron (when one does not already have
 *      a queued/running row for the current bucket)
 *   2. ticks the runner up to a cap
 *
 * Designed for the scheduler container in docker-compose.
 */

use Meridian\Domain\Jobs\JobDefinition;
use Meridian\Domain\Jobs\JobRun;
use Meridian\Domain\Jobs\JobRunner;
use Meridian\Infrastructure\Clock\Clock;
use Meridian\Infrastructure\Cron\CronExpression;

$state = require __DIR__ . '/_bootstrap.php';
/** @var DI\Container $container */
$container = $state['container'];

/** @var JobRunner $runner */
$runner = $container->get(JobRunner::class);
/** @var Clock $clock */
$clock = $container->get(Clock::class);
$parser = new CronExpression();

fwrite(STDOUT, "Meridian scheduler started.\n");

$lastMinuteKey = '';
while (true) {
    try {
        $now = $clock->nowUtc();
        $minuteKey = $now->format('Y-m-d H:i');
        if ($minuteKey !== $lastMinuteKey) {
            $defs = JobDefinition::query()->where('is_enabled', true)->whereNotNull('schedule_cron')->get();
            foreach ($defs as $def) {
                if (!$parser->isDue((string) $def->schedule_cron, $now)) {
                    continue;
                }
                $existing = JobRun::query()
                    ->where('job_key', (string) $def->key)
                    ->whereIn('status', ['queued', 'running', 'retry_wait'])
                    ->exists();
                if (!$existing) {
                    $runner->enqueue((string) $def->key, [], 'scheduler');
                }
            }
            $lastMinuteKey = $minuteKey;
        }
        $runner->tick(20);
    } catch (Throwable $e) {
        fwrite(STDERR, "Scheduler tick error: " . $e->getMessage() . "\n");
    }
    sleep(30);
}
