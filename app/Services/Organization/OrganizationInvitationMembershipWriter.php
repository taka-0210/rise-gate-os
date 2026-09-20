<?php

namespace App\Services\Organization;

use App\Models\OrganizationGroup;
use App\Models\OrganizationGroupMembership;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

class OrganizationInvitationMembershipWriter
{
    public function prepare(OrganizationInvitation $invitation, User $user): OrganizationUser
    {
        $membership = OrganizationUser::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (! $membership) {
            $membership = OrganizationUser::create([
                'organization_id' => $invitation->organization_id,
                'user_id' => $user->id,
                'role' => OrganizationUser::ROLE_MEMBER,
                'organization_role' => null,
                'membership_status' => OrganizationUser::STATUS_INVITED,
                'company_role' => OrganizationUser::COMPANY_ROLE_MEMBER,
                'permissions' => [],
                'joined_at' => null,
            ]);
        }

        $invitation->forceFill([
            'claimed_user_id' => $user->id,
            'organization_user_id' => $membership->id,
        ])->save();

        return $membership;
    }

    public function activate(
        OrganizationInvitation $invitation,
        OrganizationUser $membership,
        Workspace $workspace,
        iterable $groups,
    ): void {
        $membership->forceFill([
            'organization_role' => $invitation->intended_organization_role,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ])->save();

        foreach ($groups as $group) {
            /** @var OrganizationGroup $group */
            OrganizationGroupMembership::query()->firstOrCreate([
                'organization_group_id' => $group->id,
                'organization_user_id' => $membership->id,
            ], [
                'added_by' => $invitation->sponsor_user_id,
            ]);
        }

        WorkspaceMember::query()->firstOrCreate([
            'workspace_id' => $workspace->id,
            'user_id' => $membership->user_id,
        ], [
            'role' => WorkspaceMember::ROLE_MEMBER,
            'joined_at' => now(),
        ]);
    }
}
