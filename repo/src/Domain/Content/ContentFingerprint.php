<?php

declare(strict_types=1);

namespace Meridian\Domain\Content;

use Illuminate\Database\Eloquent\Model;

final class ContentFingerprint extends Model
{
    protected $table = 'content_fingerprints';
    protected $primaryKey = 'content_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = [
        'content_id', 'title_normalized', 'author_normalized', 'duration_seconds',
        'simhash_hex', 'composite_fingerprint', 'algorithm_version', 'updated_at',
    ];
}
