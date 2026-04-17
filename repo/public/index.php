<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Meridian\Application\AppFactory as MeridianAppFactory;

$app = MeridianAppFactory::create();
$app->run();
