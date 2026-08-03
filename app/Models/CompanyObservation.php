<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class CompanyObservation extends Model
{
    use HasFactory;

    public const STATUS_RECORDED = 'recorded';
    public const STATUS_WATCHING = 'watching';
    public const STATUS_INTERPRETED = 'interpreted';
    public const STATUS_DEVELOPED = 'developed';
    public const STATUS_CLOSED = 'closed';

    public const IMPORTANCE_UNREVIEWED = 'unreviewed';
    public const IMPORTANCE_IMPORTANT = 'important';
    public const IMPORTANCE_WATCHING = 'watching';
    public const IMPORTANCE_NOT_NOW = 'not_now';
    public const IMPORTANCE_UNCLEAR = 'unclear';

    public const SOURCE_EXECUTIVE = 'executive';
    public const SOURCE_EMPLOYEE = 'employee';
    public const SOURCE_CUSTOMER = 'customer';
    public const SOURCE_PARTNER = 'partner';
    public const SOURCE_MEETING = 'meeting';
    public const SOURCE_OTHER = 'other';

    protected $fillable = [
        'public_id',
        'organization_id',
        'title',
        'body',
        'occurred_on',
        'observed_at',
        'observer_user_id',
        'source_type',
        'source_name',
        'source_note',
        'status',
        'importance',
        'next_observation',
        'reviewed_by',
        'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (CompanyObservation $observation): void {
            $observation->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'observed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function observer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observer_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function senses(): BelongsToMany
    {
        return $this->belongsToMany(CompanySense::class, 'company_observation_sense')
            ->withPivot(['relationship_type', 'created_by'])
            ->withTimestamps();
    }

    public function improvements(): BelongsToMany
    {
        return $this->belongsToMany(CompanyImprovement::class, 'company_improvement_observation')
            ->withPivot(['relationship_type', 'created_by'])
            ->withTimestamps();
    }

    public static function sourceTypes(): array
    {
        return [
            self::SOURCE_EXECUTIVE => '経営者',
            self::SOURCE_EMPLOYEE => '社員',
            self::SOURCE_CUSTOMER => '顧客',
            self::SOURCE_PARTNER => '外部パートナー',
            self::SOURCE_MEETING => '会議',
            self::SOURCE_OTHER => 'その他',
        ];
    }

    public static function importanceLabels(): array
    {
        return [
            self::IMPORTANCE_UNREVIEWED => '未確認',
            self::IMPORTANCE_IMPORTANT => '重要な変化',
            self::IMPORTANCE_WATCHING => '経過観察',
            self::IMPORTANCE_NOT_NOW => '今は扱わない',
            self::IMPORTANCE_UNCLEAR => 'まだ判断できない',
        ];
    }
}
