<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCommonAttachmentOperation extends Model
{
    public const RESULT_RESERVED = 'reserved';

    public const RESULT_COMPLETED = 'completed';

    public const RESULT_FAILED = 'failed';

    protected $fillable = [
        'organization_id', 'ai_common_conversation_id', 'actor_user_id',
        'ai_common_attachment_id', 'operation_id', 'command', 'payload_fingerprint',
        'result_status', 'safe_error_code',
    ];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(AiCommonAttachment::class, 'ai_common_attachment_id');
    }
}
