<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AiProposalAuthorization
{
    public function canReview(User $user, Project $project, ?AiProposal $proposal = null): bool
    {
        if (! $this->hasActiveTenantAccess($user, $project)) {
            return false;
        }
        $member = $project->members()->where('user_id', $user->id)->where('status', ProjectMember::STATUS_ACTIVE)->first();
        if (! $member
            || $member->project_role === ProjectMember::ROLE_CLIENT
            || ! in_array($member->permission_level, [ProjectMember::PERMISSION_ADMIN, ProjectMember::PERMISSION_EDIT], true)) {
            return false;
        }
        if (! $proposal) {
            return true;
        }

        return ! $proposal->items->contains(fn ($item) => $item->entity_type === 'project')
            || $member->project_role === ProjectMember::ROLE_OWNER;
    }

    public function canView(User $user, Project $project, ?AiProposal $proposal = null): bool
    {
        if (! $this->hasActiveTenantAccess($user, $project)) {
            return false;
        }
        $member = $project->members()->where('user_id', $user->id)->where('status', ProjectMember::STATUS_ACTIVE)->first();
        if (! $member) {
            return false;
        }

        // Legacy client membership must not expose internal before/after or proposal history.
        return $member->project_role !== ProjectMember::ROLE_CLIENT;
    }

    public function authorize(User $user, Project $project, AiProposal $proposal): void
    {
        if ($project->organization_id !== $proposal->organization_id
            || $project->owning_workspace_id !== $proposal->workspace_id
            || $project->id !== $proposal->project_id) {
            throw (new ModelNotFoundException)->setModel(AiProposal::class, [$proposal->getKey()]);
        }
        if (! $this->canReview($user, $project, $proposal)) {
            throw new AuthorizationException;
        }
    }

    public function authorizeView(User $user, Project $project, ?AiProposal $proposal = null): void
    {
        if ($proposal && ($project->id !== $proposal->project_id
            || $project->organization_id !== $proposal->organization_id
            || $project->owning_workspace_id !== $proposal->workspace_id)) {
            throw (new ModelNotFoundException)->setModel(AiProposal::class, [$proposal->getKey()]);
        }
        if (! $this->canView($user, $project, $proposal)) {
            throw new AuthorizationException;
        }
    }

    private function hasActiveTenantAccess(User $user, Project $project): bool
    {
        if (! $user->is_active || $project->trashed()) {
            return false;
        }
        $workspace = Workspace::query()
            ->whereKey($project->owning_workspace_id)
            ->where('organization_id', $project->organization_id)
            ->where('status', Workspace::STATUS_ACTIVE)
            ->first();
        if (! $workspace) {
            return false;
        }

        return $user->organizations()->where('organizations.id', $project->organization_id)->exists()
            && $user->workspaces()->where('workspaces.id', $workspace->id)->exists();
    }
}
