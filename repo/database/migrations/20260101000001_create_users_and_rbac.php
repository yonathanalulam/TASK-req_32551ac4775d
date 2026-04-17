<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUsersAndRbac extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('username', 'string', ['limit' => 64])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            ->addColumn('display_name', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('email_ciphertext', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('status', 'enum', [
                'values' => ['pending_activation', 'active', 'locked', 'password_reset_required', 'disabled'],
                'default' => 'active',
            ])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('locked_until', 'datetime', ['null' => true])
            ->addColumn('reset_locked_until', 'datetime', ['null' => true])
            ->addColumn('is_system', 'boolean', ['default' => false])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['username'], ['unique' => true])
            ->addIndex(['status'])
            ->create();

        $this->table('security_questions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('prompt', 'string', ['limit' => 255])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['prompt'], ['unique' => true])
            ->create();

        $this->table('user_security_answers', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('security_question_id', 'integer', ['signed' => false])
            ->addColumn('answer_ciphertext', 'string', ['limit' => 1024])
            ->addColumn('key_version', 'integer', ['default' => 1])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['user_id', 'security_question_id'], ['unique' => true])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('security_question_id', 'security_questions', 'id', ['delete' => 'RESTRICT'])
            ->create();

        $this->table('roles', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('key', 'string', ['limit' => 64])
            ->addColumn('label', 'string', ['limit' => 128])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['key'], ['unique' => true])
            ->create();

        $this->table('permissions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('key', 'string', ['limit' => 96])
            ->addColumn('category', 'string', ['limit' => 32])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['key'], ['unique' => true])
            ->addIndex(['category'])
            ->create();

        $this->table('permission_groups', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false])
            ->addColumn('key', 'string', ['limit' => 64])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['key'], ['unique' => true])
            ->create();

        $this->table('permission_group_members', ['id' => false, 'primary_key' => ['permission_group_id', 'permission_id']])
            ->addColumn('permission_group_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('permission_id', 'integer', ['signed' => false, 'null' => false])
            ->addForeignKey('permission_group_id', 'permission_groups', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('permission_id', 'permissions', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('role_permissions', ['id' => false, 'primary_key' => ['role_id', 'permission_id']])
            ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('permission_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('effect', 'enum', ['values' => ['allow', 'deny'], 'default' => 'allow'])
            ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('permission_id', 'permissions', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('role_permission_groups', ['id' => false, 'primary_key' => ['role_id', 'permission_group_id']])
            ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('permission_group_id', 'integer', ['signed' => false, 'null' => false])
            ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('permission_group_id', 'permission_groups', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('user_role_bindings', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('role_id', 'integer', ['signed' => false])
            ->addColumn('scope_type', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('scope_ref', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('granted_by_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['user_id', 'role_id', 'scope_type', 'scope_ref'], ['unique' => true, 'name' => 'uniq_user_role_scope'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('granted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->create();

        $this->table('user_sessions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64])
            ->addColumn('created_at', 'datetime')
            ->addColumn('last_seen_at', 'datetime')
            ->addColumn('absolute_expires_at', 'datetime')
            ->addColumn('idle_expires_at', 'datetime')
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('revoke_reason', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('ip_address_ciphertext', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['user_id'])
            ->addIndex(['absolute_expires_at'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('login_attempts', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('username', 'string', ['limit' => 64])
            ->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('success', 'boolean')
            ->addColumn('reason', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('attempted_at', 'datetime')
            ->addIndex(['username', 'attempted_at'])
            ->create();

        $this->table('password_reset_attempts', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('success', 'boolean')
            ->addColumn('reason', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('attempted_at', 'datetime')
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addIndex(['user_id', 'attempted_at'])
            ->create();
    }
}
