<?php

declare(strict_types=1);

namespace Meridian\Domain\Content;

use Illuminate\Database\Eloquent\Model;

final class ContentMediaRef extends Model
{
    protected $table = 'content_media_refs';
    public $timestamps = true;
    protected $fillable = [
        'content_id', 'media_type', 'local_path', 'reference_hash',
        'external_url', 'caption', 'order_index',
    ];
}
