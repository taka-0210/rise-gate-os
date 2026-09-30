<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ManagementDesignSection extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'public_id', 'management_design_item_id', 'title', 'body', 'horizon', 'sort_order',
        'status', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (ManagementDesignSection $section): void {
            $section->public_id ??= (string) Str::ulid();
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ManagementDesignItem::class, 'management_design_item_id');
    }
}
