<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserRoleBinding extends Model
{
    protected $table = 'user_role_bindings';
    public $timestamps = true;
    protected $fillable = ['user_id', 'role_id', 'scope_type', 'scope_ref', 'granted_by_user_id'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
