<?php

namespace App\Services;

use App\Models\AccountSecurityEvent;
use App\Models\User;

class AccountAudit
{
    public function record(
        string $event,
        string $outcome,
        ?User $user = null,
        ?User $actor = null,
        array $metadata = [],
    ): void {
        AccountSecurityEvent::query()->create([
            'user_id' => $user?->id,
            'actor_user_id' => $actor?->id,
            'event' => $event,
            'outcome' => $outcome,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }
}
