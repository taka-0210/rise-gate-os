<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiCommonSharedPurposeRevision extends Model
{
    protected $fillable = [
        'public_id', 'ai_common_shared_conversation_id', 'revision_no',
        'purpose', 'purpose_hash', 'created_by_user_id', 'operation_id',
    ];

    protected function casts(): array
    {
        return ['revision_no' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $revision) => $revision->public_id ??= (string) Str::ulid());
    }

    public function sharedConversation(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedConversation::class, 'ai_common_shared_conversation_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
