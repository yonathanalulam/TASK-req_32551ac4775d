<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Moderation;

use Meridian\Domain\Content\Content;
use Meridian\Domain\Moderation\RuleEvaluator;
use Meridian\Domain\Moderation\RulePackRule;
use PHPUnit\Framework\TestCase;

final class RuleEvaluatorTest extends TestCase
{
    public function testKeywordHit(): void
    {
        $eval = new RuleEvaluator();
        $rule = new RulePackRule();
        $rule->id = 1;
        $rule->rule_kind = 'keyword';
        $rule->pattern = 'prohibited';
        $rule->severity = 'warning';

        $content = new Content();
        $content->body = 'This text contains prohibited material and should flag.';

        $flags = $eval->evaluate($content, [$rule], 0, []);
        self::assertCount(1, $flags);
        self::assertSame('keyword', $flags[0]['rule_kind']);
    }

    public function testAdLinkDensityFormulaTriggersWhenOverThreshold(): void
    {
        $eval = new RuleEvaluator(3.0);
        $rule = new RulePackRule();
        $rule->id = 2;
        $rule->rule_kind = 'ad_link_density';
        $rule->threshold = 3.0;
        $rule->severity = 'critical';

        $content = new Content();
        $content->body = str_repeat('a', 1000); // 1000 chars, threshold 3/1000

        $flagsBelow = $eval->evaluate($content, [$rule], 3, []);
        self::assertCount(0, $flagsBelow, 'density of 3.0 is at threshold, not over');

        $flagsOver = $eval->evaluate($content, [$rule], 4, []);
        self::assertCount(1, $flagsOver);
        self::assertSame('ad_link_density', $flagsOver[0]['rule_kind']);
    }

    public function testAdLinkDensityFormula(): void
    {
        $eval = new RuleEvaluator();
        self::assertEqualsWithDelta(3.0, $eval->adLinkDensity(3, 1000), 1e-9);
        self::assertEqualsWithDelta(6.0, $eval->adLinkDensity(6, 1000), 1e-9);
    }

    public function testBannedDomainMatchesInProvenanceUrls(): void
    {
        $eval = new RuleEvaluator();
        $rule = new RulePackRule();
        $rule->id = 3;
        $rule->rule_kind = 'banned_domain';
        $rule->pattern = 'bad.example';
        $rule->severity = 'warning';

        $content = new Content();
        $content->body = 'clean body';
        $flags = $eval->evaluate($content, [$rule], 0, ['https://bad.example/page', 'https://ok.example/']);
        self::assertCount(1, $flags);
        self::assertSame('banned_domain', $flags[0]['rule_kind']);
    }
}
