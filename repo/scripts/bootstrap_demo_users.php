<?php

declare(strict_types=1);

/**
 * Provisions one deterministic demo account per business role so the README can advertise
 * real credentials. Re-running the script is safe: existing accounts are left alone (status
 * and password hash are never overwritten).
 *
 * Role lineup:
 *   - demo_learner       (learner role)
 *   - demo_instructor    (instructor role)
 *   - demo_reviewer      (reviewer role)
 *   - demo_admin         (administrator role)
 *
 * All accounts share the default password `DemoPass!2026` (documented in README.md).
 * Operators should override via `DEMO_USER_PASSWORD` env before running in any shared
 * environment.
 */

use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Auth\PasswordHasher;
use Meridian\Domain\Auth\Role;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserRoleBinding;
use Meridian\Infrastructure\Crypto\Cipher;

$state = require __DIR__ . '/_bootstrap.php';
/** @var DI\Container $container */
$container = $state['container'];

/** @var PasswordHasher $hasher */
$hasher = $container->get(PasswordHasher::class);
/** @var Cipher $cipher */
$cipher = $container->get(Cipher::class);
/** @var AuditLogger $audit */
$audit = $container->get(AuditLogger::class);

$password = (string) ($_ENV['DEMO_USER_PASSWORD'] ?? 'DemoPass!2026');

$accounts = [
    ['username' => 'demo_learner', 'role' => 'learner', 'display' => 'Demo Learner'],
    ['username' => 'demo_instructor', 'role' => 'instructor', 'display' => 'Demo Instructor'],
    ['username' => 'demo_reviewer', 'role' => 'reviewer', 'display' => 'Demo Reviewer'],
    ['username' => 'demo_admin', 'role' => 'administrator', 'display' => 'Demo Administrator'],
];

$created = [];
$skipped = [];

foreach ($accounts as $spec) {
    $existing = User::query()->where('username', $spec['username'])->first();
    if ($existing instanceof User) {
        $skipped[] = $spec['username'];
        continue;
    }
    $role = Role::query()->where('key', $spec['role'])->first();
    if (!$role instanceof Role) {
        fwrite(STDERR, "Missing role '{$spec['role']}'. Run composer seed first.\n");
        exit(1);
    }
    /** @var User $user */
    $user = User::query()->create([
        'username' => $spec['username'],
        'password_hash' => $hasher->hash($password),
        'display_name' => $spec['display'],
        'status' => 'active',
        'is_system' => false,
    ]);
    UserRoleBinding::query()->create([
        'user_id' => (int) $user->id,
        'role_id' => (int) $role->id,
        'scope_type' => null,
        'scope_ref' => null,
    ]);
    $audit->record('auth.demo_user_provisioned', 'user', (string) $user->id, [
        'username' => $spec['username'],
        'role' => $spec['role'],
    ], 'system', 'bootstrap_demo_users');
    $created[] = $spec['username'];
}

fwrite(STDOUT, sprintf(
    "Demo users created: %s\nSkipped (already existed): %s\nPassword: %s (override with DEMO_USER_PASSWORD env).\n",
    $created === [] ? 'none' : implode(', ', $created),
    $skipped === [] ? 'none' : implode(', ', $skipped),
    $password,
));
