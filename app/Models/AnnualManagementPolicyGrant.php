<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnualManagementPolicyGrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'annual_management_policy_id', 'organization_user_id', 'can_view_approved', 'can_view_draft',
        'can_edit', 'can_approve', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'can_view_approved' => 'boolean', 'can_view_draft' => 'boolean',
            'can_edit' => 'boolean', 'can_approve' => 'boolean',
        ];
    }

    public function annualPolicy(): BelongsTo { return $this->belongsTo(AnnualManagementPolicy::class); }
    public function membership(): BelongsTo { return $this->belongsTo(OrganizationUser::class, 'organization_user_id'); }
}
