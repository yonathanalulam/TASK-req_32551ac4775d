<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Content\Content;

/**
 * Applies the currently-published rule packs to a freshly-ingested piece of content.
 *
 * Responsibilities (PRD 8.3, fix-prompt 4.2):
 * - evaluate keyword / regex / banned-domain / ad-link-density rules
 * - persist per-rule flags against one automated moderation case
 * - open the case with appropriate SLA
 * - mutate the content's risk_state based on the most severe flag found:
 *     critical       -> quarantined
 *     warning        -> flagged
 *     info / none    -> unchanged
 * - append an immutable moderation note citing the rule_pack_version
 *
 * Deterministic for identical inputs and active rule packs; reruns against the same content
 * are idempotent in the sense that each call creates a distinct case snapshot (the decision
 * history must remain append-only per PRD 8.3.8).
 */
final class AutomatedModerator
{
    public function __construct(
        private readonly RuleEvaluator $evaluator,
        private readonly ModerationService $moderation,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<int,string> $provenanceUrls
     * @return array<string,mixed>|null Moderation summary if a case was opened; null when nothing flagged.
     */
    public function moderate(Content $content, int $adLinkCount, array $provenanceUrls = []): ?array
    {
        $versions = RulePackVersion::query()->where('status', 'published')->get()->all();
        if ($versions === []) {
            $this->audit->record('moderation.automated_skipped', 'content', $content->content_id, [
                'reason' => 'no_published_rule_packs',
            ], actorType: 'system', actorId: 'automated_moderation');
            return null;
        }

        $flagsByVersion = [];
        $totalFlags = [];
        $maxSeverity = 'none';

        foreach ($versions as $version) {
            $rules = RulePackRule::query()->where('rule_pack_version_id', (int) $version->id)->get()->all();
            if ($rules === []) {
                continue;
            }
            $flags = $this->evaluator->evaluate($content, $rules, $adLinkCount, $provenanceUrls);
            if ($flags === []) {
                continue;
            }
            $flagsByVersion[(int) $version->id] = $flags;
            foreach ($flags as $flag) {
                $totalFlags[] = $flag;
                $maxSeverity = $this->maxSeverity($maxSeverity, (string) $flag['severity']);
            }
        }

        if ($totalFlags === []) {
            $this->audit->record('moderation.automated_clean', 'content', $content->content_id, [
                'rule_pack_versions_evaluated' => count($versions),
            ], actorType: 'system', actorId: 'automated_moderation');
            return [
                'case_id' => null,
                'flag_count' => 0,
                'new_risk_state' => $content->risk_state,
            ];
        }

        // Open a single case attributed to the first rule pack version that hit. Additional
        // per-version flags are attached to the same case so reviewers see a full picture.
        $firstVersionId = array_key_first($flagsByVersion);
        $case = $this->moderation->createAutomatedCase(
            $content->content_id,
            (int) $firstVersionId,
            $flagsByVersion[$firstVersionId],
            'rule_match',
        );

        foreach ($flagsByVersion as $versionId => $flags) {
            if ($versionId === $firstVersionId) {
                continue;
            }
            foreach ($flags as $flag) {
                ModerationCaseFlag::query()->create([
                    'case_id' => $case->id,
                    'rule_pack_version_id' => $versionId,
                    'rule_id' => $flag['rule_id'] ?? null,
                    'rule_kind' => (string) ($flag['rule_kind'] ?? ''),
                    'evidence_json' => json_encode($flag['evidence'] ?? []),
                    'created_at' => $case->opened_at instanceof \DateTimeInterface
                        ? $case->opened_at->format('Y-m-d H:i:s')
                        : (string) $case->opened_at,
                ]);
            }
        }

        $newRisk = $this->mapSeverityToRisk($maxSeverity, (string) $content->risk_state);
        if ($newRisk !== $content->risk_state) {
            $previous = (string) $content->risk_state;
            $content->risk_state = $newRisk;
            $content->save();
            $this->audit->record('content.risk_state_transition', 'content', $content->content_id, [
                'from' => $previous,
                'to' => $newRisk,
                'reason' => 'automated_moderation',
                'case_id' => $case->id,
            ], actorType: 'system', actorId: 'automated_moderation');
        }

        // Record an immutable automated decision note so reviewers and audit trail both see it.
        ModerationNote::query()->create([
            'case_id' => $case->id,
            'author_user_id' => 0, // 0 sentinel means system
            'note' => sprintf(
                'Automated flag: %d match(es) across %d rule pack version(s). Max severity: %s.',
                count($totalFlags),
                count($flagsByVersion),
                $maxSeverity,
            ),
            'is_private' => false,
            'created_at' => $case->opened_at instanceof \DateTimeInterface
                ? $case->opened_at->format('Y-m-d H:i:s')
                : (string) $case->opened_at,
        ]);

        ModerationDecision::query()->create([
            'case_id' => $case->id,
            'decision' => $maxSeverity === 'critical' ? 'restricted' : ($maxSeverity === 'warning' ? 'escalated' : 'approved'),
            'decision_source' => 'automated',
            'decided_by_user_id' => null,
            'rule_pack_version_id' => (int) $firstVersionId,
            'reason' => 'Automated rule evaluation on ingest.',
            'evidence_json' => json_encode([
                'max_severity' => $maxSeverity,
                'flag_count' => count($totalFlags),
                'rule_pack_versions' => array_keys($flagsByVersion),
            ]),
            'decided_at' => $case->opened_at instanceof \DateTimeInterface
                ? $case->opened_at->format('Y-m-d H:i:s')
                : (string) $case->opened_at,
        ]);

        return [
            'case_id' => $case->id,
            'flag_count' => count($totalFlags),
            'max_severity' => $maxSeverity,
            'rule_pack_versions' => array_keys($flagsByVersion),
            'new_risk_state' => $content->risk_state,
        ];
    }

    private function mapSeverityToRisk(string $severity, string $currentState): string
    {
        // Only transition out of 'normalized' — do not overwrite decisions a human has made
        // (published_safe, restricted, rejected are all terminal for automated moderation).
        if (!in_array($currentState, ['normalized', 'ingested'], true)) {
            return $currentState;
        }
        return match ($severity) {
            'critical' => 'quarantined',
            'warning' => 'flagged',
            default => $currentState,
        };
    }

    private function maxSeverity(string $a, string $b): string
    {
        $order = ['none' => 0, 'info' => 1, 'warning' => 2, 'critical' => 3];
        $ai = $order[$a] ?? 0;
        $bi = $order[$b] ?? 0;
        return $ai >= $bi ? $a : $b;
    }
}
