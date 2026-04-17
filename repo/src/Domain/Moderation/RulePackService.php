<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\ConflictException;
use Meridian\Application\Exceptions\NotFoundException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Infrastructure\Clock\Clock;

/**
 * Rule pack lifecycle: draft -> published (immutable) -> archived.
 *
 * Once a version is published, its rules may not be added/edited/removed.
 * Archive is a state change only; published rules remain retrievable for audit of past decisions.
 */
final class RulePackService
{
    private const ALLOWED_KINDS = ['keyword', 'regex', 'banned_domain', 'ad_link_density'];

    public function __construct(
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    public function createPack(User $actor, string $key, ?string $description = null): RulePack
    {
        $this->requireAdminOrPerm($actor, 'admin.manage_rules');
        if (!preg_match('/^[a-z0-9][a-z0-9._\-]{2,95}$/', $key)) {
            throw new ValidationException('Invalid rule pack key.', ['field' => 'key']);
        }
        if (RulePack::query()->where('key', $key)->exists()) {
            throw new ConflictException('Rule pack already exists.', 'RULE_PACK_EXISTS');
        }
        /** @var RulePack $pack */
        $pack = RulePack::query()->create(['key' => $key, 'description' => $description]);
        $this->audit->record('rules.pack_created', 'rule_pack', (string) $pack->id, ['key' => $key], actorType: 'user', actorId: (string) $actor->id);
        return $pack;
    }

    public function createDraftVersion(User $actor, int $packId, ?string $notes = null): RulePackVersion
    {
        $this->requireAdminOrPerm($actor, 'rules.draft');
        $pack = RulePack::query()->find($packId);
        if (!$pack instanceof RulePack) {
            throw new NotFoundException('Rule pack not found.');
        }
        $nextVersion = (int) RulePackVersion::query()->where('rule_pack_id', $packId)->max('version') + 1;
        /** @var RulePackVersion $v */
        $v = RulePackVersion::query()->create([
            'rule_pack_id' => $packId,
            'version' => $nextVersion,
            'status' => 'draft',
            'notes' => $notes,
        ]);
        $this->audit->record('rules.version_drafted', 'rule_pack_version', (string) $v->id, [
            'rule_pack_id' => $packId, 'version' => $nextVersion,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $v;
    }

    public function addRule(User $actor, int $versionId, array $input): RulePackRule
    {
        $this->requireAdminOrPerm($actor, 'rules.draft');
        $v = RulePackVersion::query()->find($versionId);
        if (!$v instanceof RulePackVersion) {
            throw new NotFoundException('Version not found.');
        }
        if ($v->status !== 'draft') {
            throw new ConflictException('Only draft versions can be edited.', 'VERSION_NOT_DRAFT');
        }
        $kind = (string) ($input['rule_kind'] ?? '');
        if (!in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new ValidationException('Invalid rule_kind.', ['field' => 'rule_kind', 'allowed' => self::ALLOWED_KINDS]);
        }
        $pattern = isset($input['pattern']) ? (string) $input['pattern'] : null;
        $threshold = isset($input['threshold']) ? (float) $input['threshold'] : null;

        if ($kind === 'regex' && $pattern !== null) {
            if (@preg_match($pattern, '') === false) {
                throw new ValidationException('Invalid regex pattern.', ['field' => 'pattern']);
            }
        }
        if (($kind === 'keyword' || $kind === 'banned_domain') && ($pattern === null || $pattern === '')) {
            throw new ValidationException('pattern is required.', ['field' => 'pattern']);
        }
        if ($kind === 'ad_link_density' && $threshold === null) {
            throw new ValidationException('threshold is required for ad_link_density.', ['field' => 'threshold']);
        }

        /** @var RulePackRule $rule */
        $rule = RulePackRule::query()->create([
            'rule_pack_version_id' => $versionId,
            'rule_kind' => $kind,
            'pattern' => $pattern !== null ? mb_substr($pattern, 0, 512) : null,
            'threshold' => $threshold,
            'severity' => in_array(($input['severity'] ?? null), ['info', 'warning', 'critical'], true) ? (string) $input['severity'] : 'warning',
            'description' => isset($input['description']) ? mb_substr((string) $input['description'], 0, 255) : null,
            'created_at' => $this->clock->nowUtc()->format('Y-m-d H:i:s'),
        ]);
        $this->audit->record('rules.rule_added', 'rule_pack_version', (string) $versionId, [
            'rule_id' => $rule->id,
            'rule_kind' => $kind,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $rule;
    }

    public function publishVersion(User $actor, int $versionId): RulePackVersion
    {
        $this->requireAdminOrPerm($actor, 'rules.publish');
        return DB::connection()->transaction(function () use ($actor, $versionId) {
            $v = RulePackVersion::query()->find($versionId);
            if (!$v instanceof RulePackVersion) {
                throw new NotFoundException('Version not found.');
            }
            if ($v->status !== 'draft') {
                throw new ConflictException('Only draft versions can be published.', 'VERSION_NOT_DRAFT');
            }
            $ruleCount = RulePackRule::query()->where('rule_pack_version_id', $versionId)->count();
            if ($ruleCount === 0) {
                throw new ValidationException('Cannot publish an empty version.');
            }
            $v->status = 'published';
            $v->published_at = $this->clock->nowUtc()->format('Y-m-d H:i:s');
            $v->published_by_user_id = (int) $actor->id;
            $v->save();
            $this->audit->record('rules.version_published', 'rule_pack_version', (string) $versionId, [
                'rule_pack_id' => $v->rule_pack_id,
                'version' => $v->version,
                'rule_count' => $ruleCount,
            ], actorType: 'user', actorId: (string) $actor->id);
            return $v;
        });
    }

    public function archiveVersion(User $actor, int $versionId): RulePackVersion
    {
        $this->requireAdminOrPerm($actor, 'rules.archive');
        $v = RulePackVersion::query()->find($versionId);
        if (!$v instanceof RulePackVersion) {
            throw new NotFoundException('Version not found.');
        }
        if ($v->status === 'archived') {
            return $v;
        }
        $v->status = 'archived';
        $v->save();
        $this->audit->record('rules.version_archived', 'rule_pack_version', (string) $versionId, [
            'rule_pack_id' => $v->rule_pack_id,
            'version' => $v->version,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $v;
    }

    /** @return array<int,RulePackVersion> All currently published versions across packs. */
    public function allPublishedVersions(): array
    {
        return RulePackVersion::query()->where('status', 'published')->get()->all();
    }

    private function requireAdminOrPerm(User $actor, string $perm): void
    {
        if (UserPermissions::hasPermission($actor, $perm) || UserPermissions::hasRole($actor, 'administrator')) {
            return;
        }
        throw new AuthorizationException('Missing permission: ' . $perm);
    }
}
