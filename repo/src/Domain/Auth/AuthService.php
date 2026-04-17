<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use DateTimeImmutable;
use Meridian\Application\Exceptions\AuthenticationException;
use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\ConflictException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Infrastructure\Clock\Clock;
use Meridian\Infrastructure\Crypto\Cipher;
use Ramsey\Uuid\Uuid;

/**
 * Login, logout, password reset via security questions, and account lifecycle transitions.
 */
final class AuthService
{
    private const RESET_TICKET_TTL_SECONDS = 900; // 15 minutes

    public function __construct(
        private readonly Clock $clock,
        private readonly PasswordHasher $hasher,
        private readonly SessionService $sessions,
        private readonly LockoutPolicy $policy,
        private readonly Cipher $cipher,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Username allowlist mirrors UserAdminController::USERNAME_PATTERN for consistency. */
    private const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._\-]{2,63}$/i';

    /** Minimum number of security answers required for future self-service password reset. */
    private const MIN_SIGNUP_SECURITY_ANSWERS = 2;

    /**
     * Self-service signup for local username/password accounts.
     *
     * Creates a `learner`-role account (safest baseline per PRD section 6 role matrix) in the
     * `active` state, stores encrypted answers for at least MIN_SIGNUP_SECURITY_ANSWERS
     * distinct security questions so the caller can later run password reset, and records
     * `auth.signup_completed` to the audit trail.
     *
     * Sensitive material (password hash, security answers) is never returned.
     *
     * @param array<int,array{question_id:int,answer:string}> $answers
     * @return array{token:string,user:User}
     */
    public function signup(
        string $username,
        string $password,
        array $answers,
        ?string $displayName = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        $username = trim($username);
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            throw new ValidationException(
                'Invalid username format.',
                ['field' => 'username', 'rule' => 'pattern'],
            );
        }
        if (User::query()->where('username', $username)->exists()) {
            throw new ConflictException('Username already exists.', 'USERNAME_TAKEN', ['field' => 'username']);
        }
        if (count($answers) < self::MIN_SIGNUP_SECURITY_ANSWERS) {
            throw new ValidationException(
                'At least ' . self::MIN_SIGNUP_SECURITY_ANSWERS . ' distinct security answers are required.',
                ['field' => 'security_answers'],
            );
        }
        // Validate every referenced question exists & is active BEFORE hashing so we don't
        // burn bcrypt cycles on a request that would have failed validation anyway.
        $seenQuestionIds = [];
        $normalizedAnswers = [];
        foreach ($answers as $pair) {
            if (!is_array($pair) || !isset($pair['question_id'], $pair['answer'])) {
                throw new ValidationException(
                    'Each security answer must include question_id and answer.',
                    ['field' => 'security_answers'],
                );
            }
            $qid = (int) $pair['question_id'];
            $text = trim((string) $pair['answer']);
            if ($qid <= 0 || $text === '') {
                throw new ValidationException('Invalid security answer entry.', ['field' => 'security_answers']);
            }
            if (isset($seenQuestionIds[$qid])) {
                throw new ValidationException('Duplicate question_id in security_answers.', ['field' => 'security_answers']);
            }
            $seenQuestionIds[$qid] = true;
            $q = SecurityQuestion::query()->where('id', $qid)->where('is_active', true)->first();
            if (!$q instanceof SecurityQuestion) {
                throw new ValidationException(
                    'Unknown or inactive security question_id ' . $qid,
                    ['field' => 'security_answers'],
                );
            }
            $normalizedAnswers[$qid] = $this->normalizeAnswer($text);
        }

        $passwordHash = $this->hasher->hash($password);
        $learnerRole = Role::query()->where('key', 'learner')->first();
        if (!$learnerRole instanceof Role) {
            throw new \RuntimeException('Learner role missing; rerun role seeder before allowing signups.');
        }

