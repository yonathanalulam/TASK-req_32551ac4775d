<?php

declare(strict_types=1);

namespace Meridian\Infrastructure\Clock;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
