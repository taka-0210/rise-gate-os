<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationGroup;
use App\Models\User;

class OrganizationAudit
{
    public function record(
        Organization $organization,
        ?User $actor,
        string $event,
        string $outcome,
        ?User $subject = null,
        ?OrganizationGroup $group = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
    ): OrganizationAuditEvent {
        return OrganizationAuditEvent::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $actor?->id,
            'subject_user_id' => $subject?->id,
            'organization_group_id' => $group?->id,
            'event' => $event,
            'outcome' => $outcome,
            'before_data' => $this->sanitize($before),
            'after_data' => $this->sanitize($after),
            'metadata' => $this->sanitize($metadata),
            'occurred_at' => now(),
        ]);
    }

    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];
        foreach ($values as $key => $value) {
            if (is_string($key)
                && preg_match('/password|token|secret|credential|authorization/i', $key) === 1) {
                continue;
            }
            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }
}
