<?php

declare(strict_types=1);

namespace Meridian\Application\Exceptions;

final class AuthenticationException extends ApiException
{
    public function __construct(string $message = 'Authentication required.', ?array $details = null)
    {
        parent::__construct('AUTHENTICATION_REQUIRED', $message, 401, $details);
    }
}
