<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAnalyticsTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('analytics_events', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('occurred_at', 'datetime')
            ->addColumn('received_at', 'datetime')
            ->addColumn('actor_type', 'enum', ['values' => ['user', 'system', 'anonymous']])
            ->addColumn('actor_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('session_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('event_type', 'string', ['limit' => 64])
            ->addColumn('object_type', 'string', ['limit' => 64])
            ->addColumn('object_id', 'string', ['limit' => 128])
            ->addColumn('dwell_seconds', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('idempotency_key', 'string', ['limit' => 128])
            ->addColumn('properties_json', 'text', ['null' => true])
            ->addColumn('role_context', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('language', 'char', ['limit' => 8, 'null' => true])
            ->addColumn('media_source', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('section_tag', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('ip_address_ciphertext', 'string', ['limit' => 512, 'null' => true])
            ->addIndex(['occurred_at'])
            ->addIndex(['event_type', 'occurred_at'])
            ->addIndex(['object_type', 'object_id'])
            ->addIndex(['actor_type', 'actor_id'])
            ->addIndex(['session_id'])
            ->create();

        $this->table('analytics_idempotency_keys', ['id' => false, 'primary_key' => ['idempotency_key']])
            ->addColumn('idempotency_key', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('actor_identity', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('first_seen_at', 'datetime')
            ->addColumn('expires_at', 'datetime')
            ->addColumn('analytics_event_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('status_code', 'integer', ['default' => 201])
            ->addColumn('response_fingerprint', 'char', ['limit' => 64, 'null' => true])
            ->addIndex(['expires_at'])
            ->create();

        $this->table('analytics_rollups', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('rollup_day', 'date')
            ->addColumn('event_type', 'string', ['limit' => 64])
            ->addColumn('dimension_key', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('dimension_value', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('count_value', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('sum_dwell_seconds', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['rollup_day', 'event_type', 'dimension_key', 'dimension_value'], ['unique' => true, 'name' => 'uniq_rollup_slot'])
            ->create();

        $this->table('scheduled_reports', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('key', 'string', ['limit' => 96])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('report_kind', 'string', ['limit' => 64])
            ->addColumn('parameters_json', 'text', ['null' => true])
            ->addColumn('output_format', 'enum', ['values' => ['csv', 'json']])
            ->addColumn('cron_expression', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['key'], ['unique' => true])
            ->create();

        $this->table('generated_reports', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('scheduled_report_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('status', 'enum', ['values' => ['scheduled', 'running', 'completed', 'failed', 'expired']])
            ->addColumn('report_key', 'string', ['limit' => 96])
            ->addColumn('parameters_json', 'text', ['null' => true])
            ->addColumn('started_at', 'datetime', ['null' => true])
            ->addColumn('completed_at', 'datetime', ['null' => true])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('requested_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('row_count', 'integer', ['null' => true])
            ->addColumn('error_reason', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('unmasked', 'boolean', ['default' => false])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['status'])
            ->addIndex(['expires_at'])
            ->addForeignKey('scheduled_report_id', 'scheduled_reports', 'id', ['delete' => 'SET_NULL'])
            ->create();

        $this->table('report_files', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('generated_report_id', 'biginteger', ['signed' => false])
            ->addColumn('relative_path', 'string', ['limit' => 512])
            ->addColumn('checksum_sha256', 'char', ['limit' => 64])
            ->addColumn('size_bytes', 'biginteger', ['signed' => false])
            ->addColumn('format', 'enum', ['values' => ['csv', 'json']])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['generated_report_id'])
            ->addForeignKey('generated_report_id', 'generated_reports', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('local_exports', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('export_kind', 'string', ['limit' => 64])
            ->addColumn('relative_path', 'string', ['limit' => 512])
            ->addColumn('checksum_sha256', 'char', ['limit' => 64])
            ->addColumn('size_bytes', 'biginteger', ['signed' => false])
            ->addColumn('unmasked', 'boolean', ['default' => false])
            ->addColumn('requested_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('expires_at', 'datetime', ['null' => true])
            ->addIndex(['expires_at'])
            ->create();

        $this->table('data_classifications', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('key', 'string', ['limit' => 64])
            ->addColumn('label', 'string', ['limit' => 96])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addIndex(['key'], ['unique' => true])
            ->create();
    }
}
