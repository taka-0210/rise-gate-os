<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCommonAttachmentTranscriptionOperation extends Model
{
    public const RESULT_PROCESSING = 'processing';

    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILED = 'failed';

    public const RESULT_UNKNOWN = 'unknown';

    protected $fillable = [
        'ai_common_attachment_id', 'actor_user_id', 'ai_common_transcript_revision_id',
        'operation_id', 'payload_fingerprint', 'logical_request_id', 'result_status',
        'provider', 'model', 'safe_error_code', 'latency_ms',
    ];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(AiCommonAttachment::class, 'ai_common_attachment_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(AiCommonTranscriptRevision::class, 'ai_common_transcript_revision_id');
    }
}
