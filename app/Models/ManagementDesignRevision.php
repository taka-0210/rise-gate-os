<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ManagementDesignRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'management_design_item_id', 'revision_no', 'snapshot_schema_version', 'status',
        'snapshot', 'actor_user_id', 'management_design_operation_id', 'change_reason', 'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'revision_no' => 'integer',
            'snapshot_schema_version' => 'integer',
            'snapshot' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Management Design revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Management Design revisions are immutable.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ManagementDesignItem::class, 'management_design_item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(ManagementDesignOperation::class, 'management_design_operation_id');
    }
}
