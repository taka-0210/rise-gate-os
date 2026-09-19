<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class AiProposalItem extends Model
{
    public const OPERATION_CREATE = 'create';

    public const OPERATION_UPDATE = 'update';

    public const OPERATION_DELETE = 'delete';

    protected $fillable = [
        'ai_proposal_id', 'operation', 'entity_type', 'target_public_id', 'reference_key', 'parent_reference',
        'public_id', 'attributes', 'depends_on', 'before', 'after', 'expected_version',
        'sort_order', 'validation_status', 'validation_message', 'applied_entity_public_id', 'applied_version',
    ];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'depends_on' => 'array', 'before' => 'array', 'after' => 'array', 'expected_version' => 'integer', 'applied_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $item) => $item->public_id ??= (string) Str::ulid());
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AiProposal::class, 'ai_proposal_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(AiProposalItemReview::class);
    }
}
