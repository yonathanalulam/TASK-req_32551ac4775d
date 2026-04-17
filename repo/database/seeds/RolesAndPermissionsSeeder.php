<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class RolesAndPermissionsSeeder extends AbstractSeed
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $roles = [
            ['key' => 'learner', 'label' => 'Learner', 'description' => 'Read-only or limited interaction with allowed content and event views.'],
            ['key' => 'instructor', 'label' => 'Instructor', 'description' => 'Manage selected event-related objects and view scoped analytics.'],
            ['key' => 'reviewer', 'label' => 'Reviewer', 'description' => 'Review moderation cases, record decisions, handle appeals.'],
            ['key' => 'administrator', 'label' => 'Administrator', 'description' => 'Full governance, RBAC, rule packs, blacklists, and system settings.'],
            ['key' => 'system', 'label' => 'System', 'description' => 'Non-human automation/identity for trusted ingestion and background jobs.'],
        ];
        $existingRoleKeys = array_column($this->fetchAll('SELECT `key` FROM roles'), 'key');
        $newRoles = [];
        foreach ($roles as $r) {
            if (in_array($r['key'], $existingRoleKeys, true)) {
                continue;
            }
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
            $newRoles[] = $r;
        }
        if ($newRoles !== []) {
            $this->table('roles')->insert($newRoles)->saveData();
        }

        $permissions = [
            ['auth.self_manage', 'auth', 'Manage own password and session.'],
            ['auth.manage_users', 'auth', 'Create/update/disable user accounts.'],
            ['auth.reset_other_password', 'auth', 'Reset other users\' passwords.'],
            ['auth.manage_roles', 'admin', 'Assign and revoke role bindings.'],

            ['content.parse', 'content', 'Submit parsing/ingest requests.'],
            ['content.view', 'content', 'Read trusted content records.'],
            ['content.edit_metadata', 'content', 'Edit normalized content metadata.'],
            ['content.merge', 'content', 'Merge/unmerge content records.'],
            ['content.unmerge', 'content', 'Unmerge content records (admin-only capability).'],
            ['content.blacklist', 'content', 'Blacklist content records.'],
            ['content.language_override', 'content', 'Override low-confidence language detection.'],

            ['moderation.view_cases', 'moderation', 'View moderation case queues.'],
            ['moderation.review', 'moderation', 'Act on moderation cases as a reviewer.'],
            ['moderation.decide', 'moderation', 'Record moderation decisions.'],
            ['moderation.override', 'moderation', 'Override automated moderation decisions.'],
            ['moderation.appeal_resolve', 'moderation', 'Resolve moderation appeals.'],
            ['moderation.view_private_notes', 'moderation', 'Read private moderation notes.'],
            ['moderation.report.create', 'moderation', 'Submit a moderation report.'],
            ['moderation.appeal.create', 'moderation', 'Submit a moderation appeal.'],

            ['rules.draft', 'rules', 'Create draft rule packs.'],
            ['rules.publish', 'rules', 'Publish rule packs.'],
            ['rules.archive', 'rules', 'Archive rule packs.'],

            ['events.draft', 'events', 'Create and edit event drafts.'],
            ['events.publish', 'events', 'Publish immutable event versions.'],
            ['events.rollback', 'events', 'Roll back to a prior event version.'],
            ['events.cancel', 'events', 'Cancel a published event version.'],
            ['events.manage_bindings', 'events', 'Manage venue/equipment bindings.'],

            ['analytics.ingest', 'analytics', 'Ingest analytics events.'],
            ['analytics.query', 'analytics', 'Query analytics and performance views.'],
            ['analytics.view_unmasked', 'analytics', 'View sensitive fields unmasked.'],
            ['analytics.export', 'analytics', 'Export analytics data locally.'],

            ['governance.view_audit', 'governance', 'View audit log summaries.'],
            ['governance.manage_blacklists', 'governance', 'Manage blacklist entries.'],
            ['governance.manage_retention', 'governance', 'Operate retention jobs.'],
            ['governance.export_reports', 'governance', 'Export generated reports.'],

            ['admin.manage_rules', 'admin', 'Full rule pack administration.'],
            ['admin.manage_system_settings', 'admin', 'Change system settings.'],
            ['admin.rotate_keys', 'admin', 'Rotate encryption key metadata.'],
            ['sensitive.unmask', 'admin', 'Request unmasking of sensitive fields (audited).'],
        ];
        // Idempotent seed: migrations may have already inserted some of these permissions
        // (e.g. 20260501000001 backfills moderation.report/appeal.create on existing DBs).
        $existingPerms = array_column($this->fetchAll('SELECT `key` FROM permissions'), 'key');
        $rows = [];
        foreach ($permissions as $p) {
            if (in_array($p[0], $existingPerms, true)) {
                continue;
            }
            $rows[] = [
                'key' => $p[0],
                'category' => $p[1],
                'description' => $p[2],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            $this->table('permissions')->insert($rows)->saveData();
        }

        $roleKeys = $this->fetchAll('SELECT id, `key` FROM roles');
        $permKeys = $this->fetchAll('SELECT id, `key` FROM permissions');
        $roleIdByKey = [];
        foreach ($roleKeys as $row) {
            $roleIdByKey[$row['key']] = (int) $row['id'];
        }
        $permIdByKey = [];
        foreach ($permKeys as $row) {
            $permIdByKey[$row['key']] = (int) $row['id'];
        }

        $assignments = [
            'learner' => [
                'auth.self_manage',
                'content.view',
                'analytics.ingest',
                'moderation.report.create',
                'moderation.appeal.create',
            ],
            'instructor' => [
                'auth.self_manage',
                'content.view',
                'content.parse',
                'content.edit_metadata',
                'events.draft',
                'events.manage_bindings',
                'analytics.ingest',
                'analytics.query',
                'moderation.report.create',
                'moderation.appeal.create',
            ],
            'reviewer' => [
                'auth.self_manage',
                'content.view',
                'content.parse',
                'moderation.view_cases',
                'moderation.review',
                'moderation.decide',
                'moderation.override',
                'moderation.appeal_resolve',
                'moderation.view_private_notes',
                'analytics.ingest',
                'analytics.query',
                'moderation.report.create',
                'moderation.appeal.create',
            ],
            'administrator' => array_values(array_diff(array_keys($permIdByKey), ['analytics.view_unmasked', 'sensitive.unmask'])),
            'system' => [
                'content.parse',
                'analytics.ingest',
                'content.view',
            ],
        ];
        // Refresh the permissions id lookup in case new rows were just inserted above.
        foreach ($this->fetchAll('SELECT id, `key` FROM permissions') as $row) {
            $permIdByKey[$row['key']] = (int) $row['id'];
        }
        $existingBindings = [];
        foreach ($this->fetchAll('SELECT role_id, permission_id FROM role_permissions') as $row) {
            $existingBindings[(int) $row['role_id'] . ':' . (int) $row['permission_id']] = true;
        }
        $rolePerms = [];
        foreach ($assignments as $roleKey => $permKeysList) {
            foreach ($permKeysList as $pk) {
                if (!isset($roleIdByKey[$roleKey]) || !isset($permIdByKey[$pk])) {
                    continue;
                }
                $key = $roleIdByKey[$roleKey] . ':' . $permIdByKey[$pk];
                if (isset($existingBindings[$key])) {
                    continue;
                }
                $existingBindings[$key] = true;
                $rolePerms[] = [
                    'role_id' => $roleIdByKey[$roleKey],
                    'permission_id' => $permIdByKey[$pk],
                    'effect' => 'allow',
                ];
            }
        }
        if ($rolePerms !== []) {
            $this->table('role_permissions')->insert($rolePerms)->saveData();
        }

        $questions = [
            'What was the name of your first pet?',
            'What city were you born in?',
            'What is your favorite teacher\'s last name?',
            'What was your first school\'s name?',
            'What street did you grow up on?',
        ];
        $existingPrompts = array_column($this->fetchAll('SELECT prompt FROM security_questions'), 'prompt');
        $qrows = [];
        foreach ($questions as $q) {
            if (in_array($q, $existingPrompts, true)) {
                continue;
            }
            $qrows[] = ['prompt' => $q, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($qrows !== []) {
            $this->table('security_questions')->insert($qrows)->saveData();
        }

        $templates = [
            ['individual_standard', 'individual', 'Standard individual competition template'],
            ['team_standard', 'team', 'Standard team competition template'],
            ['track_standard', 'track', 'Standard multi-track competition template'],
        ];
        $existingTemplateKeys = array_column($this->fetchAll('SELECT `key` FROM event_templates'), 'key');
        $trows = [];
        foreach ($templates as $t) {
            if (in_array($t[0], $existingTemplateKeys, true)) {
                continue;
            }
            $trows[] = [
                'key' => $t[0],
                'template_type' => $t[1],
                'description' => $t[2],
                'default_attempt_limit' => 3,
                'default_checkin_open_minutes_before' => 60,
                'default_late_cutoff_minutes_after' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($trows !== []) {
            $this->table('event_templates')->insert($trows)->saveData();
        }

        $classes = [
            ['public_internal', 'Public-internal', 'Normal access data such as event templates.'],
            ['restricted', 'Restricted', 'Reviewer/admin-scoped data such as moderation notes.'],
            ['sensitive', 'Sensitive', 'Masked unless scope allows (emails, IPs).'],
            ['secret', 'Secret', 'Never returned (passwords, keys, security answers).'],
        ];
        $existingClassKeys = array_column($this->fetchAll('SELECT `key` FROM data_classifications'), 'key');
        $classes = array_values(array_filter($classes, static fn($c) => !in_array($c[0], $existingClassKeys, true)));
        $crows = array_map(static fn(array $c) => [
            'key' => $c[0],
            'label' => $c[1],
            'description' => $c[2],
        ], $classes);
        if ($crows !== []) {
            $this->table('data_classifications')->insert($crows)->saveData();
        }

        $jobs = [
            ['audit.finalize_daily_chain', 'Seal previous day\'s audit hash chain', 'Meridian\\Domain\\Audit\\Jobs\\FinalizeAuditChainJob', '0 1 * * *'],
            ['reports.retention_cleanup', 'Delete generated reports past 90 days', 'Meridian\\Domain\\Reports\\Jobs\\ReportRetentionJob', '15 2 * * *'],
            ['dedup.recompute_candidates', 'Recompute duplicate candidates', 'Meridian\\Domain\\Dedup\\Jobs\\RecomputeDedupCandidatesJob', '30 3 * * *'],
            ['sessions.expire_cleanup', 'Delete/expire session records', 'Meridian\\Domain\\Auth\\Jobs\\ExpiredSessionsJob', '0 * * * *'],
            ['analytics.idempotency_cleanup', 'Prune expired idempotency keys', 'Meridian\\Domain\\Analytics\\Jobs\\IdempotencyCleanupJob', '5 * * * *'],
            ['analytics.rollups', 'Roll up analytics events into daily aggregates', 'Meridian\\Domain\\Analytics\\Jobs\\RollupJob', '10 * * * *'],
            ['metrics.rotate_logs', 'Rotate structured log/metric files', 'Meridian\\Domain\\Ops\\Jobs\\LogRotationJob', '20 0 * * *'],
            ['metrics.snapshot', 'Write platform aggregate counters to storage/metrics (NDJSON)', 'Meridian\\Domain\\Ops\\Jobs\\MetricsSnapshotJob', '0 * * * *'],
        ];
        $existingJobKeys = array_column($this->fetchAll('SELECT `key` FROM job_definitions'), 'key');
        $jrows = [];
        foreach ($jobs as $j) {
            if (in_array($j[0], $existingJobKeys, true)) {
                continue;
            }
            $jrows[] = [
                'key' => $j[0],
                'description' => $j[1],
                'handler_class' => $j[2],
                'schedule_cron' => $j[3],
                'is_singleton' => 1,
                'is_enabled' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($jrows !== []) {
            $this->table('job_definitions')->insert($jrows)->saveData();
        }
    }
}
