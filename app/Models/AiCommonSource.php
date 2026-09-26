<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class AiCommonSource extends Model
{
    protected $fillable = [
        'public_id', 'ai_common_conversation_id', 'selected_by_user_id',
        'opaque_handle', 'resource_type', 'resource_public_id', 'resource_version',
        'freshness_fingerprint', 'selection_reason', 'projection', 'selected_at',
    ];

    protected function casts(): array
    {
        return ['projection' => 'array', 'selected_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $source) => $source->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiCommonConversation::class, 'ai_common_conversation_id');
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(AiCommonMessage::class, 'ai_common_message_sources')->withTimestamps();
    }
}
