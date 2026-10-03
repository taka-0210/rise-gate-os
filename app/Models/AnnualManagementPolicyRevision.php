<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AnnualManagementPolicyRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'annual_management_policy_id', 'revision_no', 'snapshot_schema_version', 'snapshot', 'snapshot_hash',
        'approved_by_user_id', 'annual_management_policy_operation_id', 'change_reason', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'revision_no' => 'integer', 'snapshot_schema_version' => 'integer',
            'snapshot' => 'array', 'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Annual Management Policy revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Annual Management Policy revisions are immutable.'));
    }

    public function annualPolicy(): BelongsTo { return $this->belongsTo(AnnualManagementPolicy::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }
}
