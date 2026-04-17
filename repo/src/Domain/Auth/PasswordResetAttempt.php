<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use Illuminate\Database\Eloquent\Model;

final class PasswordResetAttempt extends Model
{
    protected $table = 'password_reset_attempts';
    public $timestamps = false;
    protected $fillable = ['user_id', 'success', 'reason', 'attempted_at'];
    protected $casts = ['success' => 'boolean', 'attempted_at' => 'datetime'];
}
