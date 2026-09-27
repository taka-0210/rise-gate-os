<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiCommonSharedParticipant extends Model
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_PARTICIPANT = 'participant';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LEFT = 'left';

    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'invitation_public_id', 'ai_common_shared_conversation_id', 'user_id',
        'role', 'status', 'invited_by_user_id', 'audience_epoch', 'version',
        'accepted_membership_epoch', 'accepted_credential_generation',
        'invited_at', 'accepted_at', 'left_at', 'removed_at', 'removed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'audience_epoch' => 'integer', 'version' => 'integer',
            'accepted_membership_epoch' => 'integer',
            'accepted_credential_generation' => 'integer',
            'invited_at' => 'datetime', 'accepted_at' => 'datetime',
            'left_at' => 'datetime', 'removed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $participant) => $participant->invitation_public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'invitation_public_id';
    }

    public function sharedConversation(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedConversation::class, 'ai_common_shared_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
