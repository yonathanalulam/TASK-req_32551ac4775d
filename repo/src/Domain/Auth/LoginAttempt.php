<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use Illuminate\Database\Eloquent\Model;

final class LoginAttempt extends Model
{
    protected $table = 'login_attempts';
    public $timestamps = false;
    protected $fillable = ['username', 'user_id', 'success', 'reason', 'attempted_at'];
    protected $casts = ['success' => 'boolean', 'attempted_at' => 'datetime'];
}
