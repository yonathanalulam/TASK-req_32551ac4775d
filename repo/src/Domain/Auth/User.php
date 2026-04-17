<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $username
 * @property string $password_hash
 * @property string $status
 * @property ?string $display_name
 * @property ?string $email_ciphertext
 * @property ?string $last_login_at
 * @property ?string $locked_until
 * @property ?string $reset_locked_until
 * @property bool $is_system
 * @property Collection<int, UserRoleBinding> $roleBindings
 */
final class User extends Model
{
    protected $table = 'users';
    public $timestamps = true;

    protected $fillable = [
        'username', 'password_hash', 'display_name', 'email_ciphertext',
        'status', 'last_login_at', 'locked_until', 'reset_locked_until', 'is_system',
    ];

    protected $hidden = ['password_hash', 'email_ciphertext'];

    protected $casts = [
        'is_system' => 'boolean',
        'last_login_at' => 'datetime',
        'locked_until' => 'datetime',
        'reset_locked_until' => 'datetime',
    ];

    public function roleBindings(): HasMany
    {
        return $this->hasMany(UserRoleBinding::class, 'user_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role_bindings', 'user_id', 'role_id');
    }

    public function securityAnswers(): HasMany
    {
        return $this->hasMany(UserSecurityAnswer::class, 'user_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class, 'user_id');
    }
}
