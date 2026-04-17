<?php

declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
}

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

$env = static fn(string $k, ?string $default = null): ?string => $_ENV[$k] ?? getenv($k) ?: $default;

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds' => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => $env('APP_ENV', 'production'),
        'production' => [
            'adapter' => 'mysql',
            'host' => $env('DB_HOST', '127.0.0.1'),
            'name' => $env('DB_DATABASE', 'meridian'),
            'user' => $env('DB_USERNAME', 'meridian'),
            'pass' => $env('DB_PASSWORD', 'meridian_pass'),
            'port' => (int) $env('DB_PORT', '3306'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
        'testing' => [
            'adapter' => 'mysql',
            'host' => $env('TEST_DB_HOST', $env('DB_HOST', '127.0.0.1')),
            'name' => $env('TEST_DB_DATABASE', 'meridian_test'),
            'user' => $env('DB_USERNAME', 'meridian'),
            'pass' => $env('DB_PASSWORD', 'meridian_pass'),
            'port' => (int) $env('DB_PORT', '3306'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation',
];
