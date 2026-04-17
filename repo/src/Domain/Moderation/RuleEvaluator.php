<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Meridian\Domain\Content\Content;

/**
 * Evaluates a set of RulePackRule rows against a normalized Content row.
 *
 * Output: list of flags, each with rule_id, rule_kind, severity, evidence.
 * Ad-link density formula: (ad_link_count / max(body_length, 1)) * 1000
 */
final class RuleEvaluator
{
    public function __construct(private readonly float $adDensityMax = 3.0)
    {
    }

    /**
     * @param array<int,RulePackRule> $rules
     * @return array<int,array{rule_id:int,rule_kind:string,severity:string,evidence:array<string,mixed>}>
     */
    public function evaluate(Content $content, array $rules, int $adLinkCount, array $provenanceUrls = []): array
    {
        $flags = [];
        $bodyLower = mb_strtolower($content->body, 'UTF-8');
        $bodyLen = max(1, mb_strlen($content->body, 'UTF-8'));
        $density = ($adLinkCount / $bodyLen) * 1000;

        foreach ($rules as $rule) {
            $kind = $rule->rule_kind;
            switch ($kind) {
                case 'keyword':
                    $kw = mb_strtolower((string) $rule->pattern, 'UTF-8');
                    if ($kw !== '' && str_contains($bodyLower, $kw)) {
                        $flags[] = [
                            'rule_id' => (int) $rule->id,
                            'rule_kind' => $kind,
                            'severity' => (string) $rule->severity,
                            'evidence' => ['keyword' => $rule->pattern],
                        ];
                    }
                    break;
                case 'regex':
                    $pattern = (string) $rule->pattern;
                    if ($pattern !== '' && @preg_match($pattern, $content->body, $matches) === 1) {
                        $flags[] = [
                            'rule_id' => (int) $rule->id,
                            'rule_kind' => $kind,
                            'severity' => (string) $rule->severity,
                            'evidence' => ['match' => mb_substr((string) $matches[0], 0, 240, 'UTF-8')],
                        ];
                    }
                    break;
                case 'banned_domain':
                    $domain = mb_strtolower(trim((string) $rule->pattern), 'UTF-8');
                    foreach ($provenanceUrls as $u) {
                        $host = parse_url((string) $u, PHP_URL_HOST);
                        if (is_string($host) && mb_strpos(mb_strtolower($host, 'UTF-8'), $domain) !== false) {
                            $flags[] = [
                                'rule_id' => (int) $rule->id,
                                'rule_kind' => $kind,
                                'severity' => (string) $rule->severity,
                                'evidence' => ['url' => mb_substr((string) $u, 0, 240, 'UTF-8'), 'matched_domain' => $domain],
                            ];
                            break;
                        }
                    }
                    break;
                case 'ad_link_density':
                    $threshold = $rule->threshold !== null ? (float) $rule->threshold : $this->adDensityMax;
                    if ($density > $threshold) {
                        $flags[] = [
                            'rule_id' => (int) $rule->id,
                            'rule_kind' => $kind,
                            'severity' => (string) $rule->severity,
                            'evidence' => ['density' => round($density, 4), 'threshold' => $threshold, 'ad_link_count' => $adLinkCount],
                        ];
                    }
                    break;
            }
        }
        return $flags;
    }

    public function adLinkDensity(int $adLinkCount, int $bodyLength): float
    {
        return ($adLinkCount / max(1, $bodyLength)) * 1000;
    }
}
