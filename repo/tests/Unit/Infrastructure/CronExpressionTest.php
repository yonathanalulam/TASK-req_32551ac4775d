<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Meridian\Infrastructure\Cron\CronExpression;
use PHPUnit\Framework\TestCase;

final class CronExpressionTest extends TestCase
{
    private function t(string $dt): DateTimeImmutable
    {
        return new DateTimeImmutable($dt, new DateTimeZone('UTC'));
    }

    public function testEveryMinuteMatches(): void
    {
        $c = new CronExpression();
        self::assertTrue($c->isDue('* * * * *', $this->t('2026-04-17 12:34:00')));
    }

    public function testAtOneOClockEveryDay(): void
    {
        $c = new CronExpression();
        self::assertTrue($c->isDue('0 1 * * *', $this->t('2026-04-17 01:00:00')));
        self::assertFalse($c->isDue('0 1 * * *', $this->t('2026-04-17 02:00:00')));
    }

    public function testRangeAndStep(): void
    {
        $c = new CronExpression();
        self::assertTrue($c->isDue('*/15 9-17 * * 1-5', $this->t('2026-04-17 09:15:00'))); // Friday
        self::assertFalse($c->isDue('*/15 9-17 * * 1-5', $this->t('2026-04-18 09:15:00'))); // Saturday
    }
}
