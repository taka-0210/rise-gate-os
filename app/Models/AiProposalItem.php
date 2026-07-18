<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProposalItem extends Model
{
    public const OPERATION_CREATE = 'create';
    public const OPERATION_UPDATE = 'update';
    public const OPERATION_DELETE = 'delete';

    protected $fillable = [
        'ai_proposal_id', 'operation', 'entity_type', 'target_public_id', 'reference_key', 'parent_reference',
        'attributes', 'sort_order', 'validation_status', 'validation_message',
    ];

    protected function casts(): array
    {
        return ['attributes' => 'array'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AiProposal::class, 'ai_proposal_id');
    }
}
