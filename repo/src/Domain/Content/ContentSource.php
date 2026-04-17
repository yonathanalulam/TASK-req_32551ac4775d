<?php

declare(strict_types=1);

namespace Meridian\Domain\Content;

use Illuminate\Database\Eloquent\Model;

final class ContentSource extends Model
{
    protected $table = 'content_sources';
    public $timestamps = false;
    protected $fillable = [
        'source_key', 'source_record_id', 'content_id', 'original_url',
        'original_checksum', 'first_seen_at', 'last_seen_at', 'is_active',
    ];
    protected $casts = ['is_active' => 'boolean'];
}
