<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationAuditEvent extends Model
{
    use HasFactory;

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_NOOP = 'noop';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_CONFLICT = 'conflict';

    protected $fillable = [
        'organization_id',
        'actor_user_id',
        'subject_user_id',
        'organization_group_id',
        'event',
        'outcome',
        'before_data',
        'after_data',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(OrganizationGroup::class, 'organization_group_id');
    }
}
