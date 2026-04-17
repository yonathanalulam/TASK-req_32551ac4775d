<?php

declare(strict_types=1);

namespace Meridian\Infrastructure\Cron;

use DateTimeImmutable;

/**
 * Minimal 5-field cron evaluator: "minute hour day-of-month month day-of-week".
 *
 * Supports:
 *   *        -> any value
 *   N        -> exact match
 *   a,b,c    -> list
 *   a-b      -> inclusive range
 *   * / N    -> step (every N)
 *   a-b / N  -> stepped range
 *
 * No network or third-party cron library. Deterministic for offline operation.
 */
final class CronExpression
{
    public function isDue(string $expression, DateTimeImmutable $at): bool
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            return false;
        }
        [$min, $hr, $dom, $mon, $dow] = $parts;
        $minute = (int) $at->format('i');
        $hour = (int) $at->format('G');
        $dayOfMonth = (int) $at->format('j');
        $month = (int) $at->format('n');
        $dayOfWeek = (int) $at->format('w'); // 0=Sun
        return $this->matches($min, $minute, 0, 59)
            && $this->matches($hr, $hour, 0, 23)
            && $this->matches($dom, $dayOfMonth, 1, 31)
            && $this->matches($mon, $month, 1, 12)
            && $this->matches($dow, $dayOfWeek, 0, 6);
    }

    private function matches(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $segment) {
            if ($this->segmentMatches(trim($segment), $value, $min, $max)) {
                return true;
            }
        }
        return false;
    }

    private function segmentMatches(string $segment, int $value, int $min, int $max): bool
    {
        $step = 1;
        if (str_contains($segment, '/')) {
            [$range, $stepStr] = explode('/', $segment, 2);
            $step = max(1, (int) $stepStr);
            $segment = $range;
        }
        if ($segment === '*' || $segment === '') {
            return ($value - $min) % $step === 0;
        }
        if (str_contains($segment, '-')) {
            [$a, $b] = explode('-', $segment, 2);
            $a = (int) $a;
            $b = (int) $b;
            if ($value < $a || $value > $b) {
                return false;
            }
            return ($value - $a) % $step === 0;
        }
        $n = (int) $segment;
        return $value === $n;
    }
}
