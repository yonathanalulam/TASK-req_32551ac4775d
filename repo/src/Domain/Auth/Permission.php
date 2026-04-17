<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use Illuminate\Database\Eloquent\Model;

final class Permission extends Model
{
    protected $table = 'permissions';
    public $timestamps = true;
    protected $fillable = ['key', 'category', 'description'];
}
