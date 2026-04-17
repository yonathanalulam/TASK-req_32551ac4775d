<?php

declare(strict_types=1);

namespace Meridian\Application\Exceptions;

final class AuthorizationException extends ApiException
{
    public function __construct(string $message = 'Not authorized.', ?array $details = null)
    {
        parent::__construct('NOT_AUTHORIZED', $message, 403, $details);
    }
}
