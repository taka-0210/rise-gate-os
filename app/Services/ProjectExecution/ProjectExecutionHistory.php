<?php

namespace App\Services\ProjectExecution;

use App\Models\Project;
use App\Models\ProjectExecutionEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProjectExecutionHistory
{
    public function record(Project $project, Model $entity, User $actor, string $event, array $before = [], array $after = [], ?string $reason = null, string $source = 'human', ?string $operationId = null): ProjectExecutionEvent
    {
        return ProjectExecutionEvent::create([
            'project_id' => $project->id,
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->getKey(),
            'subject_public_id' => $entity->getAttribute('public_id'),
            'actor_user_id' => $actor->id,
            'event' => $event,
            'source' => $source,
            'operation_id' => $operationId ?: (string) Str::uuid(),
            'entity_version' => $entity->getAttribute('plan_version'),
            'before_data' => $before ?: null,
            'after_data' => $after ?: null,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
