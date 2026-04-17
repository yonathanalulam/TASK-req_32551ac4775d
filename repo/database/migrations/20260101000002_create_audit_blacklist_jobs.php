<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAuditBlacklistJobs extends AbstractMigration
{
    public function change(): void
    {
        $this->table('audit_logs', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('occurred_at', 'datetime')
            ->addColumn('actor_type', 'enum', ['values' => ['user', 'system', 'scheduled_job']])
            ->addColumn('actor_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('action', 'string', ['limit' => 96])
            ->addColumn('object_type', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('object_id', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('request_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('ip_address_ciphertext', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('payload_json', 'text', ['null' => true])
            ->addColumn('previous_row_hash', 'char', ['limit' => 64, 'null' => true])
            ->addColumn('row_hash', 'char', ['limit' => 64])
            ->addIndex(['occurred_at'])
            ->addIndex(['action'])
            ->addIndex(['object_type', 'object_id'])
            ->addIndex(['actor_type', 'actor_id'])
            ->create();

        $this->table('audit_hash_chain', ['id' => false, 'primary_key' => ['chain_date']])
            ->addColumn('chain_date', 'date', ['null' => false])
            ->addColumn('previous_day_hash', 'char', ['limit' => 64, 'null' => true])
            ->addColumn('first_log_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('last_log_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('row_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('chain_hash', 'char', ['limit' => 64])
            ->addColumn('finalized_at', 'datetime')
            ->addColumn('finalized_by', 'string', ['limit' => 64, 'null' => true])
            ->create();

        $this->table('blacklists', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('entry_type', 'enum', ['values' => ['user', 'content', 'source']])
            ->addColumn('target_key', 'string', ['limit' => 191])
            ->addColumn('reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('revoked_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addIndex(['entry_type', 'target_key', 'revoked_at'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('revoked_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->create();

        $this->table('job_definitions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('key', 'string', ['limit' => 96])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('handler_class', 'string', ['limit' => 191])
            ->addColumn('schedule_cron', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('is_singleton', 'boolean', ['default' => true])
            ->addColumn('is_enabled', 'boolean', ['default' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['key'], ['unique' => true])
            ->create();

        $this->table('job_runs', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('job_key', 'string', ['limit' => 96])
            ->addColumn('status', 'enum', ['values' => ['queued', 'running', 'retry_wait', 'succeeded', 'failed', 'cancelled']])
            ->addColumn('attempt', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('max_attempts', 'integer', ['signed' => false, 'default' => 3])
            ->addColumn('started_at', 'datetime', ['null' => true])
            ->addColumn('ended_at', 'datetime', ['null' => true])
            ->addColumn('queued_at', 'datetime')
            ->addColumn('next_run_at', 'datetime', ['null' => true])
            ->addColumn('actor_identity', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('resume_marker', 'text', ['null' => true])
            ->addColumn('failure_reason', 'text', ['null' => true])
            ->addColumn('payload_json', 'text', ['null' => true])
            ->addIndex(['job_key', 'status'])
            ->addIndex(['next_run_at'])
            ->create();

        $this->table('job_locks', ['id' => false, 'primary_key' => ['lock_key']])
            ->addColumn('lock_key', 'string', ['limit' => 96, 'null' => false])
            ->addColumn('holder', 'string', ['limit' => 128])
            ->addColumn('acquired_at', 'datetime')
            ->addColumn('expires_at', 'datetime')
            ->create();

        $this->table('system_settings', ['id' => false, 'primary_key' => ['setting_key']])
            ->addColumn('setting_key', 'string', ['limit' => 96, 'null' => false])
            ->addColumn('value_type', 'enum', ['values' => ['string', 'int', 'bool', 'json', 'secret']])
            ->addColumn('value_plain', 'text', ['null' => true])
            ->addColumn('value_ciphertext', 'text', ['null' => true])
            ->addColumn('key_version', 'integer', ['null' => true])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->create();

        $this->table('rate_limit_windows', ['id' => false, 'primary_key' => ['bucket_key', 'window_start']])
            ->addColumn('bucket_key', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('window_start', 'datetime', ['null' => false])
            ->addColumn('counter', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('expires_at', 'datetime')
            ->addIndex(['expires_at'])
            ->create();
    }
}
