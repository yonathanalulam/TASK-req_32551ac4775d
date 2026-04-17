<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use DateTimeImmutable;
use Meridian\Infrastructure\Clock\Clock;
use Meridian\Infrastructure\Crypto\Cipher;
use Ramsey\Uuid\Uuid;

/**
 * Session issuance, lookup, refresh, and revocation.
 *
 * Token format: <session_id>.<raw_secret>
 * token_hash stored as SHA-256(raw_secret) to prevent replay if the DB is read.
 * IP addresses are stored as encrypted ciphertext to support masking rules.
 */
final class SessionService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Cipher $cipher,
        private readonly int $absoluteTtl,
        private readonly int $idleTtl,
        private readonly int $maxConcurrent,
    ) {
    }

    /** @return array{token:string,session:UserSession} */
    public function issue(User $user, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $now = $this->clock->nowUtc();
        $this->enforceConcurrentLimit($user, $now);

        $sessionId = Uuid::uuid4()->toString();
        $rawSecret = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawSecret);

        $session = new UserSession();
        $session->id = $sessionId;
        $session->user_id = (int) $user->id;
        $session->token_hash = $hash;
        $session->created_at = $now->format('Y-m-d H:i:s');
        $session->last_seen_at = $now->format('Y-m-d H:i:s');
        $session->absolute_expires_at = $now->modify('+' . $this->absoluteTtl . ' seconds')->format('Y-m-d H:i:s');
        $session->idle_expires_at = $now->modify('+' . $this->idleTtl . ' seconds')->format('Y-m-d H:i:s');
        $session->user_agent = $userAgent !== null ? mb_substr($userAgent, 0, 255) : null;
        $session->ip_address_ciphertext = $ipAddress !== null && $ipAddress !== ''
            ? $this->cipher->encrypt($ipAddress)
            : null;
        $session->save();

        return [
            'token' => $sessionId . '.' . $rawSecret,
            'session' => $session,
        ];
    }

    public function resolve(string $token): ?UserSession
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$sessionId, $secret] = $parts;
        /** @var UserSession|null $session */
        $session = UserSession::query()->find($sessionId);
        if (!$session instanceof UserSession) {
            return null;
        }
        if ($session->revoked_at !== null) {
            return null;
        }
        if (!hash_equals($session->token_hash, hash('sha256', $secret))) {
            return null;
        }
        $now = $this->clock->nowUtc();
        if (!$session->isActive($now)) {
            return null;
        }
        $session->last_seen_at = $now->format('Y-m-d H:i:s');
        $session->idle_expires_at = $now->modify('+' . $this->idleTtl . ' seconds')->format('Y-m-d H:i:s');
        $session->save();
        return $session;
    }

    public function revoke(UserSession $session, string $reason = 'logout'): void
    {
        $now = $this->clock->nowUtc();
        $session->revoked_at = $now->format('Y-m-d H:i:s');
        $session->revoke_reason = $reason;
        $session->save();
    }

    public function revokeAllForUser(int $userId, string $reason): int
    {
        $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');
        return UserSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'revoke_reason' => $reason]);
    }

    private function enforceConcurrentLimit(User $user, DateTimeImmutable $now): void
    {
        $nowFmt = $now->format('Y-m-d H:i:s');
        /** @var array<int,UserSession> $active */
        $active = UserSession::query()
            ->where('user_id', (int) $user->id)
            ->whereNull('revoked_at')
            ->where('absolute_expires_at', '>', $nowFmt)
            ->where('idle_expires_at', '>', $nowFmt)
            ->orderBy('last_seen_at', 'asc')
            ->get()
            ->all();
        while (count($active) >= $this->maxConcurrent) {
            $oldest = array_shift($active);
            if ($oldest instanceof UserSession) {
                $this->revoke($oldest, 'concurrent_limit');
            }
        }
    }
}
