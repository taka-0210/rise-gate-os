<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiCommonConversation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'public_id', 'organization_id', 'user_id', 'project_id', 'title',
        'status', 'version', 'last_message_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'last_message_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $conversation) => $conversation->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiCommonMessage::class)->orderBy('id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AiCommonSource::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AiProposal::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AiCommonAttachment::class);
    }
}
