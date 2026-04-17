<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Idempotently introduces the two moderation write capabilities that were previously
 * granted implicitly through authentication-only handlers:
 *
 *   - moderation.report.create : submit a moderation_report
 *   - moderation.appeal.create : submit a moderation_appeal
 *
 * Existing databases that were seeded before this migration inherit the new permissions
 * and the role bindings below without requiring a full re-seed.
 *
 * Role bindings:
 *   learner        -> moderation.report.create, moderation.appeal.create
 *   instructor     -> moderation.report.create, moderation.appeal.create
 *   reviewer       -> moderation.report.create, moderation.appeal.create
 *   administrator  -> (both, via the administrator-has-all pattern replicated here)
 */
final class AddModerationWritePermissions extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $permissions = [
            ['key' => 'moderation.report.create', 'category' => 'moderation', 'description' => 'Submit a moderation report.'],
            ['key' => 'moderation.appeal.create', 'category' => 'moderation', 'description' => 'Submit a moderation appeal.'],
        ];
        foreach ($permissions as $perm) {
            $existing = $this->fetchRow('SELECT id FROM permissions WHERE `key` = ' . $this->quote($perm['key']));
            if ($existing === false) {
                $this->execute(sprintf(
                    "INSERT INTO permissions (`key`, category, description, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)",
                    $this->quote($perm['key']),
                    $this->quote($perm['category']),
                    $this->quote($perm['description']),
                    $this->quote($now),
                    $this->quote($now),
                ));
            }
        }

        $permIdByKey = [];
        foreach ($this->fetchAll('SELECT id, `key` FROM permissions') as $row) {
            $permIdByKey[$row['key']] = (int) $row['id'];
        }
        $roleIdByKey = [];
        foreach ($this->fetchAll('SELECT id, `key` FROM roles') as $row) {
            $roleIdByKey[$row['key']] = (int) $row['id'];
        }

        $roleToPermKeys = [
            'learner' => ['moderation.report.create', 'moderation.appeal.create'],
            'instructor' => ['moderation.report.create', 'moderation.appeal.create'],
            'reviewer' => ['moderation.report.create', 'moderation.appeal.create'],
            'administrator' => ['moderation.report.create', 'moderation.appeal.create'],
        ];

        foreach ($roleToPermKeys as $roleKey => $permKeys) {
            if (!isset($roleIdByKey[$roleKey])) {
                continue;
            }
            foreach ($permKeys as $pk) {
                if (!isset($permIdByKey[$pk])) {
                    continue;
                }
                $rid = $roleIdByKey[$roleKey];
                $pid = $permIdByKey[$pk];
                $existing = $this->fetchRow(
                    "SELECT role_id FROM role_permissions WHERE role_id = {$rid} AND permission_id = {$pid}",
                );
                if ($existing === false) {
                    $this->execute(
                        "INSERT INTO role_permissions (role_id, permission_id, effect) VALUES ({$rid}, {$pid}, 'allow')",
                    );
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['moderation.report.create', 'moderation.appeal.create'] as $key) {
            $row = $this->fetchRow('SELECT id FROM permissions WHERE `key` = ' . $this->quote($key));
            if ($row === false) {
                continue;
            }
            $id = (int) $row['id'];
            $this->execute("DELETE FROM role_permissions WHERE permission_id = {$id}");
            $this->execute("DELETE FROM permissions WHERE id = {$id}");
        }
    }

    private function quote(string $value): string
    {
        return $this->getAdapter()->getConnection()->quote($value);
    }
}
