<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCommonInputOperation extends Model
{
    public const COMMAND_HUMAN_MESSAGE = 'human_message';

    public const RESULT_COMPLETED = 'completed';

    protected $fillable = [
        'organization_id', 'ai_common_conversation_id', 'actor_user_id',
        'ai_common_message_id', 'operation_id', 'command', 'payload_fingerprint',
        'result_status',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiCommonMessage::class, 'ai_common_message_id');
    }
}
