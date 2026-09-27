<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCommonSharedMessageAuthor extends Model
{
    protected $fillable = [
        'ai_common_message_id', 'ai_common_shared_conversation_id',
        'ai_common_shared_participant_id', 'author_user_id',
        'participant_audience_epoch', 'membership_access_epoch',
        'credential_generation',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiCommonMessage::class, 'ai_common_message_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
