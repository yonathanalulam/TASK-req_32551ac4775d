<?php

declare(strict_types=1);

use Meridian\Domain\Jobs\JobDefinition;
use Meridian\Domain\Jobs\JobRunner;

$state = require __DIR__ . '/_bootstrap.php';
/** @var DI\Container $container */
$container = $state['container'];

/** @var JobRunner $runner */
$runner = $container->get(JobRunner::class);

$defs = JobDefinition::query()->where('is_enabled', true)->get();
foreach ($defs as $def) {
    $runner->enqueue($def->key, [], 'manual-daily-run');
    fwrite(STDOUT, "Enqueued {$def->key}\n");
}

$processed = 0;
for ($i = 0; $i < 20; $i++) {
    $n = $runner->tick(50);
    $processed += $n;
    if ($n === 0) {
        break;
    }
}

fwrite(STDOUT, "Processed {$processed} runs.\n");
