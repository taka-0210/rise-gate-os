<?php

namespace App\Services\ProjectExecution;

use App\Models\Improvement;
use App\Models\OrganizationGroup;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\ProjectGroupAudience;
use App\Models\ProjectMember;
use App\Models\ProjectMemberRole;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notification\NotificationSourceWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectExecutionWriter
{
    public function __construct(
        private readonly ProjectExecutionAccess $access,
        private readonly ProjectExecutionHistory $history,
        private readonly NotificationSourceWriter $notifications,
    ) {}

    public function createProject(User $actor, Workspace $workspace, array $attributes): Project
    {
        if (! $actor->canAccessWorkspace($workspace->id)) {
            throw new AuthorizationException;
        }
        $this->requireText($attributes, ['name', 'purpose', 'expected_outcome']);

        return DB::transaction(function () use ($actor, $workspace, $attributes): Project {
            $this->assertActiveOrganizationUser($actor, $workspace->organization_id);
            $project = Project::create([
                ...Arr::only($attributes, ['name', 'purpose', 'expected_outcome', 'start_date', 'due_date']),
                'organization_id' => $workspace->organization_id,
                'owning_workspace_id' => $workspace->id,
                'billing_workspace_id' => $workspace->id,
                'owner_user_id' => $actor->id,
                'status' => Project::STATUS_DRAFT,
                'priority' => Project::PRIORITY_NORMAL,
                'execution_contract_version' => Project::EXECUTION_CONTRACT,
                'visibility' => Project::VISIBILITY_COMPANY,
                'review_status' => Project::REVIEW_NOT_REQUIRED,
                'workspace_reorder_mode' => 'order_only',
            ]);
            $member = ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $actor->id,
                'workspace_id' => $workspace->id,
                'project_role' => ProjectMember::ROLE_OWNER,
                'permission_level' => ProjectMember::PERMISSION_ADMIN,
                'invited_by' => $actor->id,
                'invited_at' => now(),
                'accepted_at' => now(),
                'status' => ProjectMember::STATUS_ACTIVE,
            ]);
            $this->history->record($project, $project, $actor, 'project.created', after: [
                'owner_user_id' => $actor->id, 'visibility' => Project::VISIBILITY_COMPANY,
            ]);
            $this->history->record($project, $member, $actor, 'member.joined', after: ['user_id' => $actor->id]);

            return $project->fresh();
        }, 3);
    }

    public function updateProject(User $actor, Project $project, array $attributes, int $expectedVersion): Project
    {
        return DB::transaction(function () use ($actor, $project, $attributes, $expectedVersion): Project {
            $locked = $this->lockProject($project, $expectedVersion);
            $this->authorizeOwner($actor, $locked);
            $allowed = Arr::only($attributes, ['name', 'purpose', 'expected_outcome', 'start_date', 'due_date']);
            $this->requireText($allowed + $locked->only(['name', 'purpose', 'expected_outcome']), ['name', 'purpose', 'expected_outcome']);
            $before = $locked->only(array_keys($allowed));
            $locked->update($allowed);
            $this->history->record($locked, $locked, $actor, 'project.updated', $before, $allowed);

            return $locked->fresh();
        }, 3);
    }

    public function setVisibility(User $actor, Project $project, string $visibility, array $groupIds, ?string $reason, int $expectedVersion): Project
    {
        if (! array_key_exists($visibility, Project::visibilities())) {
            throw ValidationException::withMessages(['visibility' => '公開範囲が不正です。']);
        }
        if ($visibility === Project::VISIBILITY_CONFIDENTIAL && blank($reason)) {
            throw ValidationException::withMessages(['confidential_reason' => '機密理由を入力してください。']);
        }

        return DB::transaction(function () use ($actor, $project, $visibility, $groupIds, $reason, $expectedVersion): Project {
            $locked = $this->lockProject($project, $expectedVersion);
            $this->authorizeOwner($actor, $locked);
            $ids = array_values(array_unique(array_map('intval', $groupIds)));
            $groups = OrganizationGroup::query()->whereIn('id', $ids)
                ->where('organization_id', $locked->organization_id)->whereNull('archived_at')->lockForUpdate()->get();
            if ($visibility === Project::VISIBILITY_GROUP && ($ids === [] || $groups->count() !== count($ids))) {
                throw ValidationException::withMessages(['group_ids' => '同じOrganizationのactive Groupを指定してください。']);
            }
            $before = ['visibility' => $locked->visibility, 'group_ids' => $locked->groupAudiences()->pluck('organization_group_id')->all()];
            $locked->update([
                'visibility' => $visibility,
                'confidential_reason' => $visibility === Project::VISIBILITY_CONFIDENTIAL ? trim((string) $reason) : null,
            ]);
            $locked->groupAudiences()->delete();
            if ($visibility === Project::VISIBILITY_GROUP) {
                foreach ($groups as $group) {
                    ProjectGroupAudience::create([
                        'project_id' => $locked->id,
                        'organization_group_id' => $group->id,
                        'added_by_user_id' => $actor->id,
                    ]);
                }
            }
            $this->history->record($locked, $locked, $actor, 'project.visibility_changed', $before, [
                'visibility' => $visibility, 'group_ids' => $groups->pluck('id')->all(),
            ], $reason);

            return $locked->fresh();
        }, 3);
    }

    public function addMember(User $actor, Project $project, User $user, Workspace $accessWorkspace, array $roles, int $expectedVersion): ProjectMember
    {
        $roles = array_values(array_unique($roles));
        if ($roles === [] || array_diff($roles, [ProjectMemberRole::ROLE_MEMBER, ProjectMemberRole::ROLE_VIEWER]) !== []) {
            throw ValidationException::withMessages(['roles' => 'MemberまたはViewerを指定してください。']);
        }

        return DB::transaction(function () use ($actor, $project, $user, $accessWorkspace, $roles, $expectedVersion): ProjectMember {
            $locked = $this->lockProject($project, $expectedVersion);
            $this->authorizeOwner($actor, $locked);
            $this->assertActiveOrganizationUser($user, $locked->organization_id);
            if ($accessWorkspace->organization_id !== $locked->organization_id
                || $accessWorkspace->status !== Workspace::STATUS_ACTIVE
                || ! $user->workspaces()->whereKey($accessWorkspace->id)->exists()) {
                throw ValidationException::withMessages(['workspace_id' => '本人が利用できる同一OrganizationのWorkspaceを指定してください。']);
            }
            $member = ProjectMember::query()->lockForUpdate()
                ->firstOrNew(['project_id' => $locked->id, 'user_id' => $user->id]);
            $before = $member->exists ? ['status' => $member->status] : [];
            $member->fill([
                'workspace_id' => $accessWorkspace->id,
                'project_role' => in_array(ProjectMemberRole::ROLE_MEMBER, $roles, true) ? 'member' : ProjectMember::ROLE_VIEWER,
                'permission_level' => in_array(ProjectMemberRole::ROLE_MEMBER, $roles, true) ? ProjectMember::PERMISSION_EDIT : ProjectMember::PERMISSION_VIEW,
                'invited_by' => $actor->id,
                'invited_at' => now(),
                'accepted_at' => now(),
                'status' => ProjectMember::STATUS_ACTIVE,
                'left_at' => null,
                'left_by_user_id' => null,
                'status_reason' => null,
            ])->save();
            foreach ([ProjectMemberRole::ROLE_MEMBER, ProjectMemberRole::ROLE_VIEWER] as $role) {
                $assignment = $member->roleAssignments()->firstOrNew(['role' => $role]);
                if (in_array($role, $roles, true)) {
                    $assignment->fill([
                        'granted_by_user_id' => $actor->id, 'granted_at' => now(),
                        'revoked_at' => null, 'revoked_by_user_id' => null,
                    ])->save();
                } elseif ($assignment->exists && $assignment->revoked_at === null) {
                    $assignment->update(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id]);
                }
            }
            $this->history->record($locked, $member, $actor, 'member.joined', $before, [
                'user_id' => $user->id, 'roles' => $roles,
            ]);
            $this->bumpProjectVersion($locked);

            return $member->fresh('activeRoleAssignments');
        }, 3);
    }

    public function setProjectReviewer(User $actor, Project $project, ?User $reviewer, int $expectedVersion): Project
    {
        return DB::transaction(function () use ($actor, $project, $reviewer, $expectedVersion): Project {
            $locked = $this->lockProject($project, $expectedVersion);
            $this->authorizeOwner($actor, $locked);
            if ($reviewer && ($reviewer->id === $locked->owner_user_id || ! $this->access->canBeReviewer($reviewer, $locked))) {
                throw ValidationException::withMessages(['reviewer_user_id' => 'Select another active project participant.']);
            }
            $before = $locked->only(['reviewer_user_id', 'review_status']);
            $locked->update([
                'reviewer_user_id' => $reviewer?->id,
                'review_status' => $reviewer ? Project::REVIEW_PENDING : Project::REVIEW_NOT_REQUIRED,
                'review_requested_by_user_id' => null,
                'review_requested_at' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
            ]);
            $this->history->record($locked, $locked, $actor, 'project.reviewer_changed', $before,
                $locked->only(['reviewer_user_id', 'review_status']));

            return $locked->fresh();
        }, 3);
    }

    public function transferOwner(User $actor, Project $project, User $newOwner, int $expectedVersion): Project
    {
        return DB::transaction(function () use ($actor, $project, $newOwner, $expectedVersion): Project {
            $locked = $this->lockProject($project, $expectedVersion);
            $this->authorizeOwner($actor, $locked);
            if ($newOwner->id === $locked->owner_user_id) {
                return $locked;
            }
            $newMembership = ProjectMember::query()->lockForUpdate()
                ->where('project_id', $locked->id)->where('user_id', $newOwner->id)
                ->where('status', ProjectMember::STATUS_ACTIVE)->first();
            if (! $newMembership || ! $this->access->isExecutionMember($newOwner, $locked)) {
                throw ValidationException::withMessages(['owner_user_id' => 'Select an active project member.']);
            }
            $oldMembership = ProjectMember::query()->lockForUpdate()
                ->where('project_id', $locked->id)->where('user_id', $locked->owner_user_id)
                ->where('status', ProjectMember::STATUS_ACTIVE)->firstOrFail();
            $before = ['owner_user_id' => $locked->owner_user_id];
            $oldMembership->update([
                'project_role' => ProjectMember::ROLE_MEMBER,
                'permission_level' => ProjectMember::PERMISSION_EDIT,
            ]);
            $newMembership->update([
                'project_role' => ProjectMember::ROLE_OWNER,
                'permission_level' => ProjectMember::PERMISSION_ADMIN,
            ]);
            $locked->update(['owner_user_id' => $newOwner->id]);
            $this->history->record($locked, $locked, $actor, 'project.owner_changed', $before,
                ['owner_user_id' => $newOwner->id]);

            return $locked->fresh();
        }, 3);
    }

    public function leaveMember(User $actor, Project $project, ProjectMember $member, string $reason, int $expectedVersion): ProjectMember
    {
        return DB::transaction(function () use ($actor, $project, $member, $reason, $expectedVersion): ProjectMember {
            $locked = $this->lockProject($project, $expectedVersion);
            $this->authorizeOwner($actor, $locked);
            $member = ProjectMember::query()->lockForUpdate()->where('project_id', $locked->id)->findOrFail($member->id);
            if ($member->user_id === $locked->owner_user_id || $member->user_id === $locked->reviewer_user_id) {
                throw ValidationException::withMessages(['member' => 'OwnerまたはProject Reviewerは先に役割を変更してください。']);
            }
            if (Task::query()->where('project_id', $locked->id)->whereNull('deleted_at')
                ->where(fn ($query) => $query->where('assigned_to', $member->user_id)->orWhere('reviewer_user_id', $member->user_id))
                ->whereNotIn('status', [Task::STATUS_DONE, Task::STATUS_ARCHIVED])->exists()) {
                throw ValidationException::withMessages(['member' => '未完了Actionの担当・Reviewerを先に変更してください。']);
            }
            $member->update([
                'status' => ProjectMember::STATUS_LEFT, 'left_at' => now(),
                'left_by_user_id' => $actor->id, 'status_reason' => $reason,
            ]);
            $this->history->record($locked, $member, $actor, 'member.left',
                ['status' => ProjectMember::STATUS_ACTIVE], ['status' => ProjectMember::STATUS_LEFT], $reason);
            $this->bumpProjectVersion($locked);

            return $member->fresh();
        }, 3);
    }

    public function createRoadmap(User $actor, Project $project, array $attributes, int $expectedVersion): Roadmap
    {
        return DB::transaction(function () use ($actor, $project, $attributes, $expectedVersion): Roadmap {
            $locked = $this->lockProject($project, $expectedVersion);
            $this->authorizeOwner($actor, $locked);
            $this->requireText($attributes, ['title']);
            $roadmap = Roadmap::create([
                ...Arr::only($attributes, ['title', 'purpose']),
                'organization_id' => $locked->organization_id,
                'workspace_id' => $locked->owning_workspace_id,
                'project_id' => $locked->id,
                'status' => Roadmap::STATUS_ACTIVE,
                'sort_order' => ((int) $locked->roadmaps()->withTrashed()->max('sort_order')) + 1,
                'created_by' => $actor->id,
            ]);
            $this->history->record($locked, $roadmap, $actor, 'roadmap.created',
                after: $roadmap->only(['title', 'purpose']));

            return $roadmap;
        }, 3);
    }

    public function createTheme(User $actor, Project $project, Roadmap $roadmap, array $attributes, int $expectedVersion): Improvement
    {
        return DB::transaction(function () use ($actor, $project, $roadmap, $attributes, $expectedVersion): Improvement {
            $locked = $this->lockProject($project, $expectedVersion);
            $this->authorizeOwner($actor, $locked);
            $roadmap = Roadmap::query()->lockForUpdate()->where('project_id', $locked->id)->findOrFail($roadmap->id);
            $this->requireText($attributes, ['title']);
            $theme = Improvement::create([
                'organization_id' => $locked->organization_id,
                'workspace_id' => $locked->owning_workspace_id,
                'project_id' => $locked->id,
                'roadmap_id' => $roadmap->id,
                'roadmap_sort_order' => ((int) $roadmap->improvements()->withTrashed()->max('roadmap_sort_order')) + 1,
                'title' => trim($attributes['title']),
                'theme_description' => $attributes['description'] ?? null,
                'status' => Improvement::STATUS_PLANNED,
                'execution_status' => Improvement::EXECUTION_STATUS_ACTIVE,
                'visibility' => Improvement::VISIBILITY_INTERNAL,
                'proposed_by' => $actor->id,
            ]);
            $this->history->record($locked, $theme, $actor, 'theme.created',
                after: $theme->only(['title', 'theme_description', 'roadmap_id']));

            return $theme;
        }, 3);
    }

    public function createAction(User $actor, Project $project, array $attributes, int $expectedVersion): Task
    {
        return DB::transaction(function () use ($actor, $project, $attributes, $expectedVersion): Task {
            $locked = $this->lockProject($project, $expectedVersion);
            if (! $this->access->canCreateAction($actor, $locked)) {
                throw new AuthorizationException;
            }
            $this->requireText($attributes, ['title', 'done_condition']);
            $assignee = User::query()->lockForUpdate()->findOrFail((int) ($attributes['assigned_to'] ?? 0));
            if (! $this->access->isExecutionMember($assignee, $locked)) {
                throw ValidationException::withMessages(['assigned_to' => 'activeなOwnerまたはMemberを指定してください。']);
            }
            $reviewer = null;
            if (filled($attributes['reviewer_user_id'] ?? null)) {
                $reviewer = User::query()->lockForUpdate()->findOrFail((int) $attributes['reviewer_user_id']);
                if ($reviewer->id === $assignee->id || ! $this->access->canBeReviewer($reviewer, $locked)) {
                    throw ValidationException::withMessages(['reviewer_user_id' => '本人以外のactiveなOwner・Member・Project Reviewerを指定してください。']);
                }
            }
            $theme = null;
            if (filled($attributes['improvement_id'] ?? null)) {
                $theme = Improvement::query()->lockForUpdate()->where('project_id', $locked->id)
                    ->whereNotNull('roadmap_id')->findOrFail((int) $attributes['improvement_id']);
            }
            $sortQuery = Task::query()->withTrashed()->where('project_id', $locked->id);
            $theme ? $sortQuery->where('improvement_id', $theme->id) : $sortQuery->whereNull('improvement_id');
            $action = Task::create([
                'organization_id' => $locked->organization_id,
                'workspace_id' => $locked->owning_workspace_id,
                'project_id' => $locked->id,
                'improvement_id' => $theme?->id,
                'title' => trim($attributes['title']),
                'description' => $attributes['description'] ?? null,
                'done_condition' => trim($attributes['done_condition']),
                'assigned_to' => $assignee->id,
                'reviewer_user_id' => $reviewer?->id,
                'review_status' => $reviewer ? Task::REVIEW_PENDING : Task::REVIEW_NOT_REQUIRED,
                'status' => Task::STATUS_TODO,
                'priority' => Task::PRIORITY_NORMAL,
                'due_date' => $attributes['due_date'] ?? null,
                'sort_order' => ((int) $sortQuery->max('sort_order')) + 1,
                'created_by' => $actor->id,
            ]);
            $this->history->record($locked, $action, $actor, 'action.created', after: $action->only([
                'title', 'done_condition', 'assigned_to', 'reviewer_user_id', 'due_date', 'improvement_id',
            ]));
            $this->notifications->actionAssigned($actor, $action, $attributes['notification_timing'] ?? 'now', $attributes['notification_at'] ?? null);

            return $action;
        }, 3);
    }

    public function updateAction(User $actor, Task $action, array $attributes, int $expectedProjectVersion, ?string $reason = null): Task
    {
        return DB::transaction(function () use ($actor, $action, $attributes, $expectedProjectVersion, $reason): Task {
            $project = $this->lockProject($action->project, $expectedProjectVersion);
            $action = Task::query()->lockForUpdate()->where('project_id', $project->id)->findOrFail($action->id);
            if (! $this->access->canEditAction($actor, $action)) {
                throw new AuthorizationException;
            }
            $allowed = Arr::only($attributes, ['title', 'description', 'done_condition', 'due_date']);
            $currentDue = $action->due_date?->format('Y-m-d');
            if (array_key_exists('due_date', $allowed) && $currentDue !== ($allowed['due_date'] ?: null) && blank($reason)) {
                throw ValidationException::withMessages(['reason' => '期限変更理由を入力してください。']);
            }
            $this->requireText($allowed + $action->only(['title', 'done_condition']), ['title', 'done_condition']);
            $before = $action->only(array_merge(array_keys($allowed), ['status', 'review_status']));
            if ($action->status === Task::STATUS_REVIEW_PENDING
                && array_intersect(array_keys($allowed), ['title', 'done_condition', 'due_date']) !== []) {
                $allowed += [
                    'status' => Task::STATUS_IN_PROGRESS,
                    'review_status' => Task::REVIEW_REJECTED,
                    'reviewed_at' => null,
                    'reviewed_by_user_id' => null,
                ];
            }
            $allowed['last_change_reason'] = $reason;
            $action->update($allowed);
            $this->history->record($project, $action, $actor, 'action.updated', $before,
                $action->only(array_keys($allowed)), $reason);

            return $action->fresh();
        }, 3);
    }

    public function transitionAction(User $actor, Task $action, string $command, int $expectedProjectVersion, ?string $reason = null): Task
    {
        return DB::transaction(function () use ($actor, $action, $command, $expectedProjectVersion, $reason): Task {
            $project = $this->lockProject($action->project, $expectedProjectVersion);
            $action = Task::query()->lockForUpdate()->where('project_id', $project->id)->findOrFail($action->id);
            $before = $action->only(['status', 'review_status', 'completed_at', 'completed_by_user_id']);
            $changes = match ($command) {
                'start' => $this->transitionAsAssignee($actor, $action, [Task::STATUS_TODO], [
                    'status' => Task::STATUS_IN_PROGRESS,
                ]),
                'complete' => $this->completeOrSubmit($actor, $action),
                'confirm' => $this->reviewAction($actor, $action, true, $reason),
                'reject' => $this->reviewAction($actor, $action, false, $reason),
                'reopen' => $this->reopenAction($actor, $action, $reason),
                default => throw ValidationException::withMessages(['command' => 'Action操作が不正です。']),
            };
            $action->update($changes);
            $this->history->record($project, $action, $actor, 'action.'.$command, $before,
                $action->only(array_keys($changes)), $reason);
            if ($command === 'complete') {
                $this->notifications->markActionDone($action, $actor, \App\Models\CompanyNotification::TYPE_ACTION_ASSIGNED);
                $this->notifications->markActionDone($action, $actor, \App\Models\CompanyNotification::TYPE_ACTION_RETURNED);
            } elseif (in_array($command, ['confirm', 'reject'], true)) {
                $this->notifications->markActionDone($action, $actor, \App\Models\CompanyNotification::TYPE_REVIEW_ATTENTION);
            }
            if ($command === 'complete' && $action->status === Task::STATUS_REVIEW_PENDING) {
                $this->notifications->reviewAttention($actor, $action);
            } elseif ($command === 'reject') {
                $this->notifications->actionReturned($actor, $action);
            }

            return $action->fresh();
        }, 3);
    }

    public function moveAction(User $actor, Task $action, ?Improvement $theme, int $sortOrder, int $expectedProjectVersion): Task
    {
        return DB::transaction(function () use ($actor, $action, $theme, $sortOrder, $expectedProjectVersion): Task {
            $project = $this->lockProject($action->project, $expectedProjectVersion);
            $this->authorizeOwner($actor, $project);
            $action = Task::query()->lockForUpdate()->where('project_id', $project->id)->findOrFail($action->id);
            if ($theme) {
                $theme = Improvement::query()->lockForUpdate()->where('project_id', $project->id)
                    ->whereNotNull('roadmap_id')->findOrFail($theme->id);
            }
            $before = $action->only(['improvement_id', 'sort_order', 'due_date']);
            $action->update(['improvement_id' => $theme?->id, 'sort_order' => max(1, $sortOrder)]);
            $this->history->record($project, $action, $actor, 'action.moved', $before,
                $action->only(['improvement_id', 'sort_order', 'due_date']));

            return $action->fresh();
        }, 3);
    }

    public function completeParent(User $actor, Model $parent, int $expectedProjectVersion, int $confirmedProjectVersion): Model
    {
        return DB::transaction(function () use ($actor, $parent, $expectedProjectVersion, $confirmedProjectVersion): Model {
            $project = $parent instanceof Project ? $parent : $parent->project;
            $project = $this->lockProject($project, $expectedProjectVersion);
            $this->authorizeOwner($actor, $project);
            if ($confirmedProjectVersion !== $project->plan_version) {
                throw ValidationException::withMessages(['confirmation' => '確認後にProjectが変更されています。再確認してください。']);
            }
            $target = match (true) {
                $parent instanceof Project => $project,
                $parent instanceof Roadmap => Roadmap::query()->lockForUpdate()
                    ->where('project_id', $project->id)->findOrFail($parent->id),
                $parent instanceof Improvement => Improvement::query()->lockForUpdate()
                    ->where('project_id', $project->id)->findOrFail($parent->id),
                default => throw new AuthorizationException,
            };
            $before = $target->only(['status', 'execution_status', 'completion_check_version']);
            if ($target instanceof Project && $target->reviewer_user_id) {
                $target->update([
                    'review_status' => Project::REVIEW_PENDING,
                    'review_requested_by_user_id' => $actor->id,
                    'review_requested_at' => now(),
                    'completion_check_version' => $confirmedProjectVersion,
                ]);
            } elseif ($target instanceof Project) {
                $target->update([
                    'status' => Project::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'review_status' => Project::REVIEW_NOT_REQUIRED,
                    'completion_check_version' => $confirmedProjectVersion,
                ]);
            } elseif ($target instanceof Roadmap) {
                $target->update([
                    'status' => Roadmap::STATUS_COMPLETED,
                    'reached_at' => now()->toDateString(),
                    'completed_by_user_id' => $actor->id,
                    'completion_check_version' => $confirmedProjectVersion,
                ]);
            } else {
                $target->update([
                    'execution_status' => Improvement::EXECUTION_STATUS_COMPLETED,
                    'completed_at' => now()->toDateString(),
                    'completed_by_user_id' => $actor->id,
                    'completion_check_version' => $confirmedProjectVersion,
                ]);
            }
            $this->history->record($project, $target, $actor, 'parent.completed_or_requested',
                $before, $target->fresh()->only(['status', 'execution_status', 'review_status', 'completion_check_version']));

            return $target->fresh();
        }, 3);
    }

    public function reviewProject(User $actor, Project $project, bool $confirm, int $expectedVersion, ?string $reason = null): Project
    {
        return DB::transaction(function () use ($actor, $project, $confirm, $expectedVersion, $reason): Project {
            $locked = $this->lockProject($project, $expectedVersion);
            if ($locked->reviewer_user_id !== $actor->id
                || $locked->review_status !== Project::REVIEW_PENDING
                || ! $this->access->canBeReviewer($actor, $locked)) {
                throw new AuthorizationException;
            }
            if (! $confirm && blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
            }
            $before = $locked->only(['status', 'review_status', 'reviewed_by_user_id', 'reviewed_at']);
            $locked->update($confirm ? [
                'status' => Project::STATUS_COMPLETED,
                'completed_at' => now(),
                'review_status' => Project::REVIEW_CONFIRMED,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
            ] : [
                'status' => Project::STATUS_ACTIVE,
                'completed_at' => null,
                'review_status' => Project::REVIEW_REJECTED,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $this->history->record($locked, $locked, $actor,
                $confirm ? 'project.review_confirmed' : 'project.review_rejected',
                $before, $locked->only(['status', 'review_status', 'reviewed_by_user_id', 'reviewed_at']), $reason);

            return $locked->fresh();
        }, 3);
    }

    public function archive(User $actor, Model $entity, int $expectedProjectVersion, string $reason): void
    {
        DB::transaction(function () use ($actor, $entity, $expectedProjectVersion, $reason): void {
            $project = $entity instanceof Project ? $entity : $entity->project;
            $project = $this->lockProject($project, $expectedProjectVersion);
            $this->authorizeOwner($actor, $project);
            if (blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'A reason is required.']);
            }
            $target = $entity instanceof Project ? $project : $entity->newQuery()->lockForUpdate()
                ->where('project_id', $project->id)->findOrFail($entity->id);
            $this->history->record($project, $target, $actor, 'entity.archived', after: ['type' => class_basename($target)], reason: $reason);
            $target->delete();
        }, 3);
    }

    public function reopen(User $actor, Model $entity, Project $project, int $expectedProjectVersion, string $reason): Model
    {
        return DB::transaction(function () use ($actor, $entity, $project, $expectedProjectVersion, $reason): Model {
            $project = $this->lockProject($project, $expectedProjectVersion);
            $this->authorizeOwner($actor, $project);
            if (blank($reason) || ! method_exists($entity, 'restore')) {
                throw ValidationException::withMessages(['reason' => 'A reason is required.']);
            }
            $target = $entity->newQuery()->withTrashed()->where('project_id', $project->id)->findOrFail($entity->id);
            $target->restore();
            $this->history->record($project, $target, $actor, 'entity.reopened', after: ['type' => class_basename($target)], reason: $reason);

            return $target->fresh();
        }, 3);
    }

    private function transitionAsAssignee(User $actor, Task $action, array $from, array $changes): array
    {
        if (! $this->access->canExecuteAction($actor, $action) || ! in_array($action->status, $from, true)) {
            throw new AuthorizationException;
        }

        return $changes;
    }

    private function completeOrSubmit(User $actor, Task $action): array
    {
        if (! $this->access->canExecuteAction($actor, $action)
            || ! in_array($action->status, [Task::STATUS_TODO, Task::STATUS_IN_PROGRESS], true)) {
            throw new AuthorizationException;
        }
        if ($action->reviewer_user_id) {
            return [
                'status' => Task::STATUS_REVIEW_PENDING,
                'review_status' => Task::REVIEW_PENDING,
                'review_requested_by_user_id' => $actor->id,
                'review_requested_at' => now(),
                'completed_at' => null,
                'completed_by_user_id' => null,
            ];
        }

        return [
            'status' => Task::STATUS_DONE,
            'review_status' => Task::REVIEW_NOT_REQUIRED,
            'completed_at' => now(),
            'completed_by_user_id' => $actor->id,
        ];
    }

    private function reviewAction(User $actor, Task $action, bool $confirm, ?string $reason): array
    {
        if (! $this->access->canReviewAction($actor, $action)
            || $action->status !== Task::STATUS_REVIEW_PENDING) {
            throw new AuthorizationException;
        }
        if (! $confirm && blank($reason)) {
            throw ValidationException::withMessages(['reason' => '差戻し理由を入力してください。']);
        }

        return $confirm
            ? [
                'status' => Task::STATUS_DONE,
                'review_status' => Task::REVIEW_CONFIRMED,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'completed_by_user_id' => $action->assigned_to,
                'completed_at' => now(),
            ]
            : [
                'status' => Task::STATUS_IN_PROGRESS,
                'review_status' => Task::REVIEW_REJECTED,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'completed_by_user_id' => null,
                'completed_at' => null,
            ];
    }

    private function reopenAction(User $actor, Task $action, ?string $reason): array
    {
        if (! $this->access->canManageStructure($actor, $action->project)
            || $action->status !== Task::STATUS_DONE || blank($reason)) {
            throw new AuthorizationException;
        }

        return [
            'status' => Task::STATUS_IN_PROGRESS,
            'review_status' => $action->reviewer_user_id ? Task::REVIEW_REJECTED : Task::REVIEW_NOT_REQUIRED,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'completed_by_user_id' => null,
            'completed_at' => null,
            'reopened_by_user_id' => $actor->id,
            'reopened_at' => now(),
            'last_change_reason' => $reason,
        ];
    }

    private function lockProject(Project $project, int $expectedVersion): Project
    {
        $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
        if (! $locked->usesScopeEight() || $locked->plan_version !== $expectedVersion) {
            throw ValidationException::withMessages([
                'project_version' => 'Projectが変更されています。再読込してください。',
            ]);
        }

        return $locked;
    }

    private function authorizeOwner(User $actor, Project $project): void
    {
        if (! $this->access->canManageStructure($actor, $project)) {
            throw new AuthorizationException;
        }
    }

    private function assertActiveOrganizationUser(User $user, int $organizationId): OrganizationUser
    {
        $membership = OrganizationUser::query()->where('organization_id', $organizationId)
            ->where('user_id', $user->id)->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->lockForUpdate()->first();
        if (! $user->is_active || ! $membership) {
            throw ValidationException::withMessages(['user_id' => '同じOrganizationのactive Userを指定してください。']);
        }

        return $membership;
    }

    private function bumpProjectVersion(Project $project): void
    {
        DB::table('projects')->where('id', $project->id)->increment('plan_version');
        $project->refresh();
    }

    private function requireText(array $attributes, array $keys): void
    {
        foreach ($keys as $key) {
            if (blank($attributes[$key] ?? null)) {
                throw ValidationException::withMessages([$key => '必須項目です。']);
            }
        }
    }
}
