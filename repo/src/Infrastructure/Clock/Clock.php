<?php

declare(strict_types=1);

namespace Meridian\Infrastructure\Clock;

use DateTimeImmutable;

interface Clock
{
    public function nowUtc(): DateTimeImmutable;
}
