<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCommonSharedOperation extends Model
{
    public const RESULT_COMPLETED = 'completed';

    protected $fillable = [
        'organization_id', 'ai_common_conversation_id', 'actor_user_id',
        'operation_id', 'command', 'payload_fingerprint', 'result_type',
        'result_id', 'result_status',
    ];
}
