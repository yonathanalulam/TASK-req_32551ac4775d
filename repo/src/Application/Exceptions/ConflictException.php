<?php

declare(strict_types=1);

namespace Meridian\Application\Exceptions;

final class ConflictException extends ApiException
{
    public function __construct(string $message, string $code = 'CONFLICT', ?array $details = null)
    {
        parent::__construct($code, $message, 409, $details);
    }
}
