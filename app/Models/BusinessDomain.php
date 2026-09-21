<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BusinessDomain extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const DIRECTION_GROWTH = 'growth';

    public const DIRECTION_STRENGTHEN = 'strengthen';

    public const DIRECTION_MAINTAIN = 'maintain';

    public const DIRECTION_SHRINK = 'shrink';

    public const DIRECTION_EXIT_PLANNED = 'exit_planned';

    public const DIRECTIONS = [
        self::DIRECTION_GROWTH => [
            'label' => '成長・拡大',
            'description' => '規模、地域、顧客、売上、拠点等を広げていく',
        ],
        self::DIRECTION_STRENGTHEN => [
            'label' => '強化・深化',
            'description' => '規模拡大よりも、価値、専門性、競争力、提供品質等を高める',
        ],
        self::DIRECTION_MAINTAIN => [
            'label' => '維持',
            'description' => '現在の規模・位置づけを基本的に維持する',
        ],
        self::DIRECTION_SHRINK => [
            'label' => '縮小',
            'description' => '投入資源や事業規模を段階的に小さくする',
        ],
        self::DIRECTION_EXIT_PLANNED => [
            'label' => '終了予定',
            'description' => '撤退、譲渡、統合、終了等を視野に入れる',
        ],
    ];

    public const SUMMARY_FIELDS = [
        'description', 'what_summary', 'who_summary', 'value_proposition',
        'geographic_scope_summary', 'market_position_summary', 'self_recognized_strengths',
        'direction_memo',
    ];

    protected $fillable = [
        'public_id', 'organization_id', 'name', 'description', 'what_summary', 'who_summary',
        'value_proposition', 'geographic_scope_summary', 'market_position_summary',
        'self_recognized_strengths', 'direction', 'direction_memo', 'status', 'display_order',
        'version', 'created_by_user_id',
        'updated_by_user_id', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'display_order' => 'integer', 'archived_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (BusinessDomain $domain): void {
            $domain->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public static function directionLabel(?string $direction): string
    {
        return self::DIRECTIONS[$direction]['label'] ?? '未設定';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BusinessDomainItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BusinessDomainRevision::class)->orderByDesc('revision_no');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(BusinessDomainOperation::class);
    }
}
