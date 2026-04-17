<?php

declare(strict_types=1);

namespace Meridian\Domain\Content\Parsing;

/**
 * Strips URLs from free-form text while returning the stripped URLs so the caller may
 * retain them as provenance/evidence.
 */
final class UrlStripper
{
    // Delimiter `{}` is used so the `#` fragment character inside the character class below
    // isn't mis-interpreted as the regex delimiter by the PCRE engine.
    private const URL_RE = '{\b(?:https?|ftp)://[^\s<>"\'\]\)]+}u';
    private const WWW_RE = '{\bwww\.[A-Za-z0-9][A-Za-z0-9\-\._~:/?#\[\]@!$&\'()*+,;=]*}u';

    /** @return array{text:string,urls:array<int,string>} */
    public function strip(string $text): array
    {
        $urls = [];
        $replacer = static function (array $m) use (&$urls) {
            $urls[] = $m[0];
            return ' ';
        };
        $stripped = preg_replace_callback(self::URL_RE, $replacer, $text);
        $stripped = preg_replace_callback(self::WWW_RE, $replacer, (string) $stripped);
        $stripped = preg_replace('/\s{2,}/u', ' ', (string) $stripped) ?? '';
        return ['text' => trim($stripped), 'urls' => array_values(array_unique($urls))];
    }
}
