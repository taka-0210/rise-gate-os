<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class CompanySense extends Model
{
    use HasFactory;

    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_SUPPORTED = 'supported';
    public const STATUS_UNCERTAIN = 'uncertain';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'public_id',
        'organization_id',
        'interpretation',
        'hypothesis',
        'status',
        'proposed_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (CompanySense $sense): void {
            $sense->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function observations(): BelongsToMany
    {
        return $this->belongsToMany(CompanyObservation::class, 'company_observation_sense')
            ->withPivot(['relationship_type', 'created_by'])
            ->withTimestamps();
    }

    public function improvements(): BelongsToMany
    {
        return $this->belongsToMany(CompanyImprovement::class, 'company_improvement_sense')
            ->withPivot(['relationship_type', 'created_by'])
            ->withTimestamps();
    }
}
