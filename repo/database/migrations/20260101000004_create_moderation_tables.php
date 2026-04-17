<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateModerationTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('rule_packs', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('key', 'string', ['limit' => 96])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['key'], ['unique' => true])
            ->create();

        $this->table('rule_pack_versions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('rule_pack_id', 'integer', ['signed' => false])
            ->addColumn('version', 'integer', ['signed' => false])
            ->addColumn('status', 'enum', ['values' => ['draft', 'published', 'archived']])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('published_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['rule_pack_id', 'version'], ['unique' => true])
            ->addIndex(['status'])
            ->addForeignKey('rule_pack_id', 'rule_packs', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('rule_pack_rules', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('rule_pack_version_id', 'integer', ['signed' => false])
            ->addColumn('rule_kind', 'enum', ['values' => ['keyword', 'regex', 'banned_domain', 'ad_link_density']])
            ->addColumn('pattern', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('threshold', 'decimal', ['precision' => 8, 'scale' => 4, 'null' => true])
            ->addColumn('severity', 'enum', ['values' => ['info', 'warning', 'critical'], 'default' => 'warning'])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['rule_pack_version_id'])
            ->addForeignKey('rule_pack_version_id', 'rule_pack_versions', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('moderation_cases', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('content_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('source_record_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('case_type', 'enum', ['values' => ['automated_flag', 'user_report', 'manual_submission']])
            ->addColumn('status', 'enum', ['values' => ['open', 'in_review', 'escalated', 'resolved', 'appealed', 'appeal_in_review', 'appeal_upheld', 'appeal_rejected', 'dismissed']])
            ->addColumn('reason_code', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('decision', 'enum', ['values' => ['pending', 'approved', 'rejected', 'restricted', 'escalated'], 'default' => 'pending'])
            ->addColumn('rule_pack_version_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('assigned_reviewer_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('opened_at', 'datetime')
            ->addColumn('sla_due_at', 'datetime')
            ->addColumn('resolved_at', 'datetime', ['null' => true])
            ->addColumn('has_active_appeal', 'boolean', ['default' => false])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['content_id'])
            ->addIndex(['status'])
            ->addIndex(['decision'])
            ->addIndex(['sla_due_at'])
            ->addIndex(['assigned_reviewer_id'])
            ->create();

        $this->table('moderation_case_flags', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('case_id', 'char', ['limit' => 36])
            ->addColumn('rule_pack_version_id', 'integer', ['signed' => false])
            ->addColumn('rule_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('rule_kind', 'string', ['limit' => 32])
            ->addColumn('evidence_json', 'text')
            ->addColumn('created_at', 'datetime')
            ->addIndex(['case_id'])
            ->create();

        $this->table('moderation_notes', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('case_id', 'char', ['limit' => 36])
            ->addColumn('author_user_id', 'biginteger', ['signed' => false])
            ->addColumn('note', 'text')
            ->addColumn('is_private', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['case_id'])
            ->create();

        $this->table('moderation_decisions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('case_id', 'char', ['limit' => 36])
            ->addColumn('decision', 'enum', ['values' => ['approved', 'rejected', 'restricted', 'escalated', 'appeal_upheld', 'appeal_rejected']])
            ->addColumn('decision_source', 'enum', ['values' => ['automated', 'manual']])
            ->addColumn('decided_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('rule_pack_version_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('reason', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('evidence_json', 'text', ['null' => true])
            ->addColumn('decided_at', 'datetime')
            ->addIndex(['case_id'])
            ->create();

        $this->table('moderation_reports', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('case_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('content_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('source_record_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('reporter_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('reporter_type', 'enum', ['values' => ['user', 'system']])
            ->addColumn('reason_code', 'string', ['limit' => 64])
            ->addColumn('details', 'text', ['null' => true])
            ->addColumn('sla_due_at', 'datetime')
            ->addColumn('status', 'enum', ['values' => ['received', 'routed', 'duplicate', 'closed']])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['case_id'])
            ->addIndex(['sla_due_at'])
            ->create();

        $this->table('moderation_appeals', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('case_id', 'char', ['limit' => 36])
            ->addColumn('appellant_user_id', 'biginteger', ['signed' => false])
            ->addColumn('status', 'enum', ['values' => ['submitted', 'in_review', 'upheld', 'rejected', 'withdrawn']])
            ->addColumn('rationale', 'text')
            ->addColumn('submitted_at', 'datetime')
            ->addColumn('resolved_at', 'datetime', ['null' => true])
            ->addColumn('resolved_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('resolution_reason', 'text', ['null' => true])
            ->addIndex(['case_id'])
            ->addIndex(['status'])
            ->create();
    }
}
