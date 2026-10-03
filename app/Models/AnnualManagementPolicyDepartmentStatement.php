<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AnnualManagementPolicyDepartmentStatement extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'annual_management_policy_department_id', 'statement', 'explanation', 'sort_order',
    ];

    protected function casts(): array { return ['sort_order' => 'integer']; }

    protected static function booted(): void
    {
        static::creating(fn (self $statement) => $statement->public_id ??= (string) Str::ulid());
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(AnnualManagementPolicyDepartment::class, 'annual_management_policy_department_id');
    }
}
