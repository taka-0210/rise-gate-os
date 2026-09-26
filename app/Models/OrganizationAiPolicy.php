<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationAiPolicy extends Model
{
    public const CATEGORY_COMMON = 'common_entry';
    public const CATEGORY_PROJECT = 'project';
    public const CATEGORY_ACTION = 'action';
    public const CATEGORY_DOMAIN = 'business_domain';
    public const CATEGORY_CAPTURE = 'capture';

    protected $fillable = [
        'organization_id', 'is_enabled', 'allowed_categories', 'version',
        'managed_by_user_id', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'allowed_categories' => 'array',
            'version' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by_user_id');
    }

    public function allows(string $category): bool
    {
        return $this->is_enabled && in_array($category, $this->allowed_categories ?? [], true);
    }
}
