<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StandardWorkspaceService
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly OrganizationAudit $audit,
    ) {}

    public function initialize(User $actor, Organization $organization): Workspace
    {
        return DB::transaction(function () use ($actor, $organization): Workspace {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $membership = $this->access->authorizeRoleChangeLocked($actor, $organization);
            if ($membership->organization_role !== OrganizationUser::ORGANIZATION_ROLE_OWNER) {
                abort(403);
            }

            if ($organization->standard_workspace_id) {
                $workspace = Workspace::query()->find($organization->standard_workspace_id);
                if ($workspace && $workspace->organization_id === $organization->id
                    && $workspace->status === Workspace::STATUS_ACTIVE) {
                    return $workspace;
                }
                throw ValidationException::withMessages([
                    'standard_workspace' => '標準Workspaceの設定が不整合です。自動で置き換えず管理者へ確認してください。',
                ]);
            }

            $name = $organization->name.' Standard';
            $workspace = Workspace::create([
                'organization_id' => $organization->id,
                'owner_user_id' => $actor->id,
                'name' => $name,
                'slug' => $this->uniqueSlug($organization, $name),
                'billing_type' => Workspace::BILLING_INCLUDED,
                'status' => Workspace::STATUS_ACTIVE,
                'type' => Workspace::TYPE_SHARED,
            ]);
            WorkspaceMember::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $actor->id,
                'role' => WorkspaceMember::ROLE_OWNER,
                'joined_at' => now(),
            ]);
            $organization->forceFill(['standard_workspace_id' => $workspace->id])->save();

            $this->audit->record(
                $organization,
                $actor,
                'organization.standard_workspace.initialized',
                OrganizationAuditEvent::OUTCOME_SUCCESS,
                after: ['workspace_public_id' => $workspace->public_id],
            );

            return $workspace;
        });
    }

    public function resolveLocked(Organization $organization): Workspace
    {
        $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
        if (! $organization->standard_workspace_id) {
            throw ValidationException::withMessages([
                'standard_workspace' => 'Ownerが標準Workspaceを初期設定してから受諾してください。',
            ]);
        }

        $workspace = Workspace::query()->lockForUpdate()->find($organization->standard_workspace_id);
        if (! $workspace || $workspace->organization_id !== $organization->id
            || $workspace->status !== Workspace::STATUS_ACTIVE
            || $workspace->type !== Workspace::TYPE_SHARED) {
            throw ValidationException::withMessages([
                'standard_workspace' => '標準Workspaceを安全に利用できません。自動で別Workspaceへ切り替えません。',
            ]);
        }

        return $workspace;
    }

    private function uniqueSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'standard-workspace';
        $slug = $base;
        $index = 2;
        while ($organization->workspaces()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }
}
