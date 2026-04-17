<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Creates the full Meridian schema on the connected SQLite in-memory database.
 *
 * Mirrors the Phinx migrations in shape. MySQL-only features (enums, foreign key actions)
 * are expressed via SQLite-compatible equivalents so integration tests remain offline
 * and fast. Enum columns become strings constrained by service-layer validation.
 */
final class SchemaBuilder
{
    public static function build(): void
    {
        $schema = Capsule::schema();

        // -------- Identity / RBAC -------------------------------------------
        $schema->create('users', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('username', 64)->unique();
            $t->string('password_hash', 255);
            $t->string('display_name', 128)->nullable();
            $t->string('email_ciphertext', 512)->nullable();
            $t->string('status', 32)->default('active');
            $t->dateTime('last_login_at')->nullable();
            $t->dateTime('locked_until')->nullable();
            $t->dateTime('reset_locked_until')->nullable();
            $t->boolean('is_system')->default(false);
            $t->timestamps();
            $t->index('status');
        });

        $schema->create('security_questions', static function (Blueprint $t) {
            $t->increments('id');
            $t->string('prompt', 255)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        $schema->create('user_security_answers', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedInteger('security_question_id');
            $t->string('answer_ciphertext', 1024);
            $t->integer('key_version')->default(1);
            $t->timestamps();
            $t->unique(['user_id', 'security_question_id']);
        });

        $schema->create('roles', static function (Blueprint $t) {
            $t->increments('id');
            $t->string('key', 64)->unique();
            $t->string('label', 128);
            $t->string('description', 255)->nullable();
            $t->timestamps();
        });

        $schema->create('permissions', static function (Blueprint $t) {
            $t->increments('id');
            $t->string('key', 96)->unique();
            $t->string('category', 32);
            $t->string('description', 255)->nullable();
            $t->timestamps();
            $t->index('category');
        });

        $schema->create('permission_groups', static function (Blueprint $t) {
            $t->increments('id');
            $t->string('key', 64)->unique();
            $t->string('description', 255)->nullable();
            $t->timestamps();
        });

        $schema->create('permission_group_members', static function (Blueprint $t) {
            $t->unsignedInteger('permission_group_id');
            $t->unsignedInteger('permission_id');
            $t->primary(['permission_group_id', 'permission_id']);
        });

        $schema->create('role_permissions', static function (Blueprint $t) {
            $t->unsignedInteger('role_id');
            $t->unsignedInteger('permission_id');
            $t->string('effect', 8)->default('allow');
            $t->primary(['role_id', 'permission_id']);
        });

        $schema->create('role_permission_groups', static function (Blueprint $t) {
            $t->unsignedInteger('role_id');
            $t->unsignedInteger('permission_group_id');
            $t->primary(['role_id', 'permission_group_id']);
        });

        $schema->create('user_role_bindings', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedInteger('role_id');
            $t->string('scope_type', 32)->nullable();
            $t->string('scope_ref', 128)->nullable();
            $t->unsignedBigInteger('granted_by_user_id')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'role_id', 'scope_type', 'scope_ref'], 'uniq_user_role_scope');
        });

        $schema->create('user_sessions', static function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->unsignedBigInteger('user_id');
            $t->char('token_hash', 64)->unique();
            $t->dateTime('created_at');
            $t->dateTime('last_seen_at');
            $t->dateTime('absolute_expires_at');
            $t->dateTime('idle_expires_at');
            $t->dateTime('revoked_at')->nullable();
            $t->string('revoke_reason', 64)->nullable();
            $t->string('ip_address_ciphertext', 512)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->index('user_id');
            $t->index('absolute_expires_at');
        });

        $schema->create('login_attempts', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('username', 64);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->boolean('success');
            $t->string('reason', 64)->nullable();
            $t->dateTime('attempted_at');
            $t->index(['username', 'attempted_at']);
        });

        $schema->create('password_reset_attempts', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->boolean('success');
            $t->string('reason', 64)->nullable();
            $t->dateTime('attempted_at');
            $t->index(['user_id', 'attempted_at']);
        });

        $schema->create('password_reset_tickets', static function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->unsignedBigInteger('user_id');
            $t->char('ticket_hash', 64)->unique();
            $t->dateTime('issued_at');
            $t->dateTime('expires_at');
            $t->dateTime('consumed_at')->nullable();
            $t->dateTime('revoked_at')->nullable();
            $t->string('consume_reason', 64)->nullable();
            $t->string('ip_address_ciphertext', 512)->nullable();
            $t->index(['user_id', 'expires_at']);
        });

        // -------- Audit / Blacklist / Jobs ---------------------------------
        $schema->create('audit_logs', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->dateTime('occurred_at');
            $t->string('actor_type', 16);
            $t->string('actor_id', 64)->nullable();
            $t->string('action', 96);
            $t->string('object_type', 64)->nullable();
            $t->string('object_id', 128)->nullable();
            $t->char('request_id', 36)->nullable();
            $t->string('ip_address_ciphertext', 512)->nullable();
            $t->text('payload_json')->nullable();
            $t->char('previous_row_hash', 64)->nullable();
            $t->char('row_hash', 64);
            $t->index('occurred_at');
            $t->index('action');
        });

        $schema->create('audit_hash_chain', static function (Blueprint $t) {
            $t->date('chain_date')->primary();
            $t->char('previous_day_hash', 64)->nullable();
            $t->unsignedBigInteger('first_log_id')->nullable();
            $t->unsignedBigInteger('last_log_id')->nullable();
            $t->unsignedInteger('row_count')->default(0);
            $t->char('chain_hash', 64);
            $t->dateTime('finalized_at');
            $t->string('finalized_by', 64)->nullable();
        });

        $schema->create('blacklists', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('entry_type', 16);
            $t->string('target_key', 191);
            $t->string('reason', 255)->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('revoked_at')->nullable();
            $t->unsignedBigInteger('revoked_by_user_id')->nullable();
            $t->index(['entry_type', 'target_key', 'revoked_at']);
        });

        $schema->create('job_definitions', static function (Blueprint $t) {
            $t->increments('id');
            $t->string('key', 96)->unique();
            $t->string('description', 255)->nullable();
            $t->string('handler_class', 191);
            $t->string('schedule_cron', 64)->nullable();
            $t->boolean('is_singleton')->default(true);
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });

        $schema->create('job_runs', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('job_key', 96);
            $t->string('status', 16);
            $t->unsignedInteger('attempt')->default(1);
            $t->unsignedInteger('max_attempts')->default(3);
            $t->dateTime('started_at')->nullable();
            $t->dateTime('ended_at')->nullable();
            $t->dateTime('queued_at');
            $t->dateTime('next_run_at')->nullable();
            $t->string('actor_identity', 64)->nullable();
            $t->text('resume_marker')->nullable();
            $t->text('failure_reason')->nullable();
            $t->text('payload_json')->nullable();
            $t->index(['job_key', 'status']);
        });

        $schema->create('job_locks', static function (Blueprint $t) {
            $t->string('lock_key', 96)->primary();
            $t->string('holder', 128);
            $t->dateTime('acquired_at');
            $t->dateTime('expires_at');
        });

        $schema->create('system_settings', static function (Blueprint $t) {
            $t->string('setting_key', 96)->primary();
            $t->string('value_type', 16);
            $t->text('value_plain')->nullable();
            $t->text('value_ciphertext')->nullable();
            $t->integer('key_version')->nullable();
            $t->string('description', 255)->nullable();
            $t->timestamps();
        });

        $schema->create('rate_limit_windows', static function (Blueprint $t) {
            $t->string('bucket_key', 191);
            $t->dateTime('window_start');
            $t->unsignedInteger('counter')->default(0);
            $t->dateTime('expires_at');
            $t->primary(['bucket_key', 'window_start']);
        });

        // -------- Content --------------------------------------------------
        $schema->create('content_ingest_requests', static function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->dateTime('received_at');
            $t->string('source_key', 64)->nullable();
            $t->string('source_record_id', 191)->nullable();
            $t->string('idempotency_key', 128)->nullable();
            $t->unsignedBigInteger('submitted_by_user_id')->nullable();
            $t->char('raw_payload_checksum', 64);
            $t->unsignedInteger('raw_payload_bytes');
            $t->string('payload_kind', 16);
            $t->string('status', 16);
            $t->string('error_code', 64)->nullable();
            $t->string('error_message', 512)->nullable();
            $t->char('resulting_content_id', 36)->nullable();
        });

        $schema->create('contents', static function (Blueprint $t) {
            $t->char('content_id', 36)->primary();
            $t->string('title', 191);
            $t->string('title_normalized', 191);
            $t->longText('body');
            $t->char('body_checksum', 64);
            $t->char('language', 8);
            $t->string('author', 180)->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->string('media_source', 16);
            $t->dateTime('published_at')->nullable();
            $t->dateTime('ingested_at');
            $t->string('risk_state', 24)->default('normalized');
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('last_modified_by_user_id')->nullable();
            $t->char('merged_into_content_id', 36)->nullable();
            $t->dateTime('merged_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('risk_state');
            $t->index('language');
            $t->index('merged_into_content_id');
        });

        $schema->create('content_sources', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('source_key', 64);
            $t->string('source_record_id', 191);
            $t->char('content_id', 36);
            $t->string('original_url', 512)->nullable();
            $t->char('original_checksum', 64);
            $t->dateTime('first_seen_at');
            $t->dateTime('last_seen_at');
            $t->boolean('is_active')->default(true);
            $t->unique(['source_key', 'source_record_id'], 'uniq_source_record');
        });

        $schema->create('content_media_refs', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('content_id', 36);
            $t->string('media_type', 8);
            $t->string('local_path', 512)->nullable();
            $t->char('reference_hash', 64)->nullable();
            $t->string('external_url', 512)->nullable();
            $t->string('caption', 255)->nullable();
            $t->integer('order_index')->default(0);
            $t->timestamps();
        });

        $schema->create('content_fingerprints', static function (Blueprint $t) {
            $t->char('content_id', 36)->primary();
            $t->string('title_normalized', 255);
            $t->string('author_normalized', 191)->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->char('simhash_hex', 16)->nullable();
            $t->char('composite_fingerprint', 64);
            $t->unsignedInteger('algorithm_version')->default(1);
            $t->dateTime('updated_at');
        });

        $schema->create('content_merge_history', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('primary_content_id', 36);
            $t->char('secondary_content_id', 36);
            $t->string('action', 8);
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('actor_type', 8);
            $t->string('reason', 255)->nullable();
            $t->text('evidence_json')->nullable();
            $t->dateTime('created_at');
        });

        $schema->create('content_sections', static function (Blueprint $t) {
            $t->char('content_id', 36);
            $t->string('tag_slug', 64);
            $t->primary(['content_id', 'tag_slug']);
        });

        $schema->create('content_body_revisions', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('content_id', 36);
            $t->unsignedInteger('revision_number');
            $t->longText('body');
            $t->char('body_checksum', 64);
            $t->unsignedInteger('normalization_algorithm_version');
            $t->dateTime('created_at');
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unique(['content_id', 'revision_number']);
        });

        $schema->create('dedup_candidates', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('left_content_id', 36);
            $t->char('right_content_id', 36);
            $t->decimal('title_similarity', 5, 4);
            $t->boolean('author_match')->default(false);
            $t->boolean('duration_match')->default(false);
            $t->string('status', 24);
            $t->dateTime('created_at');
            $t->dateTime('reviewed_at')->nullable();
            $t->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $t->unique(['left_content_id', 'right_content_id']);
        });

        // -------- Moderation -----------------------------------------------
        $schema->create('rule_packs', static function (Blueprint $t) {
            $t->increments('id');
            $t->string('key', 96)->unique();
            $t->string('description', 255)->nullable();
            $t->timestamps();
        });

        $schema->create('rule_pack_versions', static function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('rule_pack_id');
            $t->unsignedInteger('version');
            $t->string('status', 16);
            $t->dateTime('published_at')->nullable();
            $t->unsignedBigInteger('published_by_user_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['rule_pack_id', 'version']);
        });

        $schema->create('rule_pack_rules', static function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('rule_pack_version_id');
            $t->string('rule_kind', 24);
            $t->string('pattern', 512)->nullable();
            $t->decimal('threshold', 8, 4)->nullable();
            $t->string('severity', 16)->default('warning');
            $t->string('description', 255)->nullable();
            $t->dateTime('created_at');
        });

        $schema->create('moderation_cases', static function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('content_id', 36)->nullable();
            $t->string('source_record_id', 191)->nullable();
            $t->string('case_type', 24);
            $t->string('status', 24);
            $t->string('reason_code', 64)->nullable();
            $t->string('decision', 16)->default('pending');
            $t->unsignedInteger('rule_pack_version_id')->nullable();
            $t->unsignedBigInteger('assigned_reviewer_id')->nullable();
            $t->dateTime('opened_at');
            $t->dateTime('sla_due_at');
            $t->dateTime('resolved_at')->nullable();
            $t->boolean('has_active_appeal')->default(false);
            $t->timestamps();
            $t->index('status');
            $t->index('decision');
        });

        $schema->create('moderation_case_flags', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('case_id', 36);
            $t->unsignedInteger('rule_pack_version_id');
            $t->unsignedInteger('rule_id')->nullable();
            $t->string('rule_kind', 24);
            $t->text('evidence_json');
            $t->dateTime('created_at');
            $t->index('case_id');
        });

        $schema->create('moderation_notes', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('case_id', 36);
            $t->unsignedBigInteger('author_user_id');
            $t->text('note');
            $t->boolean('is_private')->default(true);
            $t->dateTime('created_at');
            $t->index('case_id');
        });

        $schema->create('moderation_decisions', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('case_id', 36);
            $t->string('decision', 24);
            $t->string('decision_source', 16);
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->unsignedInteger('rule_pack_version_id')->nullable();
            $t->string('reason', 512)->nullable();
            $t->text('evidence_json')->nullable();
            $t->dateTime('decided_at');
            $t->index('case_id');
        });

        $schema->create('moderation_reports', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('case_id', 36)->nullable();
            $t->char('content_id', 36)->nullable();
            $t->string('source_record_id', 191)->nullable();
            $t->unsignedBigInteger('reporter_user_id')->nullable();
            $t->string('reporter_type', 16);
            $t->string('reason_code', 64);
            $t->text('details')->nullable();
            $t->dateTime('sla_due_at');
            $t->string('status', 16);
            $t->dateTime('created_at');
        });

        $schema->create('moderation_appeals', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('case_id', 36);
            $t->unsignedBigInteger('appellant_user_id');
            $t->string('status', 16);
            $t->text('rationale');
            $t->dateTime('submitted_at');
            $t->dateTime('resolved_at')->nullable();
            $t->unsignedBigInteger('resolved_by_user_id')->nullable();
            $t->text('resolution_reason')->nullable();
        });

        // -------- Events ---------------------------------------------------
        $schema->create('event_templates', static function (Blueprint $t) {
            $t->increments('id');
            $t->string('key', 64)->unique();
            $t->string('template_type', 16);
            $t->string('description', 255)->nullable();
            $t->integer('default_attempt_limit')->default(3);
            $t->integer('default_checkin_open_minutes_before')->default(60);
            $t->integer('default_late_cutoff_minutes_after')->default(10);
            $t->timestamps();
        });

        $schema->create('events', static function (Blueprint $t) {
            $t->char('event_id', 36)->primary();
            $t->string('name', 191);
            $t->string('event_family_key', 96);
            $t->unsignedInteger('template_id');
            $t->unsignedBigInteger('active_version_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
        });

        $schema->create('event_versions', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('event_id', 36);
            $t->unsignedInteger('version');
            $t->string('status', 16);
            $t->dateTime('effective_from')->nullable();
            $t->dateTime('effective_to')->nullable();
            $t->text('config_snapshot_json');
            $t->dateTime('draft_updated_at');
            $t->unsignedInteger('draft_version_number')->default(0);
            $t->dateTime('published_at')->nullable();
            $t->unsignedBigInteger('published_by_user_id')->nullable();
            $t->unsignedBigInteger('supersedes_version_id')->nullable();
            $t->timestamps();
            $t->unique(['event_id', 'version']);
        });

        $schema->create('event_rule_sets', static function (Blueprint $t) {
            $t->unsignedBigInteger('event_version_id')->primary();
            $t->integer('attempt_limit')->default(3);
            $t->integer('checkin_open_minutes_before')->default(60);
            $t->integer('late_cutoff_minutes_after')->default(10);
            $t->text('overrides_json')->nullable();
        });

        $schema->create('event_advancement_rules', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('event_version_id');
            $t->integer('precedence');
            $t->string('criterion', 32);
            $t->text('criterion_config_json')->nullable();
            $t->string('description', 255)->nullable();
            $t->unique(['event_version_id', 'precedence']);
        });

        $schema->create('event_venues', static function (Blueprint $t) {
            $t->increments('id');
            $t->string('key', 96)->unique();
            $t->string('name', 191);
            $t->string('location_description', 255)->nullable();
            $t->integer('capacity')->nullable();
            $t->timestamps();
        });

        $schema->create('event_equipment', static function (Blueprint $t) {
            $t->increments('id');
            $t->string('key', 96)->unique();
            $t->string('name', 191);
            $t->string('category', 64)->nullable();
            $t->string('description', 255)->nullable();
            $t->timestamps();
        });

        $schema->create('event_bindings', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('event_version_id');
            $t->string('binding_type', 16);
            $t->unsignedInteger('venue_id')->nullable();
            $t->unsignedInteger('equipment_id')->nullable();
            $t->integer('quantity')->default(1);
            $t->string('notes', 255)->nullable();
        });

        $schema->create('event_publications', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('event_id', 36);
            $t->unsignedBigInteger('event_version_id');
            $t->string('action', 16);
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('rationale', 512)->nullable();
            $t->dateTime('created_at');
        });

        // -------- Analytics / Reports --------------------------------------
        $schema->create('analytics_events', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->dateTime('occurred_at');
            $t->dateTime('received_at');
            $t->string('actor_type', 16);
            $t->string('actor_id', 64)->nullable();
            $t->char('session_id', 36)->nullable();
            $t->string('event_type', 64);
            $t->string('object_type', 64);
            $t->string('object_id', 128);
            $t->unsignedInteger('dwell_seconds')->nullable();
            $t->string('idempotency_key', 128);
            $t->text('properties_json')->nullable();
            $t->string('role_context', 64)->nullable();
            $t->char('language', 8)->nullable();
            $t->string('media_source', 16)->nullable();
            $t->string('section_tag', 64)->nullable();
            $t->string('ip_address_ciphertext', 512)->nullable();
            $t->index('occurred_at');
            $t->index(['event_type', 'occurred_at']);
        });

        $schema->create('analytics_idempotency_keys', static function (Blueprint $t) {
            $t->string('idempotency_key', 128)->primary();
            $t->string('actor_identity', 64)->nullable();
            $t->dateTime('first_seen_at');
            $t->dateTime('expires_at');
            $t->unsignedBigInteger('analytics_event_id')->nullable();
            $t->integer('status_code')->default(201);
            $t->char('response_fingerprint', 64)->nullable();
        });

        $schema->create('analytics_rollups', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->date('rollup_day');
            $t->string('event_type', 64);
            $t->string('dimension_key', 64)->nullable();
            $t->string('dimension_value', 191)->nullable();
            $t->unsignedBigInteger('count_value')->default(0);
            $t->unsignedBigInteger('sum_dwell_seconds')->default(0);
            $t->dateTime('updated_at');
            $t->unique(['rollup_day', 'event_type', 'dimension_key', 'dimension_value'], 'uniq_rollup_slot');
        });

        $schema->create('scheduled_reports', static function (Blueprint $t) {
            $t->increments('id');
            $t->string('key', 96)->unique();
            $t->string('description', 255)->nullable();
            $t->string('report_kind', 64);
            $t->text('parameters_json')->nullable();
            $t->string('output_format', 8);
            $t->string('cron_expression', 64)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
        });

        $schema->create('generated_reports', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('scheduled_report_id')->nullable();
            $t->string('status', 16);
            $t->string('report_key', 96);
            $t->text('parameters_json')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->dateTime('expires_at');
            $t->unsignedBigInteger('requested_by_user_id')->nullable();
            $t->integer('row_count')->nullable();
            $t->string('error_reason', 512)->nullable();
            $t->boolean('unmasked')->default(false);
            $t->timestamps();
        });

        $schema->create('report_files', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('generated_report_id');
            $t->string('relative_path', 512);
            $t->char('checksum_sha256', 64);
            $t->unsignedBigInteger('size_bytes');
            $t->string('format', 8);
            $t->dateTime('created_at');
        });

        $schema->create('local_exports', static function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('export_kind', 64);
            $t->string('relative_path', 512);
            $t->char('checksum_sha256', 64);
            $t->unsignedBigInteger('size_bytes');
            $t->boolean('unmasked')->default(false);
            $t->unsignedBigInteger('requested_by_user_id')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('expires_at')->nullable();
        });

        $schema->create('data_classifications', static function (Blueprint $t) {
            $t->increments('id');
            $t->string('key', 64)->unique();
            $t->string('label', 96);
            $t->string('description', 255)->nullable();
        });
    }
}
