<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateEventTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('event_templates', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('key', 'string', ['limit' => 64])
            ->addColumn('template_type', 'enum', ['values' => ['individual', 'team', 'track']])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('default_attempt_limit', 'integer', ['default' => 3])
            ->addColumn('default_checkin_open_minutes_before', 'integer', ['default' => 60])
            ->addColumn('default_late_cutoff_minutes_after', 'integer', ['default' => 10])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['key'], ['unique' => true])
            ->create();

        $this->table('events', ['id' => false, 'primary_key' => ['event_id']])
            ->addColumn('event_id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('event_family_key', 'string', ['limit' => 96])
            ->addColumn('template_id', 'integer', ['signed' => false])
            ->addColumn('active_version_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('created_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['event_family_key'])
            ->addForeignKey('template_id', 'event_templates', 'id', ['delete' => 'RESTRICT'])
            ->create();

        $this->table('event_versions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('event_id', 'char', ['limit' => 36])
            ->addColumn('version', 'integer', ['signed' => false])
            ->addColumn('status', 'enum', ['values' => ['draft', 'published', 'superseded', 'rolled_back', 'cancelled', 'archived']])
            ->addColumn('effective_from', 'datetime', ['null' => true])
            ->addColumn('effective_to', 'datetime', ['null' => true])
            ->addColumn('config_snapshot_json', 'text')
            ->addColumn('draft_updated_at', 'datetime')
            ->addColumn('draft_version_number', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('published_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('supersedes_version_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['event_id', 'version'], ['unique' => true])
            ->addIndex(['status'])
            ->addIndex(['effective_from', 'effective_to'])
            ->addForeignKey('event_id', 'events', 'event_id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('event_rule_sets', ['id' => false, 'primary_key' => ['event_version_id']])
            ->addColumn('event_version_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('attempt_limit', 'integer', ['default' => 3])
            ->addColumn('checkin_open_minutes_before', 'integer', ['default' => 60])
            ->addColumn('late_cutoff_minutes_after', 'integer', ['default' => 10])
            ->addColumn('overrides_json', 'text', ['null' => true])
            ->addForeignKey('event_version_id', 'event_versions', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('event_advancement_rules', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('event_version_id', 'biginteger', ['signed' => false])
            ->addColumn('precedence', 'integer')
            ->addColumn('criterion', 'enum', ['values' => ['explicit_rank', 'score_metric', 'time_metric', 'tie_breaker', 'manual_adjudication']])
            ->addColumn('criterion_config_json', 'text', ['null' => true])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addIndex(['event_version_id', 'precedence'], ['unique' => true])
            ->addForeignKey('event_version_id', 'event_versions', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('event_venues', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('key', 'string', ['limit' => 96])
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('location_description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('capacity', 'integer', ['null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['key'], ['unique' => true])
            ->create();

        $this->table('event_equipment', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('key', 'string', ['limit' => 96])
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('category', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['key'], ['unique' => true])
            ->create();

        $this->table('event_bindings', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('event_version_id', 'biginteger', ['signed' => false])
            ->addColumn('binding_type', 'enum', ['values' => ['venue', 'equipment']])
            ->addColumn('venue_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('equipment_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('quantity', 'integer', ['default' => 1])
            ->addColumn('notes', 'string', ['limit' => 255, 'null' => true])
            ->addIndex(['event_version_id'])
            ->addForeignKey('event_version_id', 'event_versions', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('venue_id', 'event_venues', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('equipment_id', 'event_equipment', 'id', ['delete' => 'RESTRICT'])
            ->create();

        $this->table('event_publications', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('event_id', 'char', ['limit' => 36])
            ->addColumn('event_version_id', 'biginteger', ['signed' => false])
            ->addColumn('action', 'enum', ['values' => ['publish', 'rollback', 'cancel', 'archive']])
            ->addColumn('actor_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('rationale', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['event_id'])
            ->addIndex(['event_version_id'])
            ->create();
    }
}
