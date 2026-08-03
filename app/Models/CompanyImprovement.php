<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class CompanyImprovement extends Model
{
    use HasFactory;

    public const STATUS_DISCOVERED = 'discovered';
    public const STATUS_EXPLORING = 'exploring';
    public const STATUS_READY_FOR_DECISION = 'ready_for_decision';
    public const STATUS_DECIDED = 'decided';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_WATCHING = 'watching';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_EVALUATING = 'evaluating';
    public const STATUS_LEARNED = 'learned';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';

    protected $fillable = [
        'public_id',
        'organization_id',
        'title',
        'background',
        'current_state',
        'desired_state',
        'reason',
        'hypothesis',
        'expected_effect',
        'priority',
        'status',
        'owner_user_id',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (CompanyImprovement $improvement): void {
            $improvement->public_id ??= (string) Str::ulid();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function observations(): BelongsToMany
    {
        return $this->belongsToMany(CompanyObservation::class, 'company_improvement_observation')
            ->withPivot(['relationship_type', 'created_by'])
            ->withTimestamps();
    }

    public function senses(): BelongsToMany
    {
        return $this->belongsToMany(CompanySense::class, 'company_improvement_sense')
            ->withPivot(['relationship_type', 'created_by'])
            ->withTimestamps();
    }

    public static function priorities(): array
    {
        return [
            self::PRIORITY_LOW => '低',
            self::PRIORITY_NORMAL => '通常',
            self::PRIORITY_HIGH => '高',
        ];
    }
}
