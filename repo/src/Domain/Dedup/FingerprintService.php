<?php

declare(strict_types=1);

namespace Meridian\Domain\Dedup;

use Meridian\Domain\Content\Content;
use Meridian\Domain\Content\ContentFingerprint;

/**
 * Composes dedup fingerprints and computes similarity between them.
 *
 * title_normalized: lowercased, punctuation-stripped, whitespace-collapsed.
 * author_normalized: same treatment as title.
 * simhash_hex: 64-bit simhash over 3-word shingles of the title, for near-duplicate discovery.
 * composite_fingerprint: sha256(title_normalized || "\u{1f}" || author_normalized || "\u{1f}" || duration)
 *
 * Title similarity = Jaro-Winkler over title_normalized. Value in [0, 1].
 */
final class FingerprintService
{
    private const ALGORITHM_VERSION = 1;

    public function recompute(Content $content): ContentFingerprint
    {
        $titleNorm = $this->normalizeTitle($content->title);
        $authorNorm = $content->author !== null ? $this->normalizeTitle((string) $content->author) : null;
        $duration = $content->duration_seconds;
        $composite = hash('sha256', implode("\x1f", [
            $titleNorm,
            $authorNorm ?? '',
            (string) ($duration ?? ''),
        ]));
        $simhash = $this->simhash($titleNorm);

        $fp = ContentFingerprint::query()->updateOrCreate(
            ['content_id' => $content->content_id],
            [
                'title_normalized' => mb_substr($titleNorm, 0, 255, 'UTF-8'),
                'author_normalized' => $authorNorm !== null ? mb_substr($authorNorm, 0, 191, 'UTF-8') : null,
                'duration_seconds' => $duration,
                'simhash_hex' => $simhash,
                'composite_fingerprint' => $composite,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        );
        $content->title_normalized = mb_substr($titleNorm, 0, 191, 'UTF-8');
        $content->save();
        return $fp;
    }

    public function normalizeTitle(string $raw): string
    {
        $s = mb_strtolower($raw, 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    public function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        return $this->jaroWinkler($a, $b);
    }

    /** @param array<int,string> $titles */
    public function simhash(string $title): string
    {
        $tokens = preg_split('/\s+/u', $title) ?: [];
        if ($tokens === []) {
            return str_pad('0', 16, '0', STR_PAD_LEFT);
        }
        $vector = array_fill(0, 64, 0);
        foreach ($tokens as $t) {
            $hash = hash('sha256', $t, false);
            // use first 16 hex chars = 64 bits
            $bits = $this->hexToBits(substr($hash, 0, 16));
            foreach ($bits as $i => $b) {
                $vector[$i] += ($b === 1) ? 1 : -1;
            }
        }
        $bitsOut = '';
        foreach ($vector as $v) {
            $bitsOut .= $v > 0 ? '1' : '0';
        }
        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $hex .= dechex(bindec(substr($bitsOut, $i, 4)));
        }
        return $hex;
    }

    private function hexToBits(string $hex): array
    {
        $bits = [];
        for ($i = 0, $n = strlen($hex); $i < $n; $i++) {
            $nibble = hexdec($hex[$i]);
            for ($b = 3; $b >= 0; $b--) {
                $bits[] = ($nibble >> $b) & 1;
            }
        }
        return $bits;
    }

    private function jaroWinkler(string $a, string $b): float
    {
        $la = mb_strlen($a, 'UTF-8');
        $lb = mb_strlen($b, 'UTF-8');
        if ($la === 0 && $lb === 0) {
            return 1.0;
        }
        $matchDistance = (int) max(0, floor(max($la, $lb) / 2) - 1);
        $aMatches = array_fill(0, $la, false);
        $bMatches = array_fill(0, $lb, false);
        $matches = 0;
        $transpositions = 0;
        $aChars = $this->mbSplit($a);
        $bChars = $this->mbSplit($b);
        for ($i = 0; $i < $la; $i++) {
            $start = max(0, $i - $matchDistance);
            $end = min($i + $matchDistance + 1, $lb);
            for ($j = $start; $j < $end; $j++) {
                if ($bMatches[$j] || $aChars[$i] !== $bChars[$j]) {
                    continue;
                }
                $aMatches[$i] = true;
                $bMatches[$j] = true;
                $matches++;
                break;
            }
        }
        if ($matches === 0) {
            return 0.0;
        }
        $k = 0;
        for ($i = 0; $i < $la; $i++) {
            if (!$aMatches[$i]) {
                continue;
            }
            while (!$bMatches[$k]) {
                $k++;
            }
            if ($aChars[$i] !== $bChars[$k]) {
                $transpositions++;
            }
            $k++;
        }
        $transpositions = intdiv($transpositions, 2);
        $jaro = (($matches / $la) + ($matches / $lb) + (($matches - $transpositions) / $matches)) / 3.0;
        $prefix = 0;
        for ($i = 0; $i < min(4, $la, $lb); $i++) {
            if ($aChars[$i] === $bChars[$i]) {
                $prefix++;
            } else {
                break;
            }
        }
        return $jaro + ($prefix * 0.1 * (1 - $jaro));
    }

    /** @return array<int,string> */
    private function mbSplit(string $s): array
    {
        return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
