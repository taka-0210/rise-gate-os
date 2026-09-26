<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiResourcePolicy extends Model
{
    protected $fillable = [
        'organization_id', 'resource_type', 'resource_public_id',
        'allows_ai_reference', 'version', 'managed_by_user_id',
    ];

    protected function casts(): array
    {
        return ['allows_ai_reference' => 'boolean', 'version' => 'integer'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
