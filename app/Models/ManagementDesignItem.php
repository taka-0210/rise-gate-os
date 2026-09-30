<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ManagementDesignItem extends Model
{
    use HasFactory;

    public const TYPE_PHILOSOPHY = 'philosophy';

    public const TYPE_VISION = 'vision';

    public const TYPE_POLICY = 'policy';

    public const TYPES = [self::TYPE_PHILOSOPHY, self::TYPE_VISION, self::TYPE_POLICY];

    public const PRESENTATION = [
        self::TYPE_PHILOSOPHY => [
            'label' => '理念',
            'direction' => 'ROOT',
            'description' => '会社が存在する根本思想を読む',
            'statement_explanation_label' => 'この理念に込めた意味（任意）',
            'statement_explanation_help' => '正式なStatementとは分けて、理念の背景・意図・会社としての解釈を説明できます。',
            'section_explanation_label' => 'このSectionの説明（任意）',
        ],
        self::TYPE_VISION => [
            'label' => 'Vision',
            'direction' => 'FUTURE',
            'description' => '会社が向かう未来を眺める',
            'statement_explanation_label' => 'このVisionが示す意味（任意）',
            'statement_explanation_help' => '正式なStatementとは分けて、描く未来の背景・意図・会社としての解釈を説明できます。',
            'section_explanation_label' => 'このSectionが描く未来の説明（任意）',
        ],
        self::TYPE_POLICY => [
            'label' => '方針',
            'direction' => 'DIRECTION',
            'description' => '会社として優先する判断方向を辿る',
            'statement_explanation_label' => 'この方針の背景・意図（任意）',
            'statement_explanation_help' => '正式なStatementとは分けて、判断方向の背景・意図・会社としての解釈を説明できます。',
            'section_explanation_label' => 'このSectionの判断意図（任意）',
        ],
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'public_id', 'organization_id', 'type', 'statement', 'statement_explanation', 'horizon', 'status', 'version',
        'current_revision_id', 'created_by_user_id', 'updated_by_user_id', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ManagementDesignItem $item): void {
            $item->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public static function label(string $type): string
    {
        return self::PRESENTATION[$type]['label'] ?? $type;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ManagementDesignSection::class)
            ->where('status', ManagementDesignSection::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function allSections(): HasMany
    {
        return $this->hasMany(ManagementDesignSection::class)->orderBy('sort_order')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ManagementDesignRevision::class)->orderByDesc('revision_no');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(ManagementDesignRevision::class, 'current_revision_id');
    }
}
