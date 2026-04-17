<?php

declare(strict_types=1);

use Meridian\Domain\Audit\AuditLogger;
use Meridian\Domain\Auth\PasswordHasher;
use Meridian\Domain\Auth\Role;
use Meridian\Domain\Auth\User;
use Meridian\Domain\Auth\UserRoleBinding;

$state = require __DIR__ . '/_bootstrap.php';
/** @var DI\Container $container */
$container = $state['container'];

$username = $_ENV['BOOTSTRAP_ADMIN_USERNAME'] ?? 'admin';
$password = $_ENV['BOOTSTRAP_ADMIN_PASSWORD'] ?? null;
if ($password === null || $password === '') {
    fwrite(STDERR, "BOOTSTRAP_ADMIN_PASSWORD must be set in env.\n");
    exit(1);
}

$existing = User::query()->where('username', $username)->first();
if ($existing instanceof User) {
    fwrite(STDOUT, "Admin user '{$username}' already exists (id={$existing->id}).\n");
    exit(0);
}

/** @var PasswordHasher $hasher */
$hasher = $container->get(PasswordHasher::class);
/** @var AuditLogger $audit */
$audit = $container->get(AuditLogger::class);

/** @var User $user */
$user = User::query()->create([
    'username' => $username,
    'password_hash' => $hasher->hash((string) $password),
    'display_name' => 'Bootstrap Administrator',
    'status' => 'active',
    'is_system' => false,
]);

$adminRole = Role::query()->where('key', 'administrator')->firstOrFail();
UserRoleBinding::query()->create([
    'user_id' => $user->id,
    'role_id' => $adminRole->id,
    'scope_type' => null,
    'scope_ref' => null,
]);

$audit->record('auth.bootstrap_admin_created', 'user', (string) $user->id, [
    'username' => $username,
], 'system', 'bootstrap');

fwrite(STDOUT, "Created admin user '{$username}' (id={$user->id}).\n");
