<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

/**
 * Value object capturing login and reset lockout thresholds.
 */
final class LockoutPolicy
{
    public function __construct(
        public readonly int $loginFailuresThreshold,
        public readonly int $loginWindowSeconds,
        public readonly int $loginLockSeconds,
        public readonly int $resetFailuresThreshold,
        public readonly int $resetWindowSeconds,
        public readonly int $resetLockSeconds,
    ) {
    }
}
