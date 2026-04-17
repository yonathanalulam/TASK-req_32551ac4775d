<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateContentTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('content_ingest_requests', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('received_at', 'datetime')
            ->addColumn('source_key', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('source_record_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('idempotency_key', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('submitted_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('raw_payload_checksum', 'char', ['limit' => 64])
            ->addColumn('raw_payload_bytes', 'integer', ['signed' => false])
            ->addColumn('payload_kind', 'enum', ['values' => ['html', 'plain_text']])
            ->addColumn('status', 'enum', ['values' => ['received', 'normalized', 'rejected', 'duplicate']])
            ->addColumn('error_code', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('error_message', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('resulting_content_id', 'char', ['limit' => 36, 'null' => true])
            ->addIndex(['source_key', 'source_record_id'])
            ->addIndex(['idempotency_key'])
            ->addIndex(['received_at'])
            ->create();

        $this->table('contents', ['id' => false, 'primary_key' => ['content_id']])
            ->addColumn('content_id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 191])
            ->addColumn('title_normalized', 'string', ['limit' => 191])
            ->addColumn('body', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG])
            ->addColumn('body_checksum', 'char', ['limit' => 64])
            ->addColumn('language', 'char', ['limit' => 8])
            ->addColumn('author', 'string', ['limit' => 180, 'null' => true])
            ->addColumn('duration_seconds', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('media_source', 'enum', ['values' => ['article', 'image', 'video', 'mixed', 'unknown']])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('ingested_at', 'datetime')
            ->addColumn('risk_state', 'enum', [
                'values' => ['ingested', 'normalized', 'flagged', 'under_review', 'escalated', 'published_safe', 'restricted', 'quarantined', 'rejected'],
                'default' => 'normalized',
            ])
            ->addColumn('created_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('last_modified_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('merged_into_content_id', 'char', ['limit' => 36, 'null' => true])
            ->addColumn('merged_at', 'datetime', ['null' => true])
            ->addColumn('version', 'integer', ['signed' => false, 'default' => 1])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['published_at'])
            ->addIndex(['risk_state'])
            ->addIndex(['language'])
            ->addIndex(['media_source'])
            ->addIndex(['title_normalized'])
            ->addIndex(['merged_into_content_id'])
            ->create();

        $this->table('content_sources', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('source_key', 'string', ['limit' => 64])
            ->addColumn('source_record_id', 'string', ['limit' => 191])
            ->addColumn('content_id', 'char', ['limit' => 36])
            ->addColumn('original_url', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('original_checksum', 'char', ['limit' => 64])
            ->addColumn('first_seen_at', 'datetime')
            ->addColumn('last_seen_at', 'datetime')
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addIndex(['source_key', 'source_record_id'], ['unique' => true, 'name' => 'uniq_source_record'])
            ->addIndex(['content_id'])
            ->addForeignKey('content_id', 'contents', 'content_id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('content_media_refs', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('content_id', 'char', ['limit' => 36])
            ->addColumn('media_type', 'enum', ['values' => ['image', 'video', 'audio', 'other']])
            ->addColumn('local_path', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('reference_hash', 'char', ['limit' => 64, 'null' => true])
            ->addColumn('external_url', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('caption', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('order_index', 'integer', ['default' => 0])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['content_id'])
            ->addForeignKey('content_id', 'contents', 'content_id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('content_fingerprints', ['id' => false, 'primary_key' => ['content_id']])
            ->addColumn('content_id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('title_normalized', 'string', ['limit' => 255])
            ->addColumn('author_normalized', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('duration_seconds', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('simhash_hex', 'char', ['limit' => 16, 'null' => true])
            ->addColumn('composite_fingerprint', 'char', ['limit' => 64])
            ->addColumn('algorithm_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['composite_fingerprint'])
            ->addIndex(['title_normalized'])
            ->addForeignKey('content_id', 'contents', 'content_id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('content_merge_history', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('primary_content_id', 'char', ['limit' => 36])
            ->addColumn('secondary_content_id', 'char', ['limit' => 36])
            ->addColumn('action', 'enum', ['values' => ['merge', 'unmerge']])
            ->addColumn('actor_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('actor_type', 'enum', ['values' => ['user', 'system']])
            ->addColumn('reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('evidence_json', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['primary_content_id'])
            ->addIndex(['secondary_content_id'])
            ->create();

        $this->table('content_sections', ['id' => false, 'primary_key' => ['content_id', 'tag_slug']])
            ->addColumn('content_id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('tag_slug', 'string', ['limit' => 64, 'null' => false])
            ->addForeignKey('content_id', 'contents', 'content_id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('content_body_revisions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('content_id', 'char', ['limit' => 36])
            ->addColumn('revision_number', 'integer', ['signed' => false])
            ->addColumn('body', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG])
            ->addColumn('body_checksum', 'char', ['limit' => 64])
            ->addColumn('normalization_algorithm_version', 'integer', ['signed' => false])
            ->addColumn('created_at', 'datetime')
            ->addColumn('created_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addIndex(['content_id', 'revision_number'], ['unique' => true])
            ->addForeignKey('content_id', 'contents', 'content_id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('dedup_candidates', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('left_content_id', 'char', ['limit' => 36])
            ->addColumn('right_content_id', 'char', ['limit' => 36])
            ->addColumn('title_similarity', 'decimal', ['precision' => 5, 'scale' => 4])
            ->addColumn('author_match', 'boolean', ['default' => false])
            ->addColumn('duration_match', 'boolean', ['default' => false])
            ->addColumn('status', 'enum', ['values' => ['pending_review', 'auto_mergeable', 'reviewed_merged', 'reviewed_rejected']])
            ->addColumn('created_at', 'datetime')
            ->addColumn('reviewed_at', 'datetime', ['null' => true])
            ->addColumn('reviewed_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addIndex(['left_content_id', 'right_content_id'], ['unique' => true])
            ->addIndex(['status'])
            ->create();
    }
}
