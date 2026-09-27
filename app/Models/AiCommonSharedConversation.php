<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCommonSharedConversation extends Model
{
    protected $fillable = [
        'organization_id', 'ai_common_conversation_id', 'owner_user_id',
        'pending_owner_user_id', 'current_purpose_revision_id',
        'participant_version', 'version',
    ];

    protected function casts(): array
    {
        return ['participant_version' => 'integer', 'version' => 'integer'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiCommonConversation::class, 'ai_common_conversation_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function pendingOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pending_owner_user_id');
    }

    public function currentPurposeRevision(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedPurposeRevision::class, 'current_purpose_revision_id');
    }

    public function purposeRevisions(): HasMany
    {
        return $this->hasMany(AiCommonSharedPurposeRevision::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(AiCommonSharedParticipant::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AiCommonSharedSession::class);
    }
}
