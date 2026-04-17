<?php

declare(strict_types=1);

namespace Meridian\Application\Exceptions;

final class RateLimitException extends ApiException
{
    public function __construct(int $retryAfter)
    {
        parent::__construct('RATE_LIMITED', 'Too many requests.', 429, ['retry_after_seconds' => $retryAfter]);
    }
}
