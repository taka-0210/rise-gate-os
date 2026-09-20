<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationGroup;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationInvitationAcceptance
{
    public function __construct(
        private readonly OrganizationInvitationClaim $claim,
        private readonly OrganizationInvitationMembershipWriter $writer,
        private readonly StandardWorkspaceService $standardWorkspace,
        private readonly OrganizationAudit $audit,
    ) {}

    public function prepare(Request $request, User $user): OrganizationUser
    {
        $snapshot = $this->claim->current($request);

        return DB::transaction(function () use ($snapshot, $user): OrganizationUser {
            Organization::query()->whereKey($snapshot->organization_id)->lockForUpdate()->firstOrFail();
            $invitation = OrganizationInvitation::query()->lockForUpdate()->findOrFail($snapshot->id);
            $this->assertInvitationSnapshot($snapshot, $invitation);
            $this->assertIdentity($invitation, $user, false);

            $membership = OrganizationUser::query()
                ->where('organization_id', $invitation->organization_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if ($membership && in_array($membership->membership_status, [
                OrganizationUser::STATUS_SUSPENDED,
                OrganizationUser::STATUS_LEFT,
            ], true)) {
                throw ValidationException::withMessages([
                    'invitation' => '停止・退職済みの所属は招待では復帰できません。',
                ]);
            }
            if ($membership?->membership_status === OrganizationUser::STATUS_ACTIVE) {
                $invitation->forceFill([
                    'claimed_user_id' => $user->id,
                    'organization_user_id' => $membership->id,
                ])->save();

                return $membership;
            }

            return $this->writer->prepare($invitation, $user);
        });
    }

    public function accept(Request $request, User $user): OrganizationInvitation
    {
        $snapshot = $this->claim->current($request, false, true);
        if ($snapshot->status === OrganizationInvitation::STATUS_ACCEPTED) {
            if ($snapshot->accepted_by_user_id !== $user->id) {
                throw new AuthorizationException;
            }

            return $snapshot;
        }

        $accepted = DB::transaction(function () use ($snapshot, $user): OrganizationInvitation {
            $organization = Organization::query()->lockForUpdate()->findOrFail($snapshot->organization_id);
            $invitation = OrganizationInvitation::query()->lockForUpdate()->findOrFail($snapshot->id);
            $this->assertInvitationSnapshot($snapshot, $invitation);
            $this->assertIdentity($invitation, $user, true);
            $this->assertSponsor($invitation);

            $membership = OrganizationUser::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $membership) {
                throw ValidationException::withMessages(['invitation' => '招待の所属準備を完了してください。']);
            }
            if (in_array($membership->membership_status, [OrganizationUser::STATUS_SUSPENDED, OrganizationUser::STATUS_LEFT], true)) {
                throw ValidationException::withMessages(['invitation' => '停止・退職済みの所属は招待では復帰できません。']);
            }

            if ($membership->membership_status === OrganizationUser::STATUS_ACTIVE) {
                $invitation->forceFill([
                    'status' => OrganizationInvitation::STATUS_ACCEPTED,
                    'pending_email_key' => null,
                    'claimed_user_id' => $user->id,
                    'organization_user_id' => $membership->id,
                    'accepted_by_user_id' => $user->id,
                    'accepted_at' => now(),
                ])->save();
                $this->audit->record(
                    $organization,
                    $user,
                    'organization.invitation.already_joined',
                    OrganizationAuditEvent::OUTCOME_NOOP,
                    $user,
                    metadata: ['invitation_public_id' => $invitation->public_id],
                );

                return $invitation;
            }
            if ($membership->membership_status !== OrganizationUser::STATUS_INVITED) {
                throw ValidationException::withMessages(['invitation' => 'この所属状態では招待を受諾できません。']);
            }

            $groupIds = $invitation->groups()->pluck('organization_groups.id')->all();
            $groups = OrganizationGroup::query()
                ->where('organization_id', $organization->id)
                ->whereNull('archived_at')
                ->whereIn('id', $groupIds)
                ->lockForUpdate()
                ->get();
            if ($groups->count() !== count($groupIds)) {
                throw ValidationException::withMessages(['invitation' => '予定Groupが変更されています。Ownerへ再送を依頼してください。']);
            }
            $workspace = $this->standardWorkspace->resolveLocked($organization);
            $this->writer->activate($invitation, $membership, $workspace, $groups);

            $invitation->forceFill([
                'status' => OrganizationInvitation::STATUS_ACCEPTED,
                'pending_email_key' => null,
                'claimed_user_id' => $user->id,
                'organization_user_id' => $membership->id,
                'accepted_by_user_id' => $user->id,
                'accepted_at' => now(),
            ])->save();
            $this->audit->record(
                $organization,
                $user,
                'organization.invitation.accepted',
                OrganizationAuditEvent::OUTCOME_SUCCESS,
                $user,
                metadata: [
                    'invitation_public_id' => $invitation->public_id,
                    'intended_role' => $invitation->intended_organization_role,
                    'group_count' => $groups->count(),
                    'workspace_public_id' => $workspace->public_id,
                ],
            );

            return $invitation;
        });

        return $accepted;
    }

    private function assertInvitationSnapshot(
        OrganizationInvitation $snapshot,
        OrganizationInvitation $invitation,
    ): void {
        if (! $invitation->isPending() || $invitation->isExpired()
            || $invitation->token_generation !== $snapshot->token_generation
            || ! hash_equals($invitation->token_hash, $snapshot->token_hash)) {
            throw ValidationException::withMessages(['invitation' => 'この招待は更新または失効しています。']);
        }
    }

    private function assertIdentity(OrganizationInvitation $invitation, User $user, bool $requireVerified): void
    {
        if (! $user->is_active
            || ! hash_equals($invitation->normalized_email, strtolower(trim($user->email)))
            || ($invitation->claimed_user_id && $invitation->claimed_user_id !== $user->id)) {
            throw new AuthorizationException;
        }
        if ($requireVerified && ! $user->email_verified_at) {
            throw ValidationException::withMessages(['email' => '現在のEmail確認を完了してから所属を開始してください。']);
        }
    }

    private function assertSponsor(OrganizationInvitation $invitation): void
    {
        $sponsor = User::query()->lockForUpdate()->find($invitation->sponsor_user_id);
        $membership = $sponsor ? OrganizationUser::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('user_id', $sponsor->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first() : null;
        $allowed = $sponsor?->is_active && $membership
            && ($invitation->intended_organization_role === OrganizationUser::ORGANIZATION_ROLE_MEMBER
                ? in_array($membership->organization_role, [OrganizationUser::ORGANIZATION_ROLE_OWNER, OrganizationUser::ORGANIZATION_ROLE_ADMIN], true)
                : $membership->organization_role === OrganizationUser::ORGANIZATION_ROLE_OWNER);
        if (! $allowed) {
            throw ValidationException::withMessages([
                'invitation' => '招待元の現在の権限を確認できません。適格なOwnerまたはAdminへ再送を依頼してください。',
            ]);
        }
    }
}
