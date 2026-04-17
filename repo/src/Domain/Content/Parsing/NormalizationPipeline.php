<?php

declare(strict_types=1);

namespace Meridian\Domain\Content\Parsing;

use DateTimeImmutable;
use DateTimeZone;
use Meridian\Application\Exceptions\ValidationException;

/**
 * Converts raw HTML or plain text into a trusted, deterministic NormalizedContent instance.
 *
 * Steps (in order):
 *   1. Canonicalize input to UTF-8, replacing invalid sequences deterministically.
 *   2. Compute raw payload checksum & bytes prior to any transformation.
 *   3. HTML denoising when kind=html. Plain text is passed through.
 *   4. URL stripping from body text.
 *   5. Title validation and trim (1..180 chars).
 *   6. Body length floor (>= min_body_length after denoise/strip).
 *   7. Language detection; if confidence below threshold and no override permission -> 422.
 *   8. Section tag canonicalization.
 *   9. Media source enum clamping.
 *  10. Checksums and normalized body hash.
 */
final class NormalizationPipeline
{
    public function __construct(
        private readonly HtmlDenoiser $denoiser,
        private readonly UrlStripper $stripper,
        private readonly SectionTagNormalizer $tags,
        private readonly LanguageDetector $languageDetector,
        private readonly array $config,
    ) {
    }

    /**
     * @param array<string,mixed> $input {
     *   @type string $payload Raw HTML or plain text.
     *   @type string $kind 'html'|'plain_text'
     *   @type ?string $title Caller-provided override title.
     *   @type ?string $author
     *   @type ?string $media_source
     *   @type ?array<int,string> $section_tags
     *   @type ?int $duration_seconds
     *   @type ?string $published_at
     *   @type ?string $language_override
     *   @type bool $language_override_allowed
     * }
     */
    public function normalize(array $input): NormalizedContent
    {
        $payload = (string) ($input['payload'] ?? '');
        $kind = (string) ($input['kind'] ?? 'plain_text');
        if ($payload === '') {
            throw new ValidationException('payload is required.', ['field' => 'payload']);
        }
        $canonical = $this->toUtf8($payload);
        $rawBytes = strlen($payload);
        $rawChecksum = hash('sha256', $payload);

        if ($kind === 'html') {
            $d = $this->denoiser->denoise($canonical);
            $candidateTitle = $d['title'];
            $bodyRaw = $d['body'];
            $media = $d['media_candidates'];
            $provenance = $d['provenance_urls'];
            $adLinks = $d['ad_link_count'];
        } elseif ($kind === 'plain_text') {
            $candidateTitle = null;
            $bodyRaw = trim($canonical);
            $media = [];
            $provenance = [];
            $adLinks = 0;
        } else {
            throw new ValidationException('kind must be html or plain_text.', ['field' => 'kind']);
        }

        $providedTitle = isset($input['title']) && is_string($input['title']) ? trim($input['title']) : null;
        $title = $providedTitle !== null && $providedTitle !== '' ? $providedTitle : ($candidateTitle ?? null);
        if ($title === null || $title === '') {
            throw new ValidationException('title is required.', ['field' => 'title', 'rule' => 'required']);
        }
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if (mb_strlen($title, 'UTF-8') > (int) $this->config['title_max_length']) {
            throw new ValidationException('title exceeds maximum length.', ['field' => 'title', 'rule' => 'max_length']);
        }

        $stripOut = $this->stripper->strip($bodyRaw);
        $bodyStripped = $stripOut['text'];
        $provenance = array_values(array_unique(array_merge($provenance, $stripOut['urls'])));

        $bodyNormalized = preg_replace('/\s+/u', ' ', $bodyStripped) ?? $bodyStripped;
        $bodyNormalized = trim($bodyNormalized);
        if (mb_strlen($bodyNormalized, 'UTF-8') < (int) $this->config['body_min_length']) {
            throw new ValidationException(
                'Body must contain at least ' . $this->config['body_min_length'] . ' characters after denoising.',
                ['field' => 'body', 'rule' => 'min_length_after_denoise'],
            );
        }

        $overrideLang = isset($input['language_override']) && is_string($input['language_override']) ? strtolower($input['language_override']) : null;
        $overrideAllowed = (bool) ($input['language_override_allowed'] ?? false);
        $detected = $this->languageDetector->detect($bodyNormalized);
        if ($overrideLang !== null && $overrideAllowed) {
            $lang = $overrideLang;
            $conf = 1.0;
        } else {
            $lang = $detected['code'];
            $conf = $detected['confidence'];
            if ($conf < $this->languageDetector->confidenceThreshold()) {
                throw new ValidationException(
                    'Language confidence below threshold; require language_override.',
                    ['field' => 'language', 'rule' => 'low_confidence', 'detected' => $lang, 'confidence' => $conf],
                );
            }
        }

        $mediaSource = isset($input['media_source']) ? (string) $input['media_source'] : 'unknown';
        $allowedMedia = ['article', 'image', 'video', 'mixed', 'unknown'];
        if (!in_array($mediaSource, $allowedMedia, true)) {
            throw new ValidationException('Invalid media_source.', ['field' => 'media_source', 'allowed' => $allowedMedia]);
        }

        $tags = $this->tags->normalize((array) ($input['section_tags'] ?? []));

        $publishedAt = null;
        if (isset($input['published_at']) && $input['published_at'] !== '') {
            try {
                $publishedAt = new DateTimeImmutable((string) $input['published_at']);
                $publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));
            } catch (\Throwable) {
                throw new ValidationException('Invalid published_at datetime.', ['field' => 'published_at']);
            }
        }
        $author = isset($input['author']) && is_string($input['author']) ? mb_substr(trim($input['author']), 0, 180, 'UTF-8') : null;
        $duration = isset($input['duration_seconds']) ? max(0, (int) $input['duration_seconds']) : null;

        $bodyChecksum = hash('sha256', $bodyNormalized);

        return new NormalizedContent(
            title: $title,
            body: $bodyNormalized,
            language: $lang,
            languageConfidence: (float) $conf,
            author: $author,
            mediaSource: $mediaSource,
            publishedAt: $publishedAt,
            sectionTags: $tags,
            durationSeconds: $duration,
            adLinkCount: (int) $adLinks,
            mediaCandidates: $media,
            provenanceUrls: $provenance,
            rawChecksum: $rawChecksum,
            rawBytes: $rawBytes,
            bodyChecksum: $bodyChecksum,
        );
    }

    private function toUtf8(string $s): string
    {
        $encoded = mb_convert_encoding($s, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1');
        return is_string($encoded) ? $encoded : '';
    }
}
