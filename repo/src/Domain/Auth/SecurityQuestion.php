<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use Illuminate\Database\Eloquent\Model;

final class SecurityQuestion extends Model
{
    protected $table = 'security_questions';
    public $timestamps = true;
    protected $fillable = ['prompt', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
}
