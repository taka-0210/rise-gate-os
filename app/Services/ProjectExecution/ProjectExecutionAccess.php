<?php

namespace App\Services\ProjectExecution;

use App\Models\OrganizationGroupMembership;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectMemberRole;
use App\Models\Task;
use App\Models\User;
use App\Services\Organization\OrganizationAccess;
use Illuminate\Database\Eloquent\Builder;

class ProjectExecutionAccess
{
    public function __construct(private readonly OrganizationAccess $organizations) {}

    public function canRead(User $user, Project $project): bool
    {
        if (! $user->is_active || $project->trashed()) {
            return false;
        }
        if ($this->activeExplicitMember($user, $project)) {
            return true;
        }
        if (! $project->usesScopeEight() || $project->visibility === Project::VISIBILITY_CONFIDENTIAL) {
            return false;
        }
        if (! $this->organizations->hasActiveMembership($user, $project->organization)) {
            return false;
        }

        return match ($project->visibility) {
            Project::VISIBILITY_COMPANY => true,
            Project::VISIBILITY_GROUP => OrganizationGroupMembership::query()
                ->whereHas('organizationMembership', fn (Builder $query) => $query
                    ->where('organization_id', $project->organization_id)
                    ->where('user_id', $user->id)
                    ->where('membership_status', OrganizationUser::STATUS_ACTIVE))
                ->whereHas('group', fn (Builder $query) => $query
                    ->where('organization_id', $project->organization_id)
                    ->whereNull('archived_at')
                    ->whereHas('projectAudiences', fn (Builder $audiences) => $audiences->where('project_id', $project->id)))
                ->exists(),
            default => false,
        };
    }

    public function canManageStructure(User $user, Project $project): bool
    {
        return $project->owner_user_id === $user->id && $this->activeExplicitMember($user, $project) !== null;
    }

    public function canCreateAction(User $user, Project $project): bool
    {
        $member = $this->activeExplicitMember($user, $project);

        return $member !== null && ($project->owner_user_id === $user->id
            || $member->hasExecutionRole(ProjectMemberRole::ROLE_MEMBER));
    }

    public function canEditAction(User $user, Task $task): bool
    {
        return $this->canManageStructure($user, $task->project)
            || ($task->assigned_to === $user->id && $this->isExecutionMember($user, $task->project));
    }

    public function canExecuteAction(User $user, Task $task): bool
    {
        return $task->assigned_to === $user->id && $this->isExecutionMember($user, $task->project);
    }

    public function canReviewAction(User $user, Task $task): bool
    {
        return $task->reviewer_user_id === $user->id
            && $task->assigned_to !== $user->id
            && $this->canBeReviewer($user, $task->project);
    }

    public function isExecutionMember(User $user, Project $project): bool
    {
        $member = $this->activeExplicitMember($user, $project);

        return $member !== null && ($project->owner_user_id === $user->id
            || $member->hasExecutionRole(ProjectMemberRole::ROLE_MEMBER));
    }

    public function canBeReviewer(User $user, Project $project): bool
    {
        $member = $this->activeExplicitMember($user, $project);

        return $member !== null && ($project->owner_user_id === $user->id
            || $project->reviewer_user_id === $user->id
            || $member->hasExecutionRole(ProjectMemberRole::ROLE_MEMBER));
    }

    public function activeExplicitMember(User $user, Project $project): ?ProjectMember
    {
        $member = $project->members()->with('activeRoleAssignments')
            ->where('user_id', $user->id)->where('status', ProjectMember::STATUS_ACTIVE)->first();

        return $member && $user->canAccessWorkspace($member->workspace_id) ? $member : null;
    }
}
