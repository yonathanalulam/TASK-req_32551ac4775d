<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class PermissionGroup extends Model
{
    protected $table = 'permission_groups';
    public $timestamps = true;
    protected $fillable = ['key', 'description'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_group_members', 'permission_group_id', 'permission_id');
    }
}
