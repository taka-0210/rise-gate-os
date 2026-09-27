<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class AiCommonMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const VISIBILITY_VISIBLE = 'visible';

    public const VISIBILITY_SOURCE_REVOKED = 'source_revoked';

    public const SOURCE_LINEAGE_V1 = 'source-lineage.v1';

    protected $fillable = [
        'public_id', 'ai_common_conversation_id', 'role', 'content',
        'visibility_status', 'source_fingerprint', 'source_lineage_version',
        'provider', 'model', 'logical_request_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $message) => $message->public_id ??= (string) Str::ulid());
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiCommonConversation::class, 'ai_common_conversation_id');
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(AiCommonSource::class, 'ai_common_message_sources')->withTimestamps();
    }

    public function sourceRevisions(): BelongsToMany
    {
        return $this->belongsToMany(
            AiCommonSourceRevision::class,
            'ai_common_message_source_revisions',
            'ai_common_message_id',
            'ai_common_source_revision_id',
        )->withTimestamps();
    }
}
