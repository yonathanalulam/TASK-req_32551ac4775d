<?php

declare(strict_types=1);

namespace Meridian\Domain\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $content_id
 * @property string $title
 * @property string $title_normalized
 * @property string $body
 * @property string $body_checksum
 * @property string $language
 * @property ?string $author
 * @property ?int $duration_seconds
 * @property string $media_source
 * @property string $risk_state
 * @property ?string $merged_into_content_id
 */
final class Content extends Model
{
    protected $table = 'contents';
    protected $primaryKey = 'content_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = true;

    protected $fillable = [
        'content_id', 'title', 'title_normalized', 'body', 'body_checksum',
        'language', 'author', 'duration_seconds', 'media_source',
        'published_at', 'ingested_at', 'risk_state',
        'created_by_user_id', 'last_modified_by_user_id',
        'merged_into_content_id', 'merged_at', 'version',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'ingested_at' => 'datetime',
        'merged_at' => 'datetime',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(ContentSource::class, 'content_id', 'content_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ContentMediaRef::class, 'content_id', 'content_id');
    }

    public function fingerprint(): HasOne
    {
        return $this->hasOne(ContentFingerprint::class, 'content_id', 'content_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ContentSection::class, 'content_id', 'content_id');
    }
}
