<?php

declare(strict_types=1);

namespace Meridian\Domain\Content\Parsing;

/**
 * Canonicalizes section tags: lowercase ASCII slug, hyphen-joined, deduplicated,
 * length-capped at 64 per tag. Caps list length at the configured maximum.
 */
final class SectionTagNormalizer
{
    public function __construct(private readonly int $maxTags = 10)
    {
    }

    /**
     * @param array<int,mixed> $tags
     * @return array<int,string>
     */
    public function normalize(array $tags): array
    {
        $canonical = [];
        foreach ($tags as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $slug = $this->slug($raw);
            if ($slug === '') {
                continue;
            }
            $canonical[$slug] = true;
        }
        $list = array_slice(array_keys($canonical), 0, $this->maxTags);
        return array_values($list);
    }

    private function slug(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? '';
        $s = trim($s, '-');
        if ($s === '') {
            return '';
        }
        if (mb_strlen($s, 'UTF-8') > 64) {
            $s = mb_substr($s, 0, 64, 'UTF-8');
        }
        return $s;
    }
}
