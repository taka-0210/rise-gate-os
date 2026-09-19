<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiProposalUndo extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['public_id', 'ai_proposal_id', 'actor_id', 'status', 'result', 'error_code', 'error_message'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $undo) => $undo->public_id ??= (string) Str::ulid());
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AiProposal::class, 'ai_proposal_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
