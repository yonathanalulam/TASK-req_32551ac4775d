<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Dedup;

use Meridian\Domain\Dedup\FingerprintService;
use PHPUnit\Framework\TestCase;

final class FingerprintServiceTest extends TestCase
{
    public function testNormalizedTitleCollapsesWhitespaceAndStripsPunctuation(): void
    {
        $fp = new FingerprintService();
        self::assertSame('hello world', $fp->normalizeTitle("  Hello, WORLD!  \n"));
    }

    public function testSimilarityOneWhenEqual(): void
    {
        $fp = new FingerprintService();
        self::assertSame(1.0, $fp->similarity('quick brown fox', 'quick brown fox'));
    }

    public function testSimilarityHighForMinorEdits(): void
    {
        $fp = new FingerprintService();
        $s = $fp->similarity('the quick brown fox', 'the quick brown foxes');
        self::assertGreaterThan(0.9, $s);
    }

    public function testSimilarityLowForDifferentTitles(): void
    {
        $fp = new FingerprintService();
        $s = $fp->similarity('apples and oranges', 'zeppelins over tokyo');
        self::assertLessThan(0.7, $s);
    }
}
