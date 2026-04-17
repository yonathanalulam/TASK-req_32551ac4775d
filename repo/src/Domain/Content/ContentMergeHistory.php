<?php

declare(strict_types=1);

namespace Meridian\Domain\Content;

use Illuminate\Database\Eloquent\Model;

final class ContentMergeHistory extends Model
{
    protected $table = 'content_merge_history';
    public $timestamps = false;
    protected $fillable = [
        'primary_content_id', 'secondary_content_id', 'action',
        'actor_user_id', 'actor_type', 'reason', 'evidence_json', 'created_at',
    ];
}
