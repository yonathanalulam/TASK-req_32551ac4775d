<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use DI\ContainerBuilder;

$rootDir = dirname(__DIR__);
require $rootDir . '/vendor/autoload.php';
if (file_exists($rootDir . '/.env')) {
    Dotenv::createImmutable($rootDir)->safeLoad();
}
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'mysql',
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
    'database' => $_ENV['DB_DATABASE'] ?? 'meridian',
    'username' => $_ENV['DB_USERNAME'] ?? 'meridian',
    'password' => $_ENV['DB_PASSWORD'] ?? 'meridian_pass',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$capsule->setEventDispatcher(new Dispatcher());
$capsule->setAsGlobal();
$capsule->bootEloquent();

$config = require $rootDir . '/config/app.php';
$builder = new ContainerBuilder();
$builder->useAutowiring(true);
$builder->addDefinitions(require $rootDir . '/config/container.php');
$builder->addDefinitions(['config' => $config]);
/** @var DI\Container $container */
$container = $builder->build();

return ['container' => $container, 'config' => $config, 'root' => $rootDir];
