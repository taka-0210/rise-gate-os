<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiCommonHandoffRelation extends Model
{
    protected $fillable = [
        'public_id', 'ai_common_conversation_id', 'ai_proposal_id',
        'target_type', 'target_public_id', 'published_summary', 'applied_at',
    ];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $relation) => $relation->public_id ??= (string) Str::ulid());
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiCommonConversation::class, 'ai_common_conversation_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AiProposal::class, 'ai_proposal_id');
    }
}
