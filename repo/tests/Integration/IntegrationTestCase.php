<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration;

use DI\Container;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Meridian\Application\Middleware\AuthMiddleware;
use Meridian\Application\Middleware\ErrorResponseMiddleware;
use Meridian\Application\Middleware\MetricsMiddleware;
use Meridian\Application\Middleware\RateLimitMiddleware;
use Meridian\Application\Middleware\RequestIdMiddleware;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Auth\AuthService;
use Meridian\Domain\Auth\PasswordHasher;
use Meridian\Domain\Auth\Role;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserRoleBinding;
use Meridian\Http\Routes\RouteRegistrar;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Lightweight integration base class.
 *
 * Spins up:
 *   - a SQLite in-memory DB
 *   - the full Meridian schema (SchemaBuilder)
 *   - the DI container + middleware stack identical to production (order-preserving)
 *   - the Slim app with real RouteRegistrar routes
 *
 * Tests assert against real HTTP round-trips so authorization/policy/validation behavior
 * is exercised end-to-end without network dependencies.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Container $container;
    protected \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDatabase();
        $this->container = $this->buildContainer();
        $this->app = $this->buildApp();
        SchemaBuilder::build();
        SeedFixtures::run($this->container->get(PasswordHasher::class));
    }

    protected function tearDown(): void
    {
        Capsule::connection()->disconnect();
        parent::tearDown();
    }

    private function bootDatabase(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $capsule->setEventDispatcher(new Dispatcher());
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    private function buildContainer(): Container
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        // Force deterministic crypto key in tests.
        $config['crypto']['master_key_hex'] = str_repeat('a', 64);
        $config['crypto']['master_key_version'] = 1;
        // Larger rate limit so tests don't trip over the default.
        $config['rate_limit']['default_per_minute'] = 10000;
        // Isolated per-test metrics root so parallel/repeated runs never collide and
        // the repository's storage/metrics directory stays clean.
        $config['metrics_root'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meridian-metrics-' . bin2hex(random_bytes(4));
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $builder->addDefinitions(['config' => $config]);
        return $builder->build();
    }

    private function buildApp(): \Slim\App
    {
        SlimAppFactory::setContainer($this->container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $app->add($this->container->get(RateLimitMiddleware::class));
        $app->add($this->container->get(AuthMiddleware::class));
        $app->add(new RequestIdMiddleware());
        $app->add($this->container->get(MetricsMiddleware::class));
        $app->add(new ErrorResponseMiddleware(true));

        RouteRegistrar::register($app);
        return $app;
    }

    /** @param array<string,mixed>|null $body */
    protected function request(string $method, string $path, ?array $body = null, array $headers = []): ResponseInterface
    {
        $requestFactory = new ServerRequestFactory();
        $streamFactory = new StreamFactory();
        $request = $requestFactory->createServerRequest($method, $path);
        foreach ($headers as $k => $v) {
            $request = $request->withHeader($k, $v);
        }
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($streamFactory->createStream(json_encode($body) ?: ''));
            $request = $request->withParsedBody($body);
        }
        return $this->app->handle($request);
    }

    protected function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A deterministic English paragraph rich in stop-words so the offline LanguageDetector
     * clears its 0.75 confidence threshold without needing `content.language_override`.
     */
    protected function englishBody(int $repeats = 15): string
    {
        return str_repeat(
            'The quick brown fox jumps over the lazy dog and watches the bright sun rise above the river. '
            . 'This is a simple article about the fox, the dog, and all of the animals in the forest. ',
            max(1, $repeats),
        );
    }

    protected function createUser(string $username, string $roleKey, string $password = 'Pass1234!LongEnough'): User
    {
        /** @var PasswordHasher $hasher */
        $hasher = $this->container->get(PasswordHasher::class);
        /** @var User $u */
        $u = User::query()->create([
            'username' => $username,
            'password_hash' => $hasher->hash($password),
            'display_name' => ucfirst($username),
            'status' => 'active',
            'is_system' => false,
        ]);
        if ($roleKey !== '') {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            UserRoleBinding::query()->create([
                'user_id' => (int) $u->id,
                'role_id' => (int) $role->id,
                'scope_type' => null,
                'scope_ref' => null,
            ]);
            \Meridian\Domain\Auth\UserPermissions::clearCacheForUser((int) $u->id);
        }
        return $u;
    }

    protected function addScopedRole(User $user, string $roleKey, string $scopeType, string $scopeRef): void
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        UserRoleBinding::query()->create([
            'user_id' => (int) $user->id,
            'role_id' => (int) $role->id,
            'scope_type' => $scopeType,
            'scope_ref' => $scopeRef,
        ]);
        \Meridian\Domain\Auth\UserPermissions::clearCacheForUser((int) $user->id);
    }

    protected function login(string $username, string $password = 'Pass1234!LongEnough'): string
    {
        $response = $this->request('POST', '/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        self::assertSame(200, $response->getStatusCode(), 'Login failed: ' . (string) $response->getBody());
        $payload = $this->decode($response);
        return (string) $payload['data']['token'];
    }

    /** @return array<string,string> */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    protected function metricsRoot(): string
    {
        return (string) $this->container->get('config')['metrics_root'];
    }
}
