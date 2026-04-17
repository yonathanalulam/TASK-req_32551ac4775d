<?php

declare(strict_types=1);

namespace Meridian\Domain\Content;

use Illuminate\Database\Eloquent\Model;

final class ContentIngestRequest extends Model
{
    protected $table = 'content_ingest_requests';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = [
        'id', 'received_at', 'source_key', 'source_record_id', 'idempotency_key',
        'submitted_by_user_id', 'raw_payload_checksum', 'raw_payload_bytes',
        'payload_kind', 'status', 'error_code', 'error_message', 'resulting_content_id',
    ];
}
