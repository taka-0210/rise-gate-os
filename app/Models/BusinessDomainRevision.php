<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BusinessDomainRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_domain_id', 'revision_no', 'snapshot_schema_version', 'snapshot',
        'actor_user_id', 'business_domain_operation_id', 'change_reason', 'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'revision_no' => 'integer', 'snapshot_schema_version' => 'integer',
            'snapshot' => 'array', 'changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Business Domain revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Business Domain revisions are immutable.'));
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(BusinessDomain::class, 'business_domain_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(BusinessDomainOperation::class, 'business_domain_operation_id');
    }
}
