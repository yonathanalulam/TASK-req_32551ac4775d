<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePasswordResetTickets extends AbstractMigration
{
    public function change(): void
    {
        $this->table('password_reset_tickets', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('ticket_hash', 'char', ['limit' => 64])
            ->addColumn('issued_at', 'datetime')
            ->addColumn('expires_at', 'datetime')
            ->addColumn('consumed_at', 'datetime', ['null' => true])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('consume_reason', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('ip_address_ciphertext', 'string', ['limit' => 512, 'null' => true])
            ->addIndex(['ticket_hash'], ['unique' => true])
            ->addIndex(['user_id', 'expires_at'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
