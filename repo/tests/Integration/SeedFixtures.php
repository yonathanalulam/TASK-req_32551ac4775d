<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration;

use Illuminate\Database\Capsule\Manager as DB;
use Meridian\Domain\Auth\PasswordHasher;
use Meridian\Domain\Auth\Role;

/**
 * Seeds the minimal permission/role set + default admin + default event templates for
 * integration tests. Mirrors database/seeds/RolesAndPermissionsSeeder.
 */
final class SeedFixtures
{
    public static function run(PasswordHasher $hasher): void
    {
        $now = date('Y-m-d H:i:s');

        $roles = [
            ['key' => 'learner', 'label' => 'Learner'],
            ['key' => 'instructor', 'label' => 'Instructor'],
            ['key' => 'reviewer', 'label' => 'Reviewer'],
            ['key' => 'administrator', 'label' => 'Administrator'],
            ['key' => 'system', 'label' => 'System'],
        ];
        foreach ($roles as &$r) {
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
        }
        unset($r);
        DB::table('roles')->insert($roles);

        $permissions = [
            'auth.self_manage' => 'auth',
            'auth.manage_users' => 'auth',
            'auth.reset_other_password' => 'auth',
            'auth.manage_roles' => 'admin',
            'content.parse' => 'content',
            'content.view' => 'content',
            'content.edit_metadata' => 'content',
            'content.merge' => 'content',
            'content.unmerge' => 'content',
            'content.blacklist' => 'content',
            'content.language_override' => 'content',
            'moderation.view_cases' => 'moderation',
            'moderation.review' => 'moderation',
            'moderation.decide' => 'moderation',
            'moderation.override' => 'moderation',
            'moderation.appeal_resolve' => 'moderation',
            'moderation.view_private_notes' => 'moderation',
            'moderation.report.create' => 'moderation',
            'moderation.appeal.create' => 'moderation',
            'rules.draft' => 'rules',
            'rules.publish' => 'rules',
            'rules.archive' => 'rules',
            'events.draft' => 'events',
            'events.publish' => 'events',
            'events.rollback' => 'events',
            'events.cancel' => 'events',
            'events.manage_bindings' => 'events',
            'analytics.ingest' => 'analytics',
            'analytics.query' => 'analytics',
            'analytics.view_unmasked' => 'analytics',
            'analytics.export' => 'analytics',
            'governance.view_audit' => 'governance',
            'governance.manage_blacklists' => 'governance',
            'governance.manage_retention' => 'governance',
            'governance.export_reports' => 'governance',
            'admin.manage_rules' => 'admin',
            'admin.manage_system_settings' => 'admin',
            'admin.rotate_keys' => 'admin',
            'sensitive.unmask' => 'admin',
        ];
        $permRows = [];
        foreach ($permissions as $key => $cat) {
            $permRows[] = [
                'key' => $key,
                'category' => $cat,
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('permissions')->insert($permRows);

        $roleIdByKey = [];
        foreach (DB::table('roles')->get(['id', 'key']) as $row) {
            $roleIdByKey[$row->key] = (int) $row->id;
        }
        $permIdByKey = [];
        foreach (DB::table('permissions')->get(['id', 'key']) as $row) {
            $permIdByKey[$row->key] = (int) $row->id;
        }

        $assignments = [
            'learner' => [
                'auth.self_manage', 'content.view', 'analytics.ingest',
                'moderation.report.create', 'moderation.appeal.create',
            ],
            'instructor' => [
                'auth.self_manage', 'content.view', 'content.parse', 'content.edit_metadata',
                'events.draft', 'events.manage_bindings', 'analytics.ingest', 'analytics.query',
                'moderation.report.create', 'moderation.appeal.create',
            ],
            'reviewer' => [
                'auth.self_manage', 'content.view', 'content.parse',
                'moderation.view_cases', 'moderation.review', 'moderation.decide',
                'moderation.override', 'moderation.appeal_resolve', 'moderation.view_private_notes',
                'analytics.ingest', 'analytics.query',
                'moderation.report.create', 'moderation.appeal.create',
            ],
            'administrator' => array_values(array_diff(array_keys($permIdByKey), ['analytics.view_unmasked', 'sensitive.unmask'])),
            'system' => ['content.parse', 'analytics.ingest', 'content.view'],
        ];

        $rolePerms = [];
        foreach ($assignments as $rk => $perms) {
            foreach ($perms as $pk) {
                if (isset($roleIdByKey[$rk], $permIdByKey[$pk])) {
                    $rolePerms[] = [
                        'role_id' => $roleIdByKey[$rk],
                        'permission_id' => $permIdByKey[$pk],
                        'effect' => 'allow',
                    ];
                }
            }
        }
        if ($rolePerms !== []) {
            DB::table('role_permissions')->insert($rolePerms);
        }

        DB::table('security_questions')->insert([
            ['prompt' => 'First pet', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['prompt' => 'Birth city', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('event_templates')->insert([[
            'key' => 'individual_standard',
            'template_type' => 'individual',
            'description' => 'Individual template',
            'default_attempt_limit' => 3,
            'default_checkin_open_minutes_before' => 60,
            'default_late_cutoff_minutes_after' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);
    }
}
