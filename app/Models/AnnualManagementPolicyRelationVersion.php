<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AnnualManagementPolicyRelationVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'annual_management_policy_relation_id', 'version_no', 'status', 'confirmed_by_user_id',
        'reason', 'confirmed_at',
    ];

    protected function casts(): array { return ['version_no' => 'integer', 'confirmed_at' => 'datetime']; }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Annual Management Policy relation versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Annual Management Policy relation versions are immutable.'));
    }

    public function relation(): BelongsTo
    {
        return $this->belongsTo(AnnualManagementPolicyRelation::class, 'annual_management_policy_relation_id');
    }
}