        /** @var User $user */
        $user = User::query()->create([
            'username' => $username,
            'password_hash' => $passwordHash,
            'display_name' => $displayName !== null && $displayName !== '' ? mb_substr($displayName, 0, 128) : null,
            'status' => 'active',
            'is_system' => false,
        ]);
        UserRoleBinding::query()->create([
            'user_id' => (int) $user->id,
            'role_id' => (int) $learnerRole->id,
            'scope_type' => null,
            'scope_ref' => null,
        ]);
        foreach ($normalizedAnswers as $qid => $normText) {
            UserSecurityAnswer::query()->create([
                'user_id' => (int) $user->id,
                'security_question_id' => (int) $qid,
                'answer_ciphertext' => $this->cipher->encrypt($normText),
                'key_version' => $this->cipher->currentKeyVersion(),
            ]);
        }
        UserPermissions::clearCacheForUser((int) $user->id);

        $now = $this->clock->nowUtc();
        $user->last_login_at = $now->format('Y-m-d H:i:s');
        $user->save();

        $issue = $this->sessions->issue($user, $ip, $userAgent);

        $this->audit->record(
            'auth.signup_completed',
            'user',
            (string) $user->id,
            [
                'username' => $username,
                'role' => 'learner',
                'security_answer_count' => count($normalizedAnswers),
            ],
            actorType: 'user',
            actorId: (string) $user->id,
        );

