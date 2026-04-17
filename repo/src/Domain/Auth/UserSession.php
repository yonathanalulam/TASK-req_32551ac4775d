<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string $token_hash
 * @property ?string $revoked_at
 */
final class UserSession extends Model
{
    protected $table = 'user_sessions';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'user_id', 'token_hash', 'created_at', 'last_seen_at',
        'absolute_expires_at', 'idle_expires_at', 'revoked_at', 'revoke_reason',
        'ip_address_ciphertext', 'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'absolute_expires_at' => 'datetime',
        'idle_expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        $abs = $this->absolute_expires_at;
        $idle = $this->idle_expires_at;
        return $abs > $now && $idle > $now;
    }
}
