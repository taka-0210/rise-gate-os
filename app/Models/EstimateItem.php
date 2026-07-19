<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateItem extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['quantity' => 'decimal:3', 'tax_rate' => 'decimal:2']; }
    public function estimate(): BelongsTo { return $this->belongsTo(Estimate::class); }
}
