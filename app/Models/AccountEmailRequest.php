<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountEmailRequest extends Model
{
    public const PURPOSE_VERIFY_CURRENT = 'verify_current';

    public const PURPOSE_CHANGE_EMAIL = 'change_email';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'public_id',
        'user_id',
        'purpose',
        'current_email',
        'pending_email',
        'token_hash',
        'credential_generation',
        'status',
        'expires_at',
        'consumed_at',
        'cancelled_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
