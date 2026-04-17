<?php

declare(strict_types=1);

namespace Meridian\Application;

use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Meridian\Application\Middleware\ErrorResponseMiddleware;
use Meridian\Application\Middleware\MetricsMiddleware;
use Meridian\Application\Middleware\RequestIdMiddleware;
use Meridian\Http\Routes\RouteRegistrar;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Bootstraps the Slim application with DI container, Eloquent capsule, middleware, and routes.
 */
final class AppFactory
{
    public static function create(): App
    {
        self::loadEnv();

        $config = self::loadConfig();
        $container = self::buildContainer($config);

        self::bootEloquent($container);

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        // Slim applies middleware LIFO: last `add` runs outermost. We want
        //   ErrorResponse -> Metrics -> RequestId -> Auth -> RateLimit -> route
        // so that RateLimit sees the resolved user attribute and derives per-user buckets,
        // and Metrics records the full round-trip status code.
        $app->add($container->get(Middleware\RateLimitMiddleware::class));
        $app->add($container->get(Middleware\AuthMiddleware::class));
        $app->add(new RequestIdMiddleware());
        $app->add($container->get(MetricsMiddleware::class));
        $app->add(new ErrorResponseMiddleware(filter_var($config['debug'] ?? false, FILTER_VALIDATE_BOOL)));

        RouteRegistrar::register($app);

        return $app;
    }

    private static function loadEnv(): void
    {
        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }
        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');
    }

    /** @return array<string,mixed> */
    private static function loadConfig(): array
    {
        return require dirname(__DIR__, 2) . '/config/app.php';
    }

    /** @param array<string,mixed> $config */
    private static function buildContainer(array $config): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $builder->addDefinitions([
            'config' => $config,
        ]);
        /** @var Container $c */
        $c = $builder->build();
        return $c;
    }

    private static function bootEloquent(Container $container): void
    {
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
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ]);
        $capsule->setEventDispatcher(new Dispatcher());
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $container->set(Capsule::class, $capsule);
    }
}
