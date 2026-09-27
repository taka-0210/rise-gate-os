<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiCommonTemporaryAudio extends Model
{
    protected $table = 'ai_common_temporary_audios';

    public const STATE_RECORDED = 'recorded';

    public const STATE_TRANSCRIBING = 'transcribing';

    public const STATE_DRAFT = 'draft';

    public const STATE_POSTED = 'posted';

    public const STATE_CANCELLED = 'cancelled';

    public const STATE_FAILED = 'failed';

    public const STATE_UNKNOWN = 'unknown';

    public const STATE_EXPIRED = 'expired';

    public const CLEANUP_PENDING = 'pending';

    public const CLEANUP_COMPLETE = 'complete';

    protected $fillable = [
        'public_id', 'organization_id', 'ai_common_conversation_id', 'actor_user_id',
        'operation_id', 'payload_fingerprint', 'state', 'version', 'storage_key',
        'mime_type', 'extension', 'size_bytes', 'sha256', 'codec', 'duration_ms',
        'draft_text', 'posted_message_id', 'transcription_consented_at_utc',
        'expires_at_utc', 'cleanup_status', 'cleaned_at_utc', 'safe_error_code',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    protected function transcriptionConsentedAtUtc(): Attribute
    {
        return $this->utcDateTime();
    }

    protected function expiresAtUtc(): Attribute
    {
        return $this->utcDateTime();
    }

    protected function cleanedAtUtc(): Attribute
    {
        return $this->utcDateTime();
    }

    private function utcDateTime(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?CarbonImmutable => $value === null
                ? null
                : CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, 'UTC'),
            set: fn (DateTimeInterface|string|null $value): ?string => $value === null
                ? null
                : ($value instanceof DateTimeInterface
                    ? CarbonImmutable::instance($value)
                    : CarbonImmutable::parse($value))
                    ->utc()->format('Y-m-d H:i:s'),
        );
    }

    protected static function booted(): void
    {
        static::creating(fn (self $audio) => $audio->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiCommonConversation::class, 'ai_common_conversation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function postedMessage(): BelongsTo
    {
        return $this->belongsTo(AiCommonMessage::class, 'posted_message_id');
    }

    public function transcriptionOperations(): HasMany
    {
        return $this->hasMany(AiCommonTranscriptionOperation::class);
    }
}
