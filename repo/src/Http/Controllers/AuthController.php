<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use Meridian\Application\Exceptions\AuthenticationException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Auth\AuthService;
use Meridian\Domain\Auth\SessionService;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Domain\Auth\UserSession;
use Meridian\Http\Responses\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly SessionService $sessions,
    ) {
    }

    /**
     * Public self-service signup (fix round-2 A). Creates a learner-role account, persists
     * encrypted security answers, issues a session token, and returns a sanitized user view.
     * Rate-limited by the RateLimitMiddleware's anonymous IP+path bucket.
     */
    public function signup(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $body = (array) ($req->getParsedBody() ?? []);
        $username = isset($body['username']) ? (string) $body['username'] : '';
        $password = isset($body['password']) ? (string) $body['password'] : '';
        $displayName = isset($body['display_name']) && is_string($body['display_name']) ? $body['display_name'] : null;
        $answers = isset($body['security_answers']) && is_array($body['security_answers'])
            ? $body['security_answers']
            : [];
        if ($username === '' || $password === '' || $answers === []) {
            throw new ValidationException(
                'username, password, and security_answers are required.',
                ['fields' => ['username', 'password', 'security_answers']],
            );
        }
        $normalized = [];
        foreach ($answers as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $normalized[] = [
                'question_id' => (int) ($pair['question_id'] ?? 0),
                'answer' => (string) ($pair['answer'] ?? ''),
            ];
        }
        $sp = $req->getServerParams();
        $out = $this->auth->signup(
            $username,
            $password,
            $normalized,
            $displayName,
            $sp['REMOTE_ADDR'] ?? null,
            $req->getHeaderLine('User-Agent') ?: null,
        );
        return ApiResponse::success($res, [
            'token' => $out['token'],
            'user' => [
                'id' => (int) $out['user']->id,
                'username' => $out['user']->username,
                'display_name' => $out['user']->display_name,
                'status' => $out['user']->status,
                'role' => 'learner',
            ],
        ], 201);
    }

    public function login(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $body = (array) ($req->getParsedBody() ?? []);
        $username = isset($body['username']) ? (string) $body['username'] : '';
        $password = isset($body['password']) ? (string) $body['password'] : '';
        if ($username === '' || $password === '') {
            throw new ValidationException('username and password are required.', ['fields' => ['username', 'password']]);
        }
        $sp = $req->getServerParams();
        $ip = $sp['REMOTE_ADDR'] ?? null;
        $ua = $req->getHeaderLine('User-Agent') ?: null;
        $out = $this->auth->login($username, $password, $ip, $ua);

        return ApiResponse::success($res, [
            'token' => $out['token'],
            'user' => [
                'id' => (int) $out['user']->id,
                'username' => $out['user']->username,
                'display_name' => $out['user']->display_name,
                'status' => $out['user']->status,
            ],
        ], 200);
    }

    public function logout(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $session = $req->getAttribute('session');
        if (!$session instanceof UserSession) {
            throw new AuthenticationException('Missing session.');
        }
        $this->auth->logout($session);
        return ApiResponse::success($res, ['revoked' => true], 200);
    }

    public function beginReset(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $body = (array) ($req->getParsedBody() ?? []);
        $username = isset($body['username']) ? (string) $body['username'] : '';
        if ($username === '') {
            throw new ValidationException('username is required.', ['field' => 'username']);
        }
        $sp = $req->getServerParams();
        $ip = $sp['REMOTE_ADDR'] ?? null;
        $out = $this->auth->beginPasswordReset($username, $ip);
        return ApiResponse::success($res, $out, 200);
    }

    public function completeReset(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $body = (array) ($req->getParsedBody() ?? []);
        $username = isset($body['username']) ? (string) $body['username'] : '';
        $ticket = isset($body['reset_ticket']) ? (string) $body['reset_ticket'] : '';
        $answers = isset($body['answers']) && is_array($body['answers']) ? $body['answers'] : [];
        $new = isset($body['new_password']) ? (string) $body['new_password'] : '';
        if ($username === '' || $ticket === '' || $new === '' || $answers === []) {
            throw new ValidationException(
                'username, reset_ticket, answers, and new_password are required.',
                ['fields' => ['username', 'reset_ticket', 'answers', 'new_password']],
            );
        }
        $normalized = [];
        foreach ($answers as $pair) {
            if (!is_array($pair) || !isset($pair['question_id'], $pair['answer'])) {
                continue;
            }
            $normalized[] = ['question_id' => (int) $pair['question_id'], 'answer' => (string) $pair['answer']];
        }
        $this->auth->completePasswordReset($username, $ticket, $normalized, $new);
        return ApiResponse::success($res, ['reset' => true], 200);
    }

    /**
     * Public catalogue of active security questions. Exposed without authentication so a
     * new signup client can render the correct prompts before it has a session token.
     * Returns only the prompt + id; no per-user answer material.
     */
    public function publicSecurityQuestions(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $rows = \Meridian\Domain\Auth\SecurityQuestion::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->all();
        $out = array_map(static fn($q) => ['id' => (int) $q->id, 'prompt' => $q->prompt], $rows);
        return ApiResponse::success($res, $out);
    }

    public function me(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $user = $req->getAttribute('user');
        if (!$user instanceof User) {
            throw new AuthenticationException();
        }
        $perms = array_keys(array_filter(UserPermissions::effective($user)));
        return ApiResponse::success($res, [
            'id' => (int) $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'status' => $user->status,
            'permissions' => $perms,
        ]);
    }
}
