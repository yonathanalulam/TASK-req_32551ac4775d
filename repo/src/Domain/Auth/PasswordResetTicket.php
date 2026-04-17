<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use Illuminate\Database\Eloquent\Model;

/**
 * One-time password reset ticket.
 *
 * Only `ticket_hash` (sha256 of the raw value) is persisted. The raw value is returned to
 * the caller exactly once in the BEGIN-reset response and must be presented at COMPLETE.
 *
 * @property string $id
 * @property int $user_id
 * @property string $ticket_hash
 * @property string $issued_at
 * @property string $expires_at
 * @property ?string $consumed_at
 * @property ?string $revoked_at
 */
final class PasswordResetTicket extends Model
{
    protected $table = 'password_reset_tickets';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    protected $fillable = [
        'id', 'user_id', 'ticket_hash', 'issued_at', 'expires_at',
        'consumed_at', 'revoked_at', 'consume_reason', 'ip_address_ciphertext',
    ];
    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
