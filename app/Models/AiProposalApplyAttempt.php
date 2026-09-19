<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiProposalApplyAttempt extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CONFLICTED = 'conflicted';

    protected $fillable = ['public_id', 'ai_proposal_id', 'actor_id', 'attempt_number', 'idempotency_key', 'status', 'error_code', 'retryable', 'error_message', 'applied_items_count', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['retryable' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $attempt) => $attempt->public_id ??= (string) Str::ulid());
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AiProposal::class, 'ai_proposal_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function itemResults(): HasMany
    {
        return $this->hasMany(AiProposalItemResult::class);
    }
}
