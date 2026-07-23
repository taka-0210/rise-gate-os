<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatMessage extends Model
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = [
        'ai_chat_thread_id', 'role', 'content', 'image_path', 'image_name', 'image_mime', 'image_size', 'context_key', 'context_label', 'model',
        'file_change_path', 'file_change_content', 'file_change_original_hash', 'file_change_status', 'file_change_applied_at',
        'provider_response_id', 'input_tokens', 'output_tokens', 'estimated_cost_microusd',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost_microusd' => 'integer',
            'image_size' => 'integer',
            'file_change_applied_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo { return $this->belongsTo(AiChatThread::class, 'ai_chat_thread_id'); }
}
