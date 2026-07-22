<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatMessage extends Model
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = [
        'ai_chat_thread_id', 'role', 'content', 'context_key', 'context_label', 'model',
        'provider_response_id', 'input_tokens', 'output_tokens', 'estimated_cost_microusd',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost_microusd' => 'integer',
        ];
    }

    public function thread(): BelongsTo { return $this->belongsTo(AiChatThread::class, 'ai_chat_thread_id'); }
}
