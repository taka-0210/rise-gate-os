<?php

namespace App\Services\Organization;

use App\Models\OwnerOnboarding;
use App\Models\OwnerOnboardingAuditEvent;
use App\Models\User;

class OwnerOnboardingAudit
{
    public function record(
        OwnerOnboarding $onboarding,
        ?User $actor,
        string $event,
        string $outcome,
        ?User $subject = null,
        array $metadata = [],
    ): OwnerOnboardingAuditEvent {
        return OwnerOnboardingAuditEvent::query()->create([
            'owner_onboarding_id' => $onboarding->id,
            'actor_user_id' => $actor?->id,
            'subject_user_id' => $subject?->id,
            'event' => $event,
            'outcome' => $outcome,
            'metadata' => $this->sanitize($metadata) ?: null,
            'occurred_at' => now(),
        ]);
    }

    private function sanitize(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|token|secret|credential|authorization|url/i', $key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }
}
