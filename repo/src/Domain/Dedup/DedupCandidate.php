<?php

declare(strict_types=1);

namespace Meridian\Domain\Dedup;

use Illuminate\Database\Eloquent\Model;

final class DedupCandidate extends Model
{
    protected $table = 'dedup_candidates';
    public $timestamps = false;
    protected $fillable = [
        'left_content_id', 'right_content_id',
        'title_similarity', 'author_match', 'duration_match',
        'status', 'created_at', 'reviewed_at', 'reviewed_by_user_id',
    ];
}
