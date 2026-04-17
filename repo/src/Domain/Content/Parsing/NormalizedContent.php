<?php

declare(strict_types=1);

namespace Meridian\Domain\Content\Parsing;

/**
 * Value object produced by the NormalizationPipeline. Immutable.
 */
final class NormalizedContent
{
    /**
     * @param array<int,string> $sectionTags
     * @param array<int,array{media_type:string,src:string,alt?:string}> $mediaCandidates
     * @param array<int,string> $provenanceUrls
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $language,
        public readonly float $languageConfidence,
        public readonly ?string $author,
        public readonly string $mediaSource,
        public readonly ?\DateTimeImmutable $publishedAt,
        public readonly array $sectionTags,
        public readonly ?int $durationSeconds,
        public readonly int $adLinkCount,
        public readonly array $mediaCandidates,
        public readonly array $provenanceUrls,
        public readonly string $rawChecksum,
        public readonly int $rawBytes,
        public readonly string $bodyChecksum,
    ) {
    }
}
