<?php

declare(strict_types=1);

namespace Meridian\Application\Exceptions;

final class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Resource not found.', ?array $details = null)
    {
        parent::__construct('NOT_FOUND', $message, 404, $details);
    }
}
