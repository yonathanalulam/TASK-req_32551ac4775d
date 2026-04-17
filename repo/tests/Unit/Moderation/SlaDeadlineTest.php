<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Authorization\Policy;
use Meridian\Domain\Moderation\ModerationService;
use Meridian\Domain\Moderation\RuleEvaluator;
use Meridian\Infrastructure\Clock\Clock;
use PHPUnit\Framework\TestCase;

final class SlaDeadlineTest extends TestCase
{
    public function testSlaDueOnNextBusinessDayWhenOpenedLateFriday(): void
    {
        $clock = new class implements Clock {
            public function nowUtc(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-04-17 16:00:00', new DateTimeZone('UTC')); // Friday 16:00
            }
        };
        $service = $this->makeService($clock, 24);
        $due = $service->computeSlaDueAt($clock->nowUtc());
        // Friday 16:00 + 24 business hours = Wednesday 16:00
        self::assertSame('2026-04-22 16:00:00', $due->format('Y-m-d H:i:s'));
    }

    public function testSlaShortCaseLandsSameDay(): void
    {
        $clock = new class implements Clock {
            public function nowUtc(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-04-15 10:00:00', new DateTimeZone('UTC')); // Wed 10:00
            }
        };
        $service = $this->makeService($clock, 4);
        $due = $service->computeSlaDueAt($clock->nowUtc());
        self::assertSame('2026-04-15 14:00:00', $due->format('Y-m-d H:i:s'));
    }

    /**
     * Build a ModerationService wired for SLA-only unit tests. Mirrors the full
     * constructor contract (clock, rule evaluator, audit, sla config, policy) so
     * the test stays statically aligned with `src/Domain/Moderation/ModerationService.php`.
     * computeSlaDueAt does not exercise Policy, so a real instance suffices.
     */
    private function makeService(Clock $clock, int $initialHours): ModerationService
    {
        return new ModerationService(
            $clock,
            new RuleEvaluator(),
            $this->createStub(AuditLogger::class),
            [
                'business_hours' => ['start' => '09:00', 'end' => '17:00', 'weekdays' => [1, 2, 3, 4, 5]],
                'moderation_initial_hours' => $initialHours,
            ],
            new Policy(),
        );
    }
}
