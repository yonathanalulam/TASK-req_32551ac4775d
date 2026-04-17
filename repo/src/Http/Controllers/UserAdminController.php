<?php

declare(strict_types=1);

namespace Meridian\Http\Controllers;

use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\NotFoundException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Auth\AuthService;
use Meridian\Domain\Auth\PasswordHasher;
use Meridian\Domain\Auth\SecurityQuestion;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Domain\Auth\UserRoleBinding;
use Meridian\Domain\Auth\UserSecurityAnswer;
use Meridian\Http\Responses\ApiResponse;
use Meridian\Infrastructure\Crypto\Cipher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class UserAdminController
{
    private const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._\-]{2,63}$/i';

    public function __construct(
        private readonly PasswordHasher $hasher,
        private readonly AuthService $auth,
        private readonly Cipher $cipher,
        private readonly AuditLogger $audit,
    ) {
    }

    public function create(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $actor = $this->requirePerm($req, 'auth.manage_users');
        $body = (array) ($req->getParsedBody() ?? []);
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $displayName = isset($body['display_name']) ? (string) $body['display_name'] : null;
        $email = isset($body['email']) ? (string) $body['email'] : null;
        $status = (string) ($body['status'] ?? 'active');
        $isSystem = (bool) ($body['is_system'] ?? false);

        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            throw new ValidationException('Invalid username format.', ['field' => 'username', 'rule' => 'pattern']);
        }
        $allowedStatuses = ['pending_activation', 'active', 'disabled', 'password_reset_required'];
        if (!in_array($status, $allowedStatuses, true)) {
            throw new ValidationException('Invalid status.', ['field' => 'status']);
        }

        if (User::query()->where('username', $username)->exists()) {
            throw new ValidationException('Username already exists.', ['field' => 'username', 'rule' => 'unique']);
        }

        $hash = $this->hasher->hash($password);
        $emailCt = $email !== null && $email !== '' ? $this->cipher->encrypt($email) : null;

        /** @var User $user */
        $user = User::query()->create([
            'username' => $username,
            'password_hash' => $hash,
            'display_name' => $displayName,
            'email_ciphertext' => $emailCt,
            'status' => $status,
            'is_system' => $isSystem,
        ]);

        $this->audit->record('auth.user_created', 'user', (string) $user->id, [
            'username' => $username,
            'status' => $status,
            'is_system' => $isSystem,
        ], actorType: 'user', actorId: (string) $actor->id);

        return ApiResponse::success($res, $this->formatUser($user), 201);
    }

    public function list(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $this->requirePerm($req, 'auth.manage_users');
        $q = (array) $req->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = min(100, max(1, (int) ($q['page_size'] ?? 25)));
        $query = User::query()->orderBy('id');
        if (!empty($q['status'])) {
            $query->where('status', (string) $q['status']);
        }
        if (!empty($q['q'])) {
            $query->where('username', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $q['q']) . '%');
        }
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $size)->get()->all();
        return ApiResponse::success($res, array_map(fn(User $u) => $this->formatUser($u), $rows), 200, [
            'page' => $page, 'page_size' => $size, 'total' => $total,
        ]);
    }

    public function get(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $this->requirePerm($req, 'auth.manage_users');
        $user = $this->findUserOr404((int) $args['id']);
        return ApiResponse::success($res, $this->formatUser($user));
    }

    public function update(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->requirePerm($req, 'auth.manage_users');
        $user = $this->findUserOr404((int) $args['id']);
        $body = (array) ($req->getParsedBody() ?? []);

        if (array_key_exists('display_name', $body)) {
            $user->display_name = $body['display_name'] !== null ? (string) $body['display_name'] : null;
        }
        if (array_key_exists('status', $body)) {
            $new = (string) $body['status'];
            $this->validateStatusTransition($user->status, $new);
            $user->status = $new;
        }
        if (array_key_exists('email', $body)) {
            $user->email_ciphertext = $body['email'] !== null && $body['email'] !== '' ? $this->cipher->encrypt((string) $body['email']) : null;
        }
        $user->save();
        $this->audit->record('auth.user_updated', 'user', (string) $user->id, [
            'fields' => array_keys($body),
        ], actorType: 'user', actorId: (string) $actor->id);
        return ApiResponse::success($res, $this->formatUser($user));
    }

    public function assignRole(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $user = $this->findUserOr404((int) $args['id']);
        $body = (array) ($req->getParsedBody() ?? []);
        $roleKey = (string) ($body['role'] ?? '');
        $scopeType = isset($body['scope_type']) ? (string) $body['scope_type'] : null;
        $scopeRef = isset($body['scope_ref']) ? (string) $body['scope_ref'] : null;
        if ($roleKey === '') {
            throw new ValidationException('role is required.', ['field' => 'role']);
        }
        $binding = $this->auth->assignRole($actor, $user, $roleKey, $scopeType, $scopeRef);
        UserPermissions::clearCacheForUser((int) $user->id);
        return ApiResponse::success($res, [
            'id' => (int) $binding->id,
            'role' => $roleKey,
            'scope_type' => $scopeType,
            'scope_ref' => $scopeRef,
        ], 201);
    }

    public function removeRole(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->requirePerm($req, 'auth.manage_roles');
        $user = $this->findUserOr404((int) $args['id']);
        /** @var UserRoleBinding|null $binding */
        $binding = UserRoleBinding::query()
            ->where('id', (int) $args['bindingId'])
            ->where('user_id', (int) $user->id)
            ->first();
        if (!$binding instanceof UserRoleBinding) {
            throw new NotFoundException('Binding not found.');
        }
        $binding->delete();
        UserPermissions::clearCacheForUser((int) $user->id);
        $this->audit->record('auth.role_revoked', 'user', (string) $user->id, [
            'binding_id' => (int) $args['bindingId'],
        ], actorType: 'user', actorId: (string) $actor->id);
        return ApiResponse::success($res, ['removed' => true]);
    }

    public function adminReset(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->requirePerm($req, 'auth.reset_other_password');
        $user = $this->findUserOr404((int) $args['id']);
        $body = (array) ($req->getParsedBody() ?? []);
        $new = (string) ($body['new_password'] ?? '');
        if ($new === '') {
            throw new ValidationException('new_password is required.', ['field' => 'new_password']);
        }
        $this->auth->adminResetPassword($actor, $user, $new);
        return ApiResponse::success($res, ['reset' => true]);
    }

    public function setSecurityAnswers(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        $actor = $this->currentUser($req);
        $user = $this->findUserOr404((int) $args['id']);
        if ((int) $actor->id !== (int) $user->id && !UserPermissions::hasPermission($actor, 'auth.manage_users')) {
            throw new AuthorizationException('Cannot set security answers for another user.');
        }
        $body = (array) ($req->getParsedBody() ?? []);
        $answers = (array) ($body['answers'] ?? []);
        if (count($answers) < 2) {
            throw new ValidationException('At least two security answers are required.', ['field' => 'answers']);
        }
        $normalizedAnswers = [];
        foreach ($answers as $a) {
            if (!is_array($a) || !isset($a['question_id'], $a['answer'])) {
                throw new ValidationException('Each answer requires question_id and answer.');
            }
            $qid = (int) $a['question_id'];
            $text = (string) $a['answer'];
            $q = SecurityQuestion::query()->where('id', $qid)->where('is_active', true)->first();
            if (!$q instanceof SecurityQuestion) {
                throw new ValidationException('Unknown or inactive question_id ' . $qid);
            }
            $normalizedAnswers[$qid] = $this->auth->normalizeAnswer($text);
        }

        UserSecurityAnswer::query()->where('user_id', (int) $user->id)->delete();
        foreach ($normalizedAnswers as $qid => $normText) {
            UserSecurityAnswer::query()->create([
                'user_id' => (int) $user->id,
                'security_question_id' => (int) $qid,
                'answer_ciphertext' => $this->cipher->encrypt($normText),
                'key_version' => $this->cipher->currentKeyVersion(),
            ]);
        }
        $this->audit->record('auth.security_answers_set', 'user', (string) $user->id, [
            'count' => count($normalizedAnswers),
        ], actorType: 'user', actorId: (string) $actor->id);
        return ApiResponse::success($res, ['count' => count($normalizedAnswers)], 201);
    }

    public function listSecurityQuestions(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        $this->currentUser($req);
        $rows = SecurityQuestion::query()->where('is_active', true)->orderBy('id')->get()->all();
        return ApiResponse::success($res, array_map(fn(SecurityQuestion $q) => [
            'id' => (int) $q->id,
            'prompt' => $q->prompt,
        ], $rows));
    }

    private function formatUser(User $u): array
    {
        return [
            'id' => (int) $u->id,
            'username' => $u->username,
            'display_name' => $u->display_name,
            'status' => $u->status,
            'is_system' => (bool) $u->is_system,
            'last_login_at' => $u->last_login_at?->format(DATE_ATOM),
            'locked_until' => $u->locked_until?->format(DATE_ATOM),
        ];
    }

    private function validateStatusTransition(string $from, string $to): void
    {
        $allowed = [
            'pending_activation' => ['active', 'disabled'],
            'active' => ['locked', 'disabled', 'password_reset_required'],
            'locked' => ['active', 'disabled'],
            'password_reset_required' => ['active', 'disabled'],
            'disabled' => ['active'],
        ];
        if (!isset($allowed[$from]) || !in_array($to, $allowed[$from], true)) {
            throw new ValidationException("Illegal status transition from {$from} to {$to}.", [
                'field' => 'status',
                'rule' => 'illegal_transition',
            ]);
        }
    }

    private function requirePerm(ServerRequestInterface $req, string $perm): User
    {
        $u = $this->currentUser($req);
        if (!UserPermissions::hasPermission($u, $perm)) {
            throw new AuthorizationException('Missing permission: ' . $perm);
        }
        return $u;
    }

    private function currentUser(ServerRequestInterface $req): User
    {
        $u = $req->getAttribute('user');
        if (!$u instanceof User) {
            throw new AuthorizationException('Authentication required.');
        }
        return $u;
    }

    private function findUserOr404(int $id): User
    {
        $u = User::query()->find($id);
        if (!$u instanceof User) {
            throw new NotFoundException('User not found.');
        }
        return $u;
    }
}
