<?php

declare(strict_types=1);

namespace Meridian\Domain\Blacklist;

use Meridian\Application\Exceptions\AuthorizationException;
use Meridian\Application\Exceptions\ConflictException;
use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserPermissions;
use Meridian\Infrastructure\Clock\Clock;

/**
 * Manages blacklist entries and efficient active-lookup for write-path enforcement.
 */
final class BlacklistService
{
    private const ALLOWED_TYPES = ['user', 'content', 'source'];

    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit)
    {
    }

    public function add(User $actor, string $type, string $targetKey, ?string $reason = null): Blacklist
    {
        if (!UserPermissions::hasPermission($actor, 'governance.manage_blacklists')) {
            throw new AuthorizationException('Missing permission: governance.manage_blacklists');
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new ValidationException('Invalid blacklist entry_type.', ['field' => 'entry_type']);
        }
        $existing = Blacklist::query()
            ->where('entry_type', $type)
            ->where('target_key', $targetKey)
            ->whereNull('revoked_at')
            ->first();
        if ($existing instanceof Blacklist) {
            throw new ConflictException('Target already blacklisted.', 'BLACKLIST_DUPLICATE');
        }
        $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');
        /** @var Blacklist $entry */
        $entry = Blacklist::query()->create([
            'entry_type' => $type,
            'target_key' => mb_substr($targetKey, 0, 191),
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'created_by_user_id' => (int) $actor->id,
            'created_at' => $now,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
        ]);
        $this->audit->record('blacklist.added', 'blacklist', (string) $entry->id, [
            'entry_type' => $type,
            'target_key' => $targetKey,
            'reason' => $reason,
        ], actorType: 'user', actorId: (string) $actor->id);
        return $entry;
    }

    public function revoke(User $actor, int $entryId): void
    {
        if (!UserPermissions::hasPermission($actor, 'governance.manage_blacklists')) {
            throw new AuthorizationException('Missing permission: governance.manage_blacklists');
        }
        /** @var Blacklist|null $entry */
        $entry = Blacklist::query()->find($entryId);
        if (!$entry instanceof Blacklist || $entry->revoked_at !== null) {
            throw new ValidationException('Blacklist entry not active.');
        }
        $entry->revoked_at = $this->clock->nowUtc()->format('Y-m-d H:i:s');
        $entry->revoked_by_user_id = (int) $actor->id;
        $entry->save();
        $this->audit->record('blacklist.revoked', 'blacklist', (string) $entry->id, [
            'entry_type' => $entry->entry_type,
            'target_key' => $entry->target_key,
        ], actorType: 'user', actorId: (string) $actor->id);
    }

    public function isBlacklisted(string $type, string $targetKey): bool
    {
        return Blacklist::query()
            ->where('entry_type', $type)
            ->where('target_key', $targetKey)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * Returns the set of active target_key values for the given entry_type.
     * Used by enforcement paths that need to filter query results in bulk
     * (content search, analytics query exclusion) without N+1 lookups.
     *
     * @return array<int,string>
     */
    public function activeTargetKeys(string $type): array
    {
        return Blacklist::query()
            ->where('entry_type', $type)
            ->whereNull('revoked_at')
            ->pluck('target_key')
            ->map(static fn($v) => (string) $v)
            ->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function listActive(?string $type = null, int $page = 1, int $pageSize = 50): array
    {
        $q = Blacklist::query()->whereNull('revoked_at');
        if ($type !== null) {
            $q->where('entry_type', $type);
        }
        return $q->orderByDesc('created_at')
            ->forPage($page, $pageSize)
            ->get()
            ->toArray();
    }
}
