<?php

namespace App\Services\Company;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PromoteClientToCompanyAccount
{
    public function promote(Client $client, User $owner, string $workspaceName): Workspace
    {
        if ($client->linked_organization_id) {
            throw new RuntimeException('このClientはすでに会社アカウントと関連付いています。');
        }

        return DB::transaction(function () use ($client, $owner, $workspaceName): Workspace {
            $organization = Organization::create([
                'name' => $client->name,
                'slug' => $this->uniqueOrganizationSlug($client->name),
            ]);

            $hasOwnedWorkspace = $owner->ownedWorkspaces()->exists();
            $workspace = Workspace::create([
                'organization_id' => $organization->id,
                'owner_user_id' => $owner->id,
                'name' => $workspaceName,
                'slug' => $this->uniqueWorkspaceSlug($organization, $workspaceName),
                'billing_type' => $hasOwnedWorkspace
                    ? Workspace::BILLING_ADDITIONAL
                    : Workspace::BILLING_INCLUDED,
                'status' => $owner->is_system_admin || ! $hasOwnedWorkspace
                    ? Workspace::STATUS_ACTIVE
                    : Workspace::STATUS_PENDING,
                'purpose' => 'COMPANY OSによる経営管理',
            ]);

            $organization->users()->attach($owner->id, [
                'role' => OrganizationUser::ROLE_OWNER,
                'company_role' => OrganizationUser::COMPANY_ROLE_OWNER,
                'joined_at' => now(),
            ]);
            $workspace->users()->attach($owner->id, [
                'role' => WorkspaceMember::ROLE_OWNER,
                'joined_at' => now(),
            ]);
            $client->update(['linked_organization_id' => $organization->id]);

            return $workspace;
        });
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $index = 2;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }

    private function uniqueWorkspaceSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $index = 2;

        while ($organization->workspaces()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }
}
