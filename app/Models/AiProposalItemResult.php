<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProposalItemResult extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NOT_RUN = 'not_run';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = ['ai_proposal_apply_attempt_id', 'ai_proposal_item_id', 'status', 'applied_entity_public_id', 'failed_step', 'error_code', 'error_message'];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AiProposalApplyAttempt::class, 'ai_proposal_apply_attempt_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AiProposalItem::class, 'ai_proposal_item_id');
    }
}
