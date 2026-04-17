<?php

declare(strict_types=1);

namespace Meridian\Application\Middleware;

use Meridian\Application\Exceptions\ApiException;
use Meridian\Application\Exceptions\AuthenticationException;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Auth\SessionService;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Blacklist\BlacklistService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Central auth + blacklist gate.
 *
 * Resolves the current session from an "Authorization: Bearer <token>" header and attaches
 * the `user`/`session` request attributes for downstream handlers. Public endpoints
 * (`/auth/signup`, `/auth/login`, password reset, health, etc.) are listed in
 * `PUBLIC_PATHS` and skip auth entirely.
 *
 * Round-3 Fix E — centralized user blacklist enforcement:
 *   After the session + user are resolved, this middleware queries BlacklistService for
 *   an active `user` entry keyed on `user.id`. A match is denied with 403
 *   `USER_BLACKLISTED` and emits a `auth.blacklist_denied` audit entry. This guarantees
 *   blacklisted users cannot perform ANY authenticated business action — the block is
 *   centralized here rather than scattered across individual services.
 *
 * The logout endpoint is deliberately NOT exempted: blacklisted users cannot call
 * /auth/logout directly, but their session rows are reaped by the expired-session job and
 * can be revoked wholesale by an administrator through the admin surface.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private const PUBLIC_PATHS = [
        '/api/v1/auth/signup',
        '/api/v1/auth/security-questions',
        '/api/v1/auth/login',
        '/api/v1/auth/password-reset/begin',
        '/api/v1/auth/password-reset/complete',
        '/api/v1/health',
    ];

    public function __construct(
        private readonly SessionService $sessions,
        private readonly BlacklistService $blacklists,
        private readonly AuditLogger $audit,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (in_array($path, self::PUBLIC_PATHS, true)) {
            return $handler->handle($request);
        }
        $authz = $request->getHeaderLine('Authorization');
        if (!str_starts_with(strtolower($authz), 'bearer ')) {
            throw new AuthenticationException('Missing bearer token.');
        }
        $token = trim(substr($authz, 7));
        $session = $this->sessions->resolve($token);
        if ($session === null) {
            throw new AuthenticationException('Invalid or expired session.');
        }
        /** @var User|null $user */
        $user = User::query()->find($session->user_id);
        if (!$user instanceof User || $user->status === 'disabled') {
            throw new AuthenticationException('Account unavailable.');
        }
        if ($this->blacklists->isBlacklisted('user', (string) $user->id)) {
            // Record once per request so forensic audit can trace the blacklisted actor
            // without relying on downstream services to re-check.
            $this->audit->record(
                'auth.blacklist_denied',
                'user',
                (string) $user->id,
                [
                    'path' => $path,
                    'method' => $request->getMethod(),
                ],
                actorType: 'user',
                actorId: (string) $user->id,
                requestId: $request->getHeaderLine('X-Request-Id') ?: null,
            );
            throw new ApiException(
                'USER_BLACKLISTED',
                'User is blacklisted.',
                403,
                ['user_id' => (int) $user->id],
            );
        }
        $request = $request
            ->withAttribute('user', $user)
            ->withAttribute('session', $session);
        return $handler->handle($request);
    }
}
