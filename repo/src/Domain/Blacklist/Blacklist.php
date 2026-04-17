<?php

declare(strict_types=1);

namespace Meridian\Domain\Blacklist;

use Illuminate\Database\Eloquent\Model;

final class Blacklist extends Model
{
    protected $table = 'blacklists';
    public $timestamps = false;
    protected $fillable = [
        'entry_type', 'target_key', 'reason',
        'created_by_user_id', 'created_at',
        'revoked_at', 'revoked_by_user_id',
    ];
}
