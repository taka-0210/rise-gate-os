<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OrganizationManagementPeriodVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_management_period_id', 'version_no', 'name', 'starts_on', 'ends_on',
        'actor_user_id', 'change_reason', 'changed_at',
    ];

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'changed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Organization management period versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Organization management period versions are immutable.'));
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(OrganizationManagementPeriod::class, 'organization_management_period_id');
    }
}
