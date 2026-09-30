<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagementDesignGrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'item_type', 'organization_user_id', 'can_view', 'can_edit',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['can_view' => 'boolean', 'can_edit' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationUser::class, 'organization_user_id');
    }
}
