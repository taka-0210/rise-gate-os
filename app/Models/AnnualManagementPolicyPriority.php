<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AnnualManagementPolicyPriority extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'annual_management_policy_id', 'annual_management_policy_theme_id',
        'statement', 'explanation', 'sort_order',
    ];

    protected function casts(): array { return ['sort_order' => 'integer']; }

    protected static function booted(): void
    {
        static::creating(fn (self $priority) => $priority->public_id ??= (string) Str::ulid());
    }

    public function annualPolicy(): BelongsTo { return $this->belongsTo(AnnualManagementPolicy::class); }
    public function theme(): BelongsTo { return $this->belongsTo(AnnualManagementPolicyTheme::class, 'annual_management_policy_theme_id'); }
}
