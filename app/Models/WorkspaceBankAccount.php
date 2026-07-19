<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceBankAccount extends Model
{
    public const TYPE_ORDINARY = 'ordinary';
    public const TYPE_CURRENT = 'current';
    public const TYPE_SAVINGS = 'savings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public static function types(): array
    {
        return [self::TYPE_ORDINARY => '普通', self::TYPE_CURRENT => '当座', self::TYPE_SAVINGS => '貯蓄'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
