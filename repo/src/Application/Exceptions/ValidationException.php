<?php

declare(strict_types=1);

namespace Meridian\Application\Exceptions;

final class ValidationException extends ApiException
{
    public function __construct(string $message, ?array $details = null)
    {
        parent::__construct('VALIDATION_ERROR', $message, 422, $details);
    }
}
