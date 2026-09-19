<?php

namespace App\Services;

use App\Models\AiAccessKey;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use Illuminate\Validation\ValidationException;

class AiProjectContextGuard
{
    public const SCOPE_ONE_CATEGORIES = ['project_metadata', 'roadmaps', 'improvements', 'tasks'];

    public function assertKeyIdentity(AiAccessKey $key): void
    {
        $key->loadMissing(['user', 'workspace.organization']);
        $user = $key->user;
        $workspace = $key->workspace;
        if (! $user || ! $user->is_active || ! $workspace || $workspace->status !== Workspace::STATUS_ACTIVE || ! $workspace->organization) {
            throw ValidationException::withMessages(['ai_context' => 'AI接続のUserまたはWorkspaceが無効です。']);
        }
        if (! $user->organizations()->where('organizations.id', $workspace->organization_id)->exists()
            || ! $user->workspaces()->where('workspaces.id', $workspace->id)->exists()) {
            throw ValidationException::withMessages(['ai_context' => '現在の所属ではこのWorkspaceをAIから参照できません。']);
        }
    }

    public function allowedCategories(AiAccessKey $key, Project $project): array
    {
        $this->assertKeyIdentity($key);
        if ($project->owning_workspace_id !== $key->workspace_id || $project->organization_id !== $key->workspace->organization_id) {
            throw ValidationException::withMessages(['ai_context' => '他の会社またはWorkspaceの情報はAIへ送信できません。']);
        }

        return $this->allowedCategoriesForUser($key->user, $project);
    }

    public function assertWorkspaceCategories(AiAccessKey $key, array $required = ['project_metadata']): array
    {
        $this->assertKeyIdentity($key);
        $setting = WorkspaceAiSetting::query()->where('workspace_id', $key->workspace_id)->first();
        if (! $setting?->enabled) {
            throw ValidationException::withMessages(['ai_context' => 'このWorkspaceではAIが無効です。']);
        }
        $allowed = array_values(array_intersect(self::SCOPE_ONE_CATEGORIES, $setting->allowed_data_categories ?? []));
        if (collect($required)->diff($allowed)->isNotEmpty()) {
            throw ValidationException::withMessages(['ai_context' => '必要なData CategoryのAI参照が許可されていません。']);
        }

        return $allowed;
    }

    public function allowedCategoriesForUser(User $user, Project $project): array
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages(['ai_context' => '現在のUserではAIを利用できません。']);
        }
        $workspace = Workspace::query()->whereKey($project->owning_workspace_id)
            ->where('organization_id', $project->organization_id)->where('status', Workspace::STATUS_ACTIVE)->first();
        if (! $workspace
            || ! $user->organizations()->where('organizations.id', $project->organization_id)->exists()
            || ! $user->workspaces()->where('workspaces.id', $workspace->id)->exists()) {
            throw ValidationException::withMessages(['ai_context' => '現在の所属ではこのProjectをAIから参照できません。']);
        }
        $member = $project->members()->where('user_id', $user->id)->where('status', ProjectMember::STATUS_ACTIVE)->first();
        if (! $member || $member->project_role === ProjectMember::ROLE_CLIENT) {
            throw ValidationException::withMessages(['ai_context' => 'このProjectを参照する権限がありません。']);
        }
        $setting = WorkspaceAiSetting::query()->where('workspace_id', $workspace->id)->first();
        if (! $setting?->enabled) {
            throw ValidationException::withMessages(['ai_context' => 'このWorkspaceではAIが無効です。']);
        }

        return array_values(array_intersect(self::SCOPE_ONE_CATEGORIES, $setting->allowed_data_categories ?? []));
    }

    public function assertProposalContext(AiAccessKey $key, Project $project, array $items = []): array
    {
        $allowed = $this->allowedCategories($key, $project);
        if (! in_array('project_metadata', $allowed, true)) {
            throw ValidationException::withMessages(['ai_context' => 'Project情報のAI参照が許可されていません。']);
        }
        $required = collect($items)
            ->pluck('entity_type')
            ->map(fn (string $type) => AiProposalContract::DATA_CATEGORY_BY_ENTITY[$type] ?? null)
            ->filter()
            ->unique();
        if ($required->diff($allowed)->isNotEmpty()) {
            throw ValidationException::withMessages(['ai_context' => 'この提案に必要なData CategoryのAI参照が許可されていません。']);
        }

        return $allowed;
    }
}
