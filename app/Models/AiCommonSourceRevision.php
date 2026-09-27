<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use LogicException;

class AiCommonSourceRevision extends Model
{
    protected $fillable = [
        'public_id', 'ai_common_source_id', 'ai_common_conversation_id', 'selected_by_user_id',
        'opaque_handle', 'resource_type', 'resource_public_id', 'resource_version',
        'selector', 'projection', 'organization_policy_version', 'resource_policy_version',
        'membership_access_epoch', 'credential_generation', 'access_fingerprint',
        'selection_reason', 'selected_at',
    ];

    protected function casts(): array
    {
        return [
            'selector' => 'array',
            'projection' => 'array',
            'organization_policy_version' => 'integer',
            'resource_policy_version' => 'integer',
            'membership_access_epoch' => 'integer',
            'credential_generation' => 'integer',
            'selected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $revision) => $revision->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new LogicException('AI common source revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('AI common source revisions are immutable.'));
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(AiCommonSource::class, 'ai_common_source_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiCommonConversation::class, 'ai_common_conversation_id');
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(
            AiCommonMessage::class,
            'ai_common_message_source_revisions',
            'ai_common_source_revision_id',
            'ai_common_message_id',
        )->withTimestamps();
    }

    public function proposals(): BelongsToMany
    {
        return $this->belongsToMany(
            AiProposal::class,
            'ai_common_proposal_source_revisions',
            'ai_common_source_revision_id',
            'ai_proposal_id',
        )->withTimestamps();
    }
}
