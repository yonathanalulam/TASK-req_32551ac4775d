<?php

declare(strict_types=1);

namespace Meridian\Domain\Moderation;

use Illuminate\Database\Eloquent\Model;

final class ModerationNote extends Model
{
    protected $table = 'moderation_notes';
    public $timestamps = false;
    protected $fillable = ['case_id', 'author_user_id', 'note', 'is_private', 'created_at'];
    protected $casts = ['is_private' => 'boolean'];
}
