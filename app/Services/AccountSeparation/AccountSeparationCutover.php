<?php

namespace App\Services\AccountSeparation;

use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AccountAudit;
use App\Services\Organization\OrganizationAudit;
use App\Services\Organization\OrganizationInvitationService;
use App\Services\Organization\OrganizationMembershipLifecycle;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountSeparationCutover
{
    public const OLD_USER_ID = 1;

    public const KEEP_ORGANIZATION_ID = 1;

    public const TARGET_ORGANIZATION_ID = 4;

    public const KEEP_MEMBERSHIP_ID = 1;

    public const TARGET_MEMBERSHIP_ID = 5;

    public const STANDARD_WORKSPACE_ID = 5;

    public const OLD_ELIGIBILITY_ID = 1;

    public const NEW_EMAIL = 'takami@pro-chubo.com';

    public const NEW_NAME = '高見 昌也';

    public function __construct(
        private readonly OrganizationInvitationService $invitations,
        private readonly OrganizationMembershipLifecycle $lifecycle,
        private readonly OrganizationAudit $organizationAudit,
        private readonly AccountAudit $accountAudit,
    ) {}

    public function inventory(string $phase = 'dry-run'): array
    {
        $old = User::query()->findOrFail(self::OLD_USER_ID);
        $keepOrganization = Organization::query()->findOrFail(self::KEEP_ORGANIZATION_ID);
        $targetOrganization = Organization::query()->findOrFail(self::TARGET_ORGANIZATION_ID);
        $keepMembership = OrganizationUser::query()->findOrFail(self::KEEP_MEMBERSHIP_ID);
        $targetMembership = OrganizationUser::query()->findOrFail(self::TARGET_MEMBERSHIP_ID);
        $workspace = Workspace::query()->findOrFail(self::STANDARD_WORKSPACE_ID);
        $workspaceMembership = WorkspaceMember::query()
            ->where('workspace_id', self::STANDARD_WORKSPACE_ID)
            ->where('user_id', self::OLD_USER_ID)
            ->sole();
        $eligibility = ProductAccountEligibility::query()->findOrFail(self::OLD_ELIGIBILITY_ID);

        $inventory = [
            'old_user' => [
                'id' => $old->id,
                'is_active' => (bool) $old->is_active,
                'is_system_admin' => (bool) $old->is_system_admin,
            ],
            'keep' => [
                'organization_id' => $keepOrganization->id,
                'membership_id' => $keepMembership->id,
                'membership_user_id' => $keepMembership->user_id,
                'membership_status' => $keepMembership->membership_status,
            ],
            'target' => [
                'organization_id' => $targetOrganization->id,
                'membership_id' => $targetMembership->id,
                'membership_user_id' => $targetMembership->user_id,
                'membership_status' => $targetMembership->membership_status,
                'organization_role' => $targetMembership->organization_role,
                'legacy_role' => $targetMembership->role,
                'legacy_company_role' => $targetMembership->company_role,
                'permissions' => $targetMembership->permissions ?? [],
                'access_epoch' => $targetMembership->access_epoch,
                'lifecycle_version' => $targetMembership->lifecycle_version,
                'active_owner_count' => OrganizationUser::query()
                    ->where('organization_id', self::TARGET_ORGANIZATION_ID)
                    ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
                    ->where('organization_role', OrganizationUser::ORGANIZATION_ROLE_OWNER)
                    ->count(),
                'standard_workspace_id' => $targetOrganization->standard_workspace_id,
            ],
            'workspace' => [
                'id' => $workspace->id,
                'organization_id' => $workspace->organization_id,
                'status' => $workspace->status,
                'type' => $workspace->type,
                'owner_user_id' => $workspace->owner_user_id,
                'old_user_role' => $workspaceMembership->role,
            ],
            'eligibility' => [
                'id' => $eligibility->id,
                'user_id' => $eligibility->user_id,
                'mode' => $eligibility->mode,
                'product_organization_id' => $eligibility->product_organization_id,
                'classification_version' => $eligibility->classification_version,
                'compatibility_membership_ids' => $eligibility->compatibilities()
                    ->orderBy('organization_user_id')
                    ->pluck('organization_user_id')
                    ->all(),
            ],
            'email_collisions' => $this->emailCollisions(),
            'counts' => [
                'users' => User::query()->count(),
                'organizations' => Organization::query()->count(),
                'workspaces' => Workspace::query()->count(),
                'projects' => DB::table('projects')->count(),
            ],
        ];
        if (in_array($phase, ['dry-run', 'phase-a'], true)) {
            $this->assertInitialInventory($inventory);
        }

        return $inventory;
    }

    public function inventoryHash(array $inventory): string
    {
        return hash('sha256', json_encode($inventory, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function prepareStandardWorkspace(User $actor, string $caseId): array
    {
        return DB::transaction(function () use ($actor, $caseId): array {
            $organization = Organization::query()->lockForUpdate()->findOrFail(self::TARGET_ORGANIZATION_ID);
            $workspace = Workspace::query()->lockForUpdate()->findOrFail(self::STANDARD_WORKSPACE_ID);
            $membership = OrganizationUser::query()->lockForUpdate()->findOrFail(self::TARGET_MEMBERSHIP_ID);
            $this->assertOldOwner($actor, $membership);
            if ($workspace->organization_id !== $organization->id
                || $workspace->status !== Workspace::STATUS_ACTIVE
                || $workspace->type !== Workspace::TYPE_SHARED
                || $workspace->owner_user_id !== $actor->id) {
                throw new RuntimeException('Approved Standard Workspace precondition changed.');
            }
            if (! in_array($organization->standard_workspace_id, [null, $workspace->id], true)) {
                throw new RuntimeException('A different Standard Workspace is configured.');
            }

            $before = $organization->standard_workspace_id;
            if ($before === null) {
                $updated = Organization::query()
                    ->whereKey($organization->id)
                    ->whereNull('standard_workspace_id')
                    ->update(['standard_workspace_id' => $workspace->id, 'updated_at' => now()]);
                if ($updated !== 1) {
                    throw new RuntimeException('Standard Workspace conditional update failed.');
                }
                $this->organizationAudit->record(
                    $organization,
                    $actor,
                    'organization.standard_workspace.selected_existing',
                    OrganizationAuditEvent::OUTCOME_SUCCESS,
                    before: ['standard_workspace_id' => null],
                    after: ['standard_workspace_id' => $workspace->id],
                    metadata: ['case_id' => $caseId],
                );
            }

            return ['before' => $before, 'after' => $workspace->id, 'created_workspace' => false];
        }, 3);
    }

    public function issueInvitation(User $actor, string $caseId): OrganizationInvitation
    {
        if (! config('product_ux.organization_admission_enabled')) {
            throw new RuntimeException('Product Organization Admission must be enabled before invitation.');
        }

        return $this->invitations->issue(
            $actor,
            Organization::query()->findOrFail(self::TARGET_ORGANIZATION_ID),
            self::NEW_EMAIL,
            OrganizationUser::ORGANIZATION_ROLE_OWNER,
            [],
            $this->requestId($caseId, 'invitation'),
        );
    }

    public function cutover(string $caseId, int $newUserId, string $approvedInventoryHash): array
    {
        $payloadHash = $this->cutoverPayloadHash($caseId, $newUserId, $approvedInventoryHash);

        return DB::transaction(function () use ($caseId, $newUserId, $approvedInventoryHash, $payloadHash): array {
            $organization = Organization::query()->lockForUpdate()->findOrFail(self::TARGET_ORGANIZATION_ID);
            $workspace = Workspace::query()->lockForUpdate()->findOrFail(self::STANDARD_WORKSPACE_ID);
            $oldMembership = OrganizationUser::query()->lockForUpdate()->findOrFail(self::TARGET_MEMBERSHIP_ID);
            $newUser = User::query()->lockForUpdate()->findOrFail($newUserId);
            $newMembership = OrganizationUser::query()
                ->where('organization_id', self::TARGET_ORGANIZATION_ID)
                ->where('user_id', $newUserId)
                ->lockForUpdate()
                ->sole();
            $oldEligibility = ProductAccountEligibility::query()->lockForUpdate()->findOrFail(self::OLD_ELIGIBILITY_ID);
            $newEligibility = ProductAccountEligibility::query()
                ->where('user_id', $newUserId)
                ->lockForUpdate()
                ->sole();
            $newWorkspaceMembership = WorkspaceMember::query()
                ->where('workspace_id', self::STANDARD_WORKSPACE_ID)
                ->where('user_id', $newUserId)
                ->lockForUpdate()
                ->sole();
            $invitation = OrganizationInvitation::query()
                ->where('organization_id', self::TARGET_ORGANIZATION_ID)
                ->where('accepted_by_user_id', $newUserId)
                ->where('status', OrganizationInvitation::STATUS_ACCEPTED)
                ->lockForUpdate()
                ->latest('id')
                ->firstOrFail();

            $this->assertCutoverReady(
                $organization,
                $workspace,
                $oldMembership,
                $newUser,
                $newMembership,
                $oldEligibility,
                $newEligibility,
                $invitation,
            );
            $alreadyApplied = $oldMembership->membership_status === OrganizationUser::STATUS_SUSPENDED
                && $oldEligibility->mode === ProductAccountEligibility::MODE_SINGLE
                && $oldEligibility->product_organization_id === self::KEEP_ORGANIZATION_ID
                && $workspace->owner_user_id === $newUserId
                && $newWorkspaceMembership->role === WorkspaceMember::ROLE_OWNER
                && $newMembership->role === OrganizationUser::ROLE_OWNER
                && $newMembership->company_role === OrganizationUser::COMPANY_ROLE_OWNER;

            $approvedPermissions = OrganizationUser::query()->findOrFail(self::TARGET_MEMBERSHIP_ID)->permissions ?? [];
            $newMembership->forceFill([
                'role' => OrganizationUser::ROLE_OWNER,
                'company_role' => OrganizationUser::COMPANY_ROLE_OWNER,
                'permissions' => $approvedPermissions,
            ])->save();
            $newWorkspaceMembership->forceFill(['role' => WorkspaceMember::ROLE_OWNER])->save();
            $workspace->forceFill(['owner_user_id' => $newUserId])->save();

            $operation = $this->lifecycle->execute(
                $newUser,
                $organization,
                $oldMembership,
                OrganizationMembershipLifecycle::COMMAND_SUSPEND,
                'AS-G04 manifest '.substr($payloadHash, 0, 32),
                1,
                $this->requestId($caseId, 'cutover'),
            );
            $oldMembership->refresh();
            if ($oldMembership->membership_status !== OrganizationUser::STATUS_SUSPENDED) {
                throw new RuntimeException('Cutover lifecycle did not converge to suspended; a compensated case cannot be reused.');
            }
            if (! $alreadyApplied) {
                $updated = ProductAccountEligibility::query()
                    ->whereKey($oldEligibility->id)
                    ->where('mode', ProductAccountEligibility::MODE_LEGACY_MULTI)
                    ->whereNull('product_organization_id')
                    ->update([
                        'mode' => ProductAccountEligibility::MODE_SINGLE,
                        'product_organization_id' => self::KEEP_ORGANIZATION_ID,
                        'classified_at' => now(),
                        'evidence_ref' => 'account-separation:'.substr($payloadHash, 0, 48),
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Old eligibility conditional update failed.');
                }
                $this->accountAudit->record(
                    'account.separation.cutover',
                    'success',
                    $newUser,
                    $newUser,
                    [
                        'case_id' => $caseId,
                        'old_user_id' => self::OLD_USER_ID,
                        'keep_organization_id' => self::KEEP_ORGANIZATION_ID,
                        'target_organization_id' => self::TARGET_ORGANIZATION_ID,
                        'workspace_id' => self::STANDARD_WORKSPACE_ID,
                        'approved_inventory_hash' => $approvedInventoryHash,
                        'payload_hash' => $payloadHash,
                    ],
                );
            }

            return [
                'already_applied' => $alreadyApplied,
                'payload_hash' => $payloadHash,
                'lifecycle_operation_id' => $operation->id,
                'new_user_id' => $newUser->id,
                'new_membership_id' => $newMembership->id,
                'new_eligibility_id' => $newEligibility->id,
                'invitation_id' => $invitation->id,
            ];
        }, 3);
    }

    public function compensate(string $caseId, int $newUserId, string $approvedInventoryHash): array
    {
        $payloadHash = $this->cutoverPayloadHash($caseId, $newUserId, $approvedInventoryHash);

        return DB::transaction(function () use ($caseId, $newUserId, $payloadHash): array {
            $organization = Organization::query()->lockForUpdate()->findOrFail(self::TARGET_ORGANIZATION_ID);
            $workspace = Workspace::query()->lockForUpdate()->findOrFail(self::STANDARD_WORKSPACE_ID);
            $oldMembership = OrganizationUser::query()->lockForUpdate()->findOrFail(self::TARGET_MEMBERSHIP_ID);
            $newUser = User::query()->lockForUpdate()->findOrFail($newUserId);
            $newMembership = OrganizationUser::query()
                ->where('organization_id', self::TARGET_ORGANIZATION_ID)
                ->where('user_id', $newUserId)
                ->lockForUpdate()
                ->sole();
            $newWorkspaceMembership = WorkspaceMember::query()
                ->where('workspace_id', self::STANDARD_WORKSPACE_ID)
                ->where('user_id', $newUserId)
                ->lockForUpdate()
                ->sole();
            $oldEligibility = ProductAccountEligibility::query()->lockForUpdate()->findOrFail(self::OLD_ELIGIBILITY_ID);
            if ($oldMembership->membership_status !== OrganizationUser::STATUS_SUSPENDED
                || $oldEligibility->mode !== ProductAccountEligibility::MODE_SINGLE
                || $oldEligibility->product_organization_id !== self::KEEP_ORGANIZATION_ID) {
                throw new RuntimeException('Compensation precondition changed.');
            }

            $operation = $this->lifecycle->execute(
                $newUser,
                $organization,
                $oldMembership,
                OrganizationMembershipLifecycle::COMMAND_RESUME,
                'AS-G04 compensation '.substr($payloadHash, 0, 32),
                (int) $oldMembership->lifecycle_version,
                $this->requestId($caseId, 'compensate'),
            );
            $workspace->forceFill(['owner_user_id' => self::OLD_USER_ID])->save();
            $newWorkspaceMembership->forceFill(['role' => WorkspaceMember::ROLE_MEMBER])->save();
            $newMembership->forceFill([
                'role' => OrganizationUser::ROLE_MEMBER,
                'company_role' => OrganizationUser::COMPANY_ROLE_MEMBER,
                'permissions' => [],
            ])->save();
            $updated = ProductAccountEligibility::query()
                ->whereKey($oldEligibility->id)
                ->where('mode', ProductAccountEligibility::MODE_SINGLE)
                ->where('product_organization_id', self::KEEP_ORGANIZATION_ID)
                ->update([
                    'mode' => ProductAccountEligibility::MODE_LEGACY_MULTI,
                    'product_organization_id' => null,
                    'classified_at' => now(),
                    'evidence_ref' => 'account-separation-compensation:'.substr($payloadHash, 0, 35),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Compensation eligibility update failed.');
            }
            $this->accountAudit->record(
                'account.separation.compensated',
                'success',
                $newUser,
                $newUser,
                ['case_id' => $caseId, 'payload_hash' => $payloadHash],
            );

            return ['lifecycle_operation_id' => $operation->id, 'result_status' => $operation->result_status];
        }, 3);
    }

    public function finalize(string $caseId, int $newUserId): array
    {
        $organization = Organization::query()->findOrFail(self::TARGET_ORGANIZATION_ID);
        $target = OrganizationUser::query()->findOrFail(self::TARGET_MEMBERSHIP_ID);
        if ($target->membership_status !== OrganizationUser::STATUS_SUSPENDED) {
            throw new RuntimeException('Finalize requires the old target membership to be suspended.');
        }
        $operation = $this->lifecycle->execute(
            User::query()->findOrFail($newUserId),
            $organization,
            $target,
            OrganizationMembershipLifecycle::COMMAND_END,
            'AS-G04 Phase D accepted by Takami Masaya',
            (int) $target->lifecycle_version,
            $this->requestId($caseId, 'finalize'),
        );

        return ['lifecycle_operation_id' => $operation->id, 'result_status' => $operation->result_status];
    }

    private function emailCollisions(): array
    {
        $email = strtolower(self::NEW_EMAIL);

        return [
            'users' => User::query()->whereRaw('LOWER(email) = ?', [$email])->count(),
            'pending_email_changes' => DB::table('account_email_requests')
                ->whereRaw('LOWER(pending_email) = ?', [$email])
                ->count(),
            'pending_invitations' => OrganizationInvitation::query()
                ->where('pending_email_key', $email)
                ->where('status', OrganizationInvitation::STATUS_PENDING)
                ->count(),
        ];
    }

    private function assertInitialInventory(array $inventory): void
    {
        if ($inventory['old_user'] !== ['id' => 1, 'is_active' => true, 'is_system_admin' => true]
            || $inventory['keep']['membership_id'] !== self::KEEP_MEMBERSHIP_ID
            || $inventory['keep']['membership_user_id'] !== self::OLD_USER_ID
            || $inventory['keep']['membership_status'] !== OrganizationUser::STATUS_ACTIVE
            || $inventory['target']['membership_id'] !== self::TARGET_MEMBERSHIP_ID
            || $inventory['target']['membership_user_id'] !== self::OLD_USER_ID
            || $inventory['target']['membership_status'] !== OrganizationUser::STATUS_ACTIVE
            || $inventory['target']['organization_role'] !== OrganizationUser::ORGANIZATION_ROLE_OWNER
            || $inventory['target']['legacy_role'] !== OrganizationUser::ROLE_OWNER
            || $inventory['target']['legacy_company_role'] !== OrganizationUser::COMPANY_ROLE_OWNER
            || $inventory['target']['permissions'] !== []
            || $inventory['target']['access_epoch'] !== 1
            || $inventory['target']['lifecycle_version'] !== 1
            || $inventory['target']['active_owner_count'] !== 1
            || ! in_array($inventory['target']['standard_workspace_id'], [null, self::STANDARD_WORKSPACE_ID], true)
            || $inventory['workspace']['organization_id'] !== self::TARGET_ORGANIZATION_ID
            || $inventory['workspace']['status'] !== Workspace::STATUS_ACTIVE
            || $inventory['workspace']['type'] !== Workspace::TYPE_SHARED
            || $inventory['workspace']['owner_user_id'] !== self::OLD_USER_ID
            || $inventory['workspace']['old_user_role'] !== WorkspaceMember::ROLE_OWNER
            || $inventory['eligibility']['id'] !== self::OLD_ELIGIBILITY_ID
            || $inventory['eligibility']['user_id'] !== self::OLD_USER_ID
            || $inventory['eligibility']['mode'] !== ProductAccountEligibility::MODE_LEGACY_MULTI
            || $inventory['eligibility']['product_organization_id'] !== null
            || $inventory['eligibility']['compatibility_membership_ids'] !== [1, 5]
            || array_sum($inventory['email_collisions']) !== 0) {
            throw new RuntimeException('Account separation before inventory does not match the approved allowlist.');
        }
    }

    private function assertOldOwner(User $actor, OrganizationUser $membership): void
    {
        if ($actor->id !== self::OLD_USER_ID
            || ! $actor->is_active
            || $membership->organization_id !== self::TARGET_ORGANIZATION_ID
            || $membership->user_id !== $actor->id
            || $membership->membership_status !== OrganizationUser::STATUS_ACTIVE
            || $membership->organization_role !== OrganizationUser::ORGANIZATION_ROLE_OWNER) {
            throw new RuntimeException('Approved old Owner precondition changed.');
        }
    }

    private function assertCutoverReady(
        Organization $organization,
        Workspace $workspace,
        OrganizationUser $oldMembership,
        User $newUser,
        OrganizationUser $newMembership,
        ProductAccountEligibility $oldEligibility,
        ProductAccountEligibility $newEligibility,
        OrganizationInvitation $invitation,
    ): void {
        $retry = $oldMembership->membership_status === OrganizationUser::STATUS_SUSPENDED;
        if ($organization->standard_workspace_id !== self::STANDARD_WORKSPACE_ID
            || $workspace->organization_id !== self::TARGET_ORGANIZATION_ID
            || ! $newUser->is_active
            || $newUser->is_system_admin
            || strtolower(trim($newUser->email)) !== strtolower(self::NEW_EMAIL)
            || $newUser->name !== self::NEW_NAME
            || $newUser->email_verified_at === null
            || $newMembership->membership_status !== OrganizationUser::STATUS_ACTIVE
            || $newMembership->organization_role !== OrganizationUser::ORGANIZATION_ROLE_OWNER
            || $newEligibility->mode !== ProductAccountEligibility::MODE_SINGLE
            || $newEligibility->product_organization_id !== self::TARGET_ORGANIZATION_ID
            || $invitation->normalized_email !== strtolower(self::NEW_EMAIL)
            || $oldEligibility->id !== self::OLD_ELIGIBILITY_ID
            || ($retry
                ? $oldEligibility->mode !== ProductAccountEligibility::MODE_SINGLE
                    || $oldEligibility->product_organization_id !== self::KEEP_ORGANIZATION_ID
                : $oldEligibility->mode !== ProductAccountEligibility::MODE_LEGACY_MULTI
                    || $oldEligibility->product_organization_id !== null)) {
            throw new RuntimeException('Cutover precondition changed.');
        }
    }

    private function cutoverPayloadHash(string $caseId, int $newUserId, string $approvedInventoryHash): string
    {
        return hash('sha256', json_encode([
            'case_id' => $caseId,
            'old_user_id' => self::OLD_USER_ID,
            'new_user_id' => $newUserId,
            'keep_organization_id' => self::KEEP_ORGANIZATION_ID,
            'target_organization_id' => self::TARGET_ORGANIZATION_ID,
            'target_membership_id' => self::TARGET_MEMBERSHIP_ID,
            'standard_workspace_id' => self::STANDARD_WORKSPACE_ID,
            'approved_inventory_hash' => $approvedInventoryHash,
        ], JSON_THROW_ON_ERROR));
    }

    private function requestId(string $caseId, string $phase): string
    {
        $hex = substr(hash('sha256', $caseId.'|'.$phase), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
