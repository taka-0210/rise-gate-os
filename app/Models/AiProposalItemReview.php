<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProposalItemReview extends Model
{
    public const ACTION_KEEP = 'keep';
    public const ACTION_REVISE = 'revise';
    public const ACTION_EXCLUDE = 'exclude';
    public const ACTION_MERGE = 'merge';

    protected $fillable = [
        'ai_proposal_item_id', 'reviewed_by', 'action', 'comment',
        'merge_target_item_id', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AiProposalItem::class, 'ai_proposal_item_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function mergeTarget(): BelongsTo
    {
        return $this->belongsTo(AiProposalItem::class, 'merge_target_item_id');
    }

    public static function actions(): array
    {
        return [
            self::ACTION_KEEP => 'このまま残す',
            self::ACTION_REVISE => '修正する',
            self::ACTION_EXCLUDE => '提案から外す',
            self::ACTION_MERGE => '別の項目と統合する',
        ];
    }
}
