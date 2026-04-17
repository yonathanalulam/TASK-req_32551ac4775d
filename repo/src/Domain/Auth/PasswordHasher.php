<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use Meridian\Application\Exceptions\ValidationException;

/**
 * Bcrypt-based password hashing. Cost configurable; minimum length enforced here.
 */
final class PasswordHasher
{
    public function __construct(private readonly int $cost = 12, private readonly int $minLength = 12)
    {
    }

    public function hash(string $plain): string
    {
        $trimmed = trim($plain);
        if (mb_strlen($trimmed, 'UTF-8') < $this->minLength) {
            throw new ValidationException(
                'Password must be at least ' . $this->minLength . ' characters.',
                ['field' => 'password', 'rule' => 'min_length'],
            );
        }
        $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => $this->cost]);
        if ($hash === false) {
            throw new \RuntimeException('password hashing failed');
        }
        return $hash;
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $this->cost]);
    }
}
