<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiCommonAttachment extends Model
{
    public const VARIANT_UPLOAD = 'upload';

    public const VARIANT_EXISTING = 'existing_resource';

    public const STATE_RECEIVING = 'receiving';

    public const STATE_QUARANTINE = 'quarantine';

    public const STATE_READY = 'ready';

    public const STATE_REJECTED = 'rejected';

    public const STATE_FAILED = 'failed';

    public const STATE_REVOKED = 'revoked';

    public const ORIGIN_PROJECT_INTERNAL_NOTE_ATTACHMENT = 'project_internal_note_attachment';

    protected $fillable = [
        'public_id', 'organization_id', 'ai_common_conversation_id', 'uploaded_by_user_id',
        'variant', 'state', 'version', 'display_name', 'mime_type', 'extension',
        'size_bytes', 'sha256', 'storage_key', 'inspection_status', 'inspection_driver',
        'inspection_version', 'inspection_safe_code', 'inspected_at_utc', 'origin_type', 'origin_public_id',
        'media_codec', 'duration_ms',
        'origin_sha256', 'allows_ai_reference', 'ai_reference_version',
        'uploader_access_epoch', 'uploader_credential_generation', 'ready_at_utc',
        'revoked_at_utc', 'revoked_by_user_id', 'revoke_reason',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'allows_ai_reference' => 'boolean',
            'ai_reference_version' => 'integer',
            'uploader_access_epoch' => 'integer',
            'uploader_credential_generation' => 'integer',
            'ready_at_utc' => 'datetime',
            'inspected_at_utc' => 'datetime',
            'revoked_at_utc' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $attachment) => $attachment->public_id ??= (string) Str::ulid());
    }

    public function isReadyForUse(): bool
    {
        return $this->state === self::STATE_READY
            && $this->inspection_status === 'passed'
            && $this->ready_at_utc !== null;
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiCommonConversation::class, 'ai_common_conversation_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(
            AiCommonMessage::class,
            'ai_common_message_attachments',
            'ai_common_attachment_id',
            'ai_common_message_id',
        )->withPivot('attachment_version')->withTimestamps();
    }

    public function derivatives(): HasMany
    {
        return $this->hasMany(AiCommonAttachmentDerivative::class);
    }

    public function transcriptRevisions(): HasMany
    {
        return $this->hasMany(AiCommonTranscriptRevision::class);
    }
}
