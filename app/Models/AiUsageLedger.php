<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AiUsageLedger extends Model
{
    protected $fillable = [
        'public_id', 'organization_id', 'user_id', 'ai_common_conversation_id',
        'logical_request_id', 'purpose', 'provider', 'model', 'attempt',
        'input_tokens', 'output_tokens', 'estimated_cost_microunits',
        'price_version', 'currency', 'result', 'safe_error_code', 'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer',
            'estimated_cost_microunits' => 'integer', 'latency_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $ledger) => $ledger->public_id ??= (string) Str::ulid());
    }
}
