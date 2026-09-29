<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AiCommonSharedProviderEventReceipt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider_sequence' => 'integer', 'receive_order' => 'integer',
            'provider_start_sample' => 'integer', 'provider_duration_samples' => 'integer',
            'verified_source_start_sample' => 'integer', 'verified_source_end_sample' => 'integer',
            'normalized_final_metadata' => 'encrypted:array', 'usage_quantity' => 'decimal:6',
            'estimated_cost_microunits' => 'integer', 'received_at_utc' => 'immutable_datetime',
            'finalized_at_utc' => 'immutable_datetime', 'rejected_at_utc' => 'immutable_datetime',
            'superseded_at_utc' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $receipt) => $receipt->public_id ??= (string) Str::uuid());
    }
}
