<?php

declare(strict_types=1);

namespace Meridian\Application\Exceptions;

use RuntimeException;

/**
 * Base class for any domain/API error that should be serialized through the standard envelope.
 */
class ApiException extends RuntimeException
{
    // NOTE: the property is named `apiCode` (not `code`) because `\Exception::$code` already
    // exists as a non-readonly property, and PHP rejects redeclaring it readonly.
    private readonly string $apiCode;
    private readonly int $status;
    /** @var array<string,mixed>|null */
    private readonly ?array $details;

    /** @param array<string,mixed>|null $details */
    public function __construct(
        string $code,
        string $message,
        int $status = 400,
        ?array $details = null,
    ) {
        parent::__construct($message);
        $this->apiCode = $code;
        $this->status = $status;
        $this->details = $details;
    }

    public function errorCode(): string
    {
        return $this->apiCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed>|null */
    public function details(): ?array
    {
        return $this->details;
    }
}
