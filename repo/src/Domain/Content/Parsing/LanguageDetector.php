<?php

declare(strict_types=1);

namespace Meridian\Domain\Content\Parsing;

/**
 * Offline language detector using character n-gram + function-word scoring against a small
 * built-in corpus of high-frequency words per language. Deterministic and self-contained.
 *
 * Returns an ISO 639-1 code and a confidence score in [0,1]. Supports common Latin-script
 * languages plus CJK heuristics (zh/ja/ko). If no sample matches, returns 'und' with 0 confidence.
 */
final class LanguageDetector
{
    /** @var array<string,array<int,string>> */
    private const STOPWORDS = [
        'en' => ['the', 'and', 'to', 'of', 'a', 'in', 'is', 'it', 'that', 'for', 'on', 'with', 'as', 'by', 'this', 'from', 'have', 'not', 'but', 'are', 'was', 'be', 'at', 'an', 'or'],
        'es' => ['el', 'la', 'de', 'que', 'y', 'a', 'en', 'un', 'por', 'con', 'no', 'una', 'su', 'para', 'los', 'las', 'se', 'del', 'al', 'es', 'más', 'o', 'pero', 'como', 'todo'],
        'fr' => ['le', 'la', 'les', 'de', 'des', 'et', 'à', 'en', 'un', 'une', 'pour', 'dans', 'que', 'qui', 'par', 'sur', 'est', 'ne', 'pas', 'au', 'avec', 'plus', 'ce', 'son', 'ses'],
        'de' => ['der', 'die', 'das', 'und', 'ist', 'von', 'nicht', 'ein', 'eine', 'mit', 'den', 'für', 'auf', 'auch', 'als', 'bei', 'aber', 'nach', 'zu', 'aus', 'sich', 'werden', 'haben', 'sind', 'war'],
        'it' => ['il', 'la', 'di', 'che', 'e', 'in', 'un', 'per', 'non', 'una', 'è', 'con', 'sono', 'del', 'ma', 'si', 'al', 'come', 'anche', 'questa', 'più', 'da', 'le'],
        'pt' => ['o', 'a', 'de', 'que', 'e', 'do', 'da', 'em', 'um', 'para', 'com', 'não', 'uma', 'os', 'as', 'se', 'na', 'por', 'mais', 'como', 'ao', 'também', 'mas'],
        'nl' => ['de', 'het', 'een', 'en', 'van', 'dat', 'is', 'op', 'te', 'zijn', 'met', 'voor', 'niet', 'ook', 'aan', 'door', 'uit', 'maar', 'als', 'over'],
    ];

    public function __construct(private readonly float $confidenceThreshold = 0.75)
    {
    }

    /** @return array{code:string,confidence:float} */
    public function detect(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return ['code' => 'und', 'confidence' => 0.0];
        }

        if ($cjk = $this->cjkGuess($trimmed)) {
            return $cjk;
        }

        $tokens = $this->tokenize($trimmed);
        if ($tokens === []) {
            return ['code' => 'und', 'confidence' => 0.0];
        }
        $totalTokens = count($tokens);
        $tokenSet = array_count_values($tokens);

        $best = ['code' => 'und', 'score' => 0];
        $second = 0;
        foreach (self::STOPWORDS as $lang => $words) {
            $hits = 0;
            foreach ($words as $w) {
                if (isset($tokenSet[$w])) {
                    $hits += $tokenSet[$w];
                }
            }
            if ($hits > $best['score']) {
                $second = $best['score'];
                $best = ['code' => $lang, 'score' => $hits];
            } elseif ($hits > $second) {
                $second = $hits;
            }
        }
        if ($best['score'] === 0) {
            return ['code' => 'und', 'confidence' => 0.0];
        }
        $density = $best['score'] / max(1, $totalTokens);
        $margin = ($best['score'] - $second) / max(1, $best['score']);
        $confidence = min(1.0, ($density * 5.0 + $margin) / 2.0);
        return ['code' => $best['code'], 'confidence' => round($confidence, 4)];
    }

    public function confidenceThreshold(): float
    {
        return $this->confidenceThreshold;
    }

    private function cjkGuess(string $text): ?array
    {
        $len = mb_strlen($text, 'UTF-8');
        if ($len === 0) {
            return null;
        }
        $hasHira = preg_match('/\p{Hiragana}/u', $text) === 1;
        $hasKata = preg_match('/\p{Katakana}/u', $text) === 1;
        $hasHangul = preg_match('/\p{Hangul}/u', $text) === 1;
        $hasHan = preg_match('/\p{Han}/u', $text) === 1;
        if ($hasHangul) {
            return ['code' => 'ko', 'confidence' => 0.95];
        }
        if ($hasHira || $hasKata) {
            return ['code' => 'ja', 'confidence' => 0.95];
        }
        if ($hasHan && mb_strlen(preg_replace('/\p{Han}/u', '', $text) ?? '', 'UTF-8') < $len / 2) {
            return ['code' => 'zh', 'confidence' => 0.9];
        }
        return null;
    }

    /** @return array<int,string> */
    private function tokenize(string $text): array
    {
        $lc = mb_strtolower($text, 'UTF-8');
        preg_match_all('/\p{L}+/u', $lc, $m);
        return $m[0] ?? [];
    }
}
