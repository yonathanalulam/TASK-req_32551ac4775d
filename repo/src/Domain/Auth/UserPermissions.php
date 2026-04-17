<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

/**
 * Permission resolution for a user. Unions allow permissions from all bound roles
 * and subtracts any deny rows. Cached per-request in a local static map.
 */
final class UserPermissions
{
    /** @var array<int,array<string,bool>> */
    private static array $cache = [];

    /** @return array<string,bool> keyed by permission key; value is the effective grant */
    public static function effective(User $user): array
    {
        $uid = (int) $user->id;
        if (isset(self::$cache[$uid])) {
            return self::$cache[$uid];
        }

        $roleBindings = UserRoleBinding::query()
            ->where('user_id', $uid)
            ->get();
        $roleIds = $roleBindings->pluck('role_id')->unique()->values()->all();

        if ($roleIds === []) {
            return self::$cache[$uid] = [];
        }

        $rolePerms = \Illuminate\Database\Capsule\Manager::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereIn('role_permissions.role_id', $roleIds)
            ->select('permissions.key as pkey', 'role_permissions.effect as effect')
            ->get();

        $groupPerms = \Illuminate\Database\Capsule\Manager::table('role_permission_groups')
            ->join('permission_group_members', 'permission_group_members.permission_group_id', '=', 'role_permission_groups.permission_group_id')
            ->join('permissions', 'permissions.id', '=', 'permission_group_members.permission_id')
            ->whereIn('role_permission_groups.role_id', $roleIds)
            ->select('permissions.key as pkey')
            ->get();

        $effective = [];
        foreach ($groupPerms as $row) {
            $effective[$row->pkey] = true;
        }
        foreach ($rolePerms as $row) {
            if ($row->effect === 'allow') {
                if (!isset($effective[$row->pkey])) {
                    $effective[$row->pkey] = true;
                }
            } else {
                $effective[$row->pkey] = false;
            }
        }
        return self::$cache[$uid] = $effective;
    }

    public static function hasPermission(User $user, string $permissionKey): bool
    {
        $perms = self::effective($user);
        return isset($perms[$permissionKey]) && $perms[$permissionKey] === true;
    }

    public static function clearCacheForUser(int $userId): void
    {
        unset(self::$cache[$userId]);
    }

    public static function resetCache(): void
    {
        self::$cache = [];
    }

    /** Returns whether user has any of the given role keys. */
    public static function hasRole(User $user, string ...$roleKeys): bool
    {
        $rows = \Illuminate\Database\Capsule\Manager::table('user_role_bindings')
            ->join('roles', 'roles.id', '=', 'user_role_bindings.role_id')
            ->where('user_role_bindings.user_id', (int) $user->id)
            ->whereIn('roles.key', $roleKeys)
            ->count();
        return $rows > 0;
    }
}