        return ['token' => $issue['token'], 'user' => $user];
    }

    /** @return array{token:string,user:User} */
    public function login(string $username, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        $username = trim($username);
        $now = $this->clock->nowUtc();

        /** @var User|null $user */
        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            $this->recordLoginAttempt($username, null, false, 'unknown_user', $now);
            throw new AuthenticationException('Invalid credentials.');
        }

        if ($user->status === 'disabled') {
            $this->recordLoginAttempt($username, (int) $user->id, false, 'disabled', $now);
            throw new AuthenticationException('Account is disabled.');
        }

        if ($user->locked_until !== null) {
            $locked = $user->locked_until instanceof DateTimeImmutable
                ? $user->locked_until
                : new DateTimeImmutable((string) $user->locked_until);
            if ($locked > $now) {
                $this->recordLoginAttempt($username, (int) $user->id, false, 'locked', $now);
                throw new AuthenticationException('Account is locked. Try again later.');
            }
        }

        if (!$this->hasher->verify($password, $user->password_hash)) {
            $this->recordLoginAttempt($username, (int) $user->id, false, 'bad_password', $now);
            $this->maybeApplyLoginLockout($user, $now);
            throw new AuthenticationException('Invalid credentials.');
        }

        if ($user->status === 'password_reset_required') {
            $this->recordLoginAttempt($username, (int) $user->id, false, 'reset_required', $now);
            throw new AuthenticationException('Password reset required before sign-in.');
        }

        if ($user->status === 'locked') {
            $user->status = 'active';
            $user->locked_until = null;
        }
        $user->last_login_at = $now->format('Y-m-d H:i:s');
        $user->save();

        if ($this->hasher->needsRehash($user->password_hash)) {
            $user->password_hash = $this->hasher->hash($password);
            $user->save();
        }

        $this->recordLoginAttempt($username, (int) $user->id, true, null, $now);

        $issue = $this->sessions->issue($user, $ip, $userAgent);

        $this->audit->record('auth.login_succeeded', 'user', (string) $user->id, [
            'username' => $username,
            'ip_present' => $ip !== null,
        ], actorType: 'user', actorId: (string) $user->id);

        return ['token' => $issue['token'], 'user' => $user];
    }

    public function logout(UserSession $session): void
    {
        $this->sessions->revoke($session, 'logout');
        $this->audit->record('auth.logout', 'session', $session->id, [], actorType: 'user', actorId: (string) $session->user_id);
    }

    /**
     * Initiates a password reset using security questions. Returns the question prompts
     * the user must answer AND a one-time reset ticket bound to the user. The raw ticket
     * value is returned to the caller exactly once; only its SHA-256 hash is persisted.
     *
     * The ticket must be presented together with correct security answers at complete time.
     *
     * @return array{reset_ticket:string,expires_at:string,questions:array<int,array{id:int,prompt:string}>}
     */
    public function beginPasswordReset(string $username, ?string $ipAddress = null): array
    {
        $user = User::query()->where('username', trim($username))->first();
        if (!$user instanceof User) {
            throw new AuthenticationException('Reset not available.');
        }
        $now = $this->clock->nowUtc();
        if ($user->reset_locked_until !== null) {
            $lock = $user->reset_locked_until instanceof DateTimeImmutable
                ? $user->reset_locked_until
                : new DateTimeImmutable((string) $user->reset_locked_until);
            if ($lock > $now) {
                throw new AuthenticationException('Reset is temporarily locked.');
            }
        }
        /** @var array<int,UserSecurityAnswer> $answers */
        $answers = UserSecurityAnswer::query()
            ->with('question')
            ->where('user_id', (int) $user->id)
            ->get()
            ->all();
        if (count($answers) < 2) {
            throw new ValidationException('No security questions configured.', ['user_id' => (int) $user->id]);
        }

        // Revoke any outstanding unconsumed tickets for this user so only one active ticket exists.
        PasswordResetTicket::query()
            ->where('user_id', (int) $user->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now->format('Y-m-d H:i:s'),
                'consume_reason' => 'superseded',
            ]);

        $rawTicket = bin2hex(random_bytes(32));
        $ticketId = Uuid::uuid4()->toString();
        $expiresAt = $now->modify('+' . self::RESET_TICKET_TTL_SECONDS . ' seconds');
        PasswordResetTicket::query()->create([
            'id' => $ticketId,
            'user_id' => (int) $user->id,
            'ticket_hash' => hash('sha256', $rawTicket),
            'issued_at' => $now->format('Y-m-d H:i:s'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'ip_address_ciphertext' => $ipAddress !== null && $ipAddress !== '' ? $this->cipher->encrypt($ipAddress) : null,
        ]);

        $questions = [];
        foreach ($answers as $ans) {
            /** @var SecurityQuestion $q */
            $q = $ans->question;
            $questions[] = ['id' => (int) $q->id, 'prompt' => $q->prompt];
        }

        $this->audit->record('auth.reset_begun', 'user', (string) $user->id, [
            'username' => $user->username,
            'ticket_id' => $ticketId,
        ], actorType: 'user', actorId: (string) $user->id);

        return [
            'reset_ticket' => $ticketId . '.' . $rawTicket,
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'questions' => $questions,
        ];
    }

    /**
     * Completes reset by verifying ALL provided question/answer pairs AND a valid reset ticket
     * previously issued to the same user. The ticket is consumed on success; replay,
     * expiration, and tampered tickets are all rejected as authentication failures.
     *
     * @param array<int,array{question_id:int,answer:string}> $answers
     */
    public function completePasswordReset(string $username, string $ticket, array $answers, string $newPassword): void
    {
        $user = User::query()->where('username', trim($username))->first();
        if (!$user instanceof User) {
            throw new AuthenticationException('Reset not available.');
        }
        $now = $this->clock->nowUtc();
        if ($user->reset_locked_until !== null) {
            $lock = $user->reset_locked_until instanceof DateTimeImmutable ? $user->reset_locked_until : new DateTimeImmutable((string) $user->reset_locked_until);
            if ($lock > $now) {
                throw new AuthenticationException('Reset is temporarily locked.');
            }
        }
        if (count($answers) < 2) {
            throw new ValidationException('At least two security answers are required.', ['field' => 'answers']);
        }

        $ticketRecord = $this->resolveResetTicket((int) $user->id, $ticket, $now);

        /** @var array<int,UserSecurityAnswer> $stored */
        $stored = UserSecurityAnswer::query()->where('user_id', (int) $user->id)->get()->all();
        $byQid = [];
        foreach ($stored as $row) {
            $byQid[(int) $row->security_question_id] = $row;
        }
        foreach ($answers as $pair) {
            $qid = (int) ($pair['question_id'] ?? 0);
            $plain = (string) ($pair['answer'] ?? '');
            if (!isset($byQid[$qid])) {
                $this->recordResetAttempt((int) $user->id, false, 'unknown_question', $now);
                $this->maybeApplyResetLockout($user, $now);
                throw new AuthenticationException('Reset verification failed.');
            }
            $decrypted = $this->cipher->decrypt($byQid[$qid]->answer_ciphertext);
            $a = $this->normalizeAnswer($plain);
            $b = $this->normalizeAnswer($decrypted);
            if (!hash_equals($b, $a)) {
                $this->recordResetAttempt((int) $user->id, false, 'bad_answer', $now);
                $this->maybeApplyResetLockout($user, $now);
                throw new AuthenticationException('Reset verification failed.');
            }
        }

        $user->password_hash = $this->hasher->hash($newPassword);
        $user->status = 'active';
        $user->reset_locked_until = null;
        $user->locked_until = null;
        $user->save();

        // Consume the ticket (one-time use) and revoke any other outstanding tickets for this user.
        $ticketRecord->consumed_at = $now->format('Y-m-d H:i:s');
        $ticketRecord->consume_reason = 'completed';
        $ticketRecord->save();
        PasswordResetTicket::query()
            ->where('user_id', (int) $user->id)
            ->where('id', '!=', $ticketRecord->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now->format('Y-m-d H:i:s'), 'consume_reason' => 'obsoleted_by_completion']);

        $this->sessions->revokeAllForUser((int) $user->id, 'password_reset');
        $this->recordResetAttempt((int) $user->id, true, null, $now);

        $this->audit->record('auth.reset_completed', 'user', (string) $user->id, [
            'username' => $user->username,
            'ticket_id' => $ticketRecord->id,
        ], actorType: 'user', actorId: (string) $user->id);
    }

    private function resolveResetTicket(int $userId, string $ticket, DateTimeImmutable $now): PasswordResetTicket
    {
        $parts = explode('.', trim($ticket), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            $this->recordResetAttempt($userId, false, 'ticket_malformed', $now);
            throw new AuthenticationException('Invalid or expired reset ticket.');
        }
        [$ticketId, $secret] = $parts;
        /** @var PasswordResetTicket|null $record */
        $record = PasswordResetTicket::query()->find($ticketId);
        if (!$record instanceof PasswordResetTicket || (int) $record->user_id !== $userId) {
            $this->recordResetAttempt($userId, false, 'ticket_unknown', $now);
            throw new AuthenticationException('Invalid or expired reset ticket.');
        }
        if (!hash_equals((string) $record->ticket_hash, hash('sha256', $secret))) {
            $this->recordResetAttempt($userId, false, 'ticket_mismatch', $now);
            throw new AuthenticationException('Invalid or expired reset ticket.');
        }
        if ($record->consumed_at !== null) {
            $this->recordResetAttempt($userId, false, 'ticket_already_consumed', $now);
            throw new AuthenticationException('Reset ticket already used.');
        }
        if ($record->revoked_at !== null) {
            $this->recordResetAttempt($userId, false, 'ticket_revoked', $now);
            throw new AuthenticationException('Reset ticket revoked.');
        }
        $expiresAt = $record->expires_at instanceof DateTimeImmutable
            ? $record->expires_at
            : new DateTimeImmutable((string) $record->expires_at);
        if ($expiresAt <= $now) {
            $this->recordResetAttempt($userId, false, 'ticket_expired', $now);
            throw new AuthenticationException('Reset ticket expired.');
        }
        return $record;
    }

    public function normalizeAnswer(string $raw): string
    {
        $s = mb_strtolower(trim($raw), 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return $s;
    }

    private function recordLoginAttempt(string $username, ?int $userId, bool $success, ?string $reason, DateTimeImmutable $now): void
    {
        LoginAttempt::query()->create([
            'username' => mb_substr($username, 0, 64),
            'user_id' => $userId,
            'success' => $success,
            'reason' => $reason,
            'attempted_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    private function recordResetAttempt(int $userId, bool $success, ?string $reason, DateTimeImmutable $now): void
    {
        PasswordResetAttempt::query()->create([
            'user_id' => $userId,
            'success' => $success,
            'reason' => $reason,
            'attempted_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    private function maybeApplyLoginLockout(User $user, DateTimeImmutable $now): void
    {
        $windowStart = $now->modify('-' . $this->policy->loginWindowSeconds . ' seconds')->format('Y-m-d H:i:s');
        $failures = LoginAttempt::query()
            ->where('user_id', (int) $user->id)
            ->where('success', false)
            ->where('attempted_at', '>=', $windowStart)
            ->count();
        if ($failures >= $this->policy->loginFailuresThreshold) {
            $user->status = 'locked';
            $user->locked_until = $now->modify('+' . $this->policy->loginLockSeconds . ' seconds')->format('Y-m-d H:i:s');
            $user->save();
            $this->audit->record('auth.account_locked', 'user', (string) $user->id, [
                'reason' => 'login_threshold',
                'failures' => $failures,
            ], actorType: 'system', actorId: 'auth');
        }
    }

    private function maybeApplyResetLockout(User $user, DateTimeImmutable $now): void
    {
        $windowStart = $now->modify('-' . $this->policy->resetWindowSeconds . ' seconds')->format('Y-m-d H:i:s');
        $failures = PasswordResetAttempt::query()
            ->where('user_id', (int) $user->id)
            ->where('success', false)
            ->where('attempted_at', '>=', $windowStart)
            ->count();
        if ($failures >= $this->policy->resetFailuresThreshold) {
            $user->reset_locked_until = $now->modify('+' . $this->policy->resetLockSeconds . ' seconds')->format('Y-m-d H:i:s');
            $user->save();
            $this->audit->record('auth.reset_locked', 'user', (string) $user->id, [
                'reason' => 'reset_threshold',
                'failures' => $failures,
            ], actorType: 'system', actorId: 'auth');
        }
    }

    public function adminResetPassword(User $actor, User $target, string $newPassword): void
    {
        if (!UserPermissions::hasPermission($actor, 'auth.reset_other_password')) {
            throw new AuthorizationException('Missing permission: auth.reset_other_password');
        }
        $target->password_hash = $this->hasher->hash($newPassword);
        $target->status = 'password_reset_required';
        $target->reset_locked_until = null;
        $target->save();
        $this->sessions->revokeAllForUser((int) $target->id, 'admin_reset');
        $this->audit->record('auth.admin_password_reset', 'user', (string) $target->id, [], actorType: 'user', actorId: (string) $actor->id);
    }

    public function assignRole(User $actor, User $target, string $roleKey, ?string $scopeType = null, ?string $scopeRef = null): UserRoleBinding
    {
        if (!UserPermissions::hasPermission($actor, 'auth.manage_roles')) {
            throw new AuthorizationException('Missing permission: auth.manage_roles');
        }
        $role = Role::query()->where('key', $roleKey)->first();
        if (!$role instanceof Role) {
            throw new ValidationException('Unknown role: ' . $roleKey, ['field' => 'role']);
        }
        $existing = UserRoleBinding::query()
            ->where('user_id', (int) $target->id)
            ->where('role_id', (int) $role->id)
            ->where('scope_type', $scopeType)
            ->where('scope_ref', $scopeRef)
            ->first();
        if ($existing instanceof UserRoleBinding) {
            throw new ConflictException('Role already assigned.', 'ROLE_ALREADY_ASSIGNED');
        }
        /** @var UserRoleBinding $binding */
        $binding = UserRoleBinding::query()->create([
            'user_id' => (int) $target->id,
            'role_id' => (int) $role->id,
            'scope_type' => $scopeType,
            'scope_ref' => $scopeRef,
            'granted_by_user_id' => (int) $actor->id,
        ]);
        $this->audit->record('auth.role_assigned', 'user', (string) $target->id, [
            'role' => $roleKey,
            'scope_type' => $scopeType,
            'scope_ref' => $scopeRef,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $binding;
    }
}
