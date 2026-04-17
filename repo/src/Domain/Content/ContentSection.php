<?php

declare(strict_types=1);

namespace Meridian\Domain\Content;

use Illuminate\Database\Eloquent\Model;

final class ContentSection extends Model
{
    protected $table = 'content_sections';
    public $timestamps = false;
    protected $fillable = ['content_id', 'tag_slug'];
}
