<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Parsing;

use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Content\Parsing\HtmlDenoiser;
use Meridian\Domain\Content\Parsing\LanguageDetector;
use Meridian\Domain\Content\Parsing\NormalizationPipeline;
use Meridian\Domain\Content\Parsing\SectionTagNormalizer;
use Meridian\Domain\Content\Parsing\UrlStripper;
use PHPUnit\Framework\TestCase;

final class NormalizationPipelineTest extends TestCase
{
    private function pipeline(): NormalizationPipeline
    {
        return new NormalizationPipeline(
            new HtmlDenoiser(),
            new UrlStripper(),
            new SectionTagNormalizer(),
            new LanguageDetector(0.6),
            ['body_min_length' => 200, 'title_max_length' => 180, 'section_tags_max' => 10, 'language_confidence_threshold' => 0.6],
        );
    }

    public function testRejectsShortBody(): void
    {
        $this->expectException(ValidationException::class);
        $this->pipeline()->normalize([
            'payload' => 'too short',
            'kind' => 'plain_text',
            'title' => 'Short title',
            'media_source' => 'article',
        ]);
    }

    public function testStripsUrlsAndReturnsProvenance(): void
    {
        $payload = 'The quick brown fox visited https://example.com/path?utm_source=x and www.example.org '
            . str_repeat('and moved on through the field ', 20);
        $out = $this->pipeline()->normalize([
            'payload' => $payload,
            'kind' => 'plain_text',
            'title' => 'A very quick fox',
            'media_source' => 'article',
            'section_tags' => ['Nature Watch', 'nature-watch', 'Wildlife'],
        ]);
        self::assertStringNotContainsString('https://', $out->body);
        self::assertStringNotContainsString('www.', $out->body);
        self::assertContains('nature-watch', $out->sectionTags);
        self::assertContains('wildlife', $out->sectionTags);
    }

    public function testHtmlDenoisingRemovesScriptsAndBoilerplate(): void
    {
        $html = '<html><head><title>Title Here</title></head><body>'
            . '<nav>Menu</nav>'
            . '<div class="ad-banner">BUY NOW</div>'
            . '<article><h1>Content Title</h1><p>' . str_repeat('This is an article about interesting topics. ', 30) . '</p></article>'
            . '<script>alert(1)</script>'
            . '</body></html>';
        $out = $this->pipeline()->normalize([
            'payload' => $html,
            'kind' => 'html',
            'media_source' => 'article',
        ]);
        self::assertStringNotContainsString('alert(1)', $out->body);
        self::assertStringNotContainsString('BUY NOW', $out->body);
        self::assertSame('Content Title', $out->title);
    }

    public function testDeterministicChecksumForIdenticalPayload(): void
    {
        $payload = str_repeat('This is determinism test content. ', 50);
        $input = [
            'payload' => $payload,
            'kind' => 'plain_text',
            'title' => 'Determinism',
            'media_source' => 'article',
        ];
        $a = $this->pipeline()->normalize($input);
        $b = $this->pipeline()->normalize($input);
        self::assertSame($a->bodyChecksum, $b->bodyChecksum);
        self::assertSame($a->rawChecksum, $b->rawChecksum);
    }
}
