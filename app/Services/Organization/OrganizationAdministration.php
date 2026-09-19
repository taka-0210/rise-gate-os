<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationGroup;
use App\Models\OrganizationGroupMembership;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrganizationAdministration
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly OrganizationAudit $audit,
    ) {}

    public function updateRole(
        User $actor,
        Organization $organization,
        OrganizationUser $target,
        string $role,
    ): OrganizationUser {
        try {
            return DB::transaction(function () use ($actor, $organization, $target, $role): OrganizationUser {
                $this->lockOrganization($organization);
                $this->access->authorizeRoleChangeLocked($actor, $organization);
                $target = $this->lockedTarget($organization, $target);
                $this->assertActiveTarget($target);

                if (! array_key_exists($role, OrganizationUser::organizationRoles())) {
                    throw ValidationException::withMessages(['organization_role' => 'Organization Roleが不正です。']);
                }

                if ($target->organization_role === OrganizationUser::ORGANIZATION_ROLE_OWNER
                    && $role !== OrganizationUser::ORGANIZATION_ROLE_OWNER
                    && $this->activeOwnerCount($organization) <= 1) {
                    throw ValidationException::withMessages([
                        'organization_role' => '最後のactive Ownerは降格できません。',
                    ]);
                }

                $before = ['organization_role' => $target->organization_role];
                if ($target->organization_role === $role) {
                    $this->audit->record(
                        $organization,
                        $actor,
                        'organization.role.updated',
                        OrganizationAuditEvent::OUTCOME_NOOP,
                        $target->user,
                        before: $before,
                        after: $before,
                    );

                    return $target;
                }

                $target->organization_role = $role;
                $target->save();
                $this->audit->record(
                    $organization,
                    $actor,
                    'organization.role.updated',
                    OrganizationAuditEvent::OUTCOME_SUCCESS,
                    $target->user,
                    before: $before,
                    after: ['organization_role' => $role],
                );

                return $target;
            });
        } catch (AuthorizationException|ModelNotFoundException|ValidationException $exception) {
            $this->recordRejected($organization, $actor, 'organization.role.updated', $target, $exception);
            throw $exception;
        }
    }

    public function updatePosition(
        User $actor,
        Organization $organization,
        OrganizationUser $target,
        ?string $position,
    ): OrganizationUser {
        try {
            return DB::transaction(function () use ($actor, $organization, $target, $position): OrganizationUser {
                $this->lockOrganization($organization);
                $this->access->authorizeManageLocked($actor, $organization);
                $target = $this->lockedTarget($organization, $target);
                $this->assertActiveTarget($target);
                $position = filled($position) ? trim($position) : null;

                if ($position !== null && mb_strlen($position) > 100) {
                    throw ValidationException::withMessages(['position' => 'Positionは100文字以内で入力してください。']);
                }

                $before = ['position' => $target->position];
                $outcome = $target->position === $position
                    ? OrganizationAuditEvent::OUTCOME_NOOP
                    : OrganizationAuditEvent::OUTCOME_SUCCESS;

                if ($outcome === OrganizationAuditEvent::OUTCOME_SUCCESS) {
                    $target->position = $position;
                    $target->save();
                }
                $this->audit->record(
                    $organization,
                    $actor,
                    'organization.position.updated',
                    $outcome,
                    $target->user,
                    before: $before,
                    after: ['position' => $position],
                );

                return $target;
            });
        } catch (AuthorizationException|ModelNotFoundException|ValidationException $exception) {
            $this->recordRejected($organization, $actor, 'organization.position.updated', $target, $exception);
            throw $exception;
        }
    }

    public function createGroup(User $actor, Organization $organization, string $name): OrganizationGroup
    {
        return DB::transaction(function () use ($actor, $organization, $name): OrganizationGroup {
            $this->lockOrganization($organization);
            $this->access->authorizeManageLocked($actor, $organization);
            $name = $this->normalizedGroupName($organization, $name);
            $group = OrganizationGroup::create([
                'organization_id' => $organization->id,
                'name' => $name,
            ]);
            $this->audit->record(
                $organization,
                $actor,
                'organization.group.created',
                OrganizationAuditEvent::OUTCOME_SUCCESS,
                group: $group,
                after: ['name' => $name, 'archived' => false],
            );

            return $group;
        });
    }

    public function renameGroup(
        User $actor,
        Organization $organization,
        OrganizationGroup $group,
        string $name,
    ): OrganizationGroup {
        return DB::transaction(function () use ($actor, $organization, $group, $name): OrganizationGroup {
            $this->lockOrganization($organization);
            $this->access->authorizeManageLocked($actor, $organization);
            $group = $this->lockedGroup($organization, $group);
            $this->assertGroupActive($group);
            $name = $this->normalizedGroupName($organization, $name, $group->id);
            $before = ['name' => $group->name, 'archived' => false];
            $outcome = $group->name === $name
                ? OrganizationAuditEvent::OUTCOME_NOOP
                : OrganizationAuditEvent::OUTCOME_SUCCESS;

            if ($outcome === OrganizationAuditEvent::OUTCOME_SUCCESS) {
                $group->name = $name;
                $group->save();
            }
            $this->audit->record(
                $organization,
                $actor,
                'organization.group.renamed',
                $outcome,
                group: $group,
                before: $before,
                after: ['name' => $name, 'archived' => false],
            );

            return $group;
        });
    }

    public function archiveGroup(
        User $actor,
        Organization $organization,
        OrganizationGroup $group,
    ): OrganizationGroup {
        return DB::transaction(function () use ($actor, $organization, $group): OrganizationGroup {
            $this->lockOrganization($organization);
            $this->access->authorizeManageLocked($actor, $organization);
            $group = $this->lockedGroup($organization, $group);
            $this->assertGroupActive($group);

            if ($group->memberships()->exists()) {
                throw ValidationException::withMessages([
                    'group' => '所属メンバーがいるGroupは保管できません。先に所属を解除してください。',
                ]);
            }

            $before = ['name' => $group->name, 'archived' => false];
            $group->forceFill(['archived_at' => now(), 'archived_by' => $actor->id])->save();
            $this->audit->record(
                $organization,
                $actor,
                'organization.group.archived',
                OrganizationAuditEvent::OUTCOME_SUCCESS,
                group: $group,
                before: $before,
                after: ['name' => $group->name, 'archived' => true],
            );

            return $group;
        });
    }

    public function addGroupMember(
        User $actor,
        Organization $organization,
        OrganizationGroup $group,
        OrganizationUser $target,
    ): OrganizationGroupMembership {
        return DB::transaction(function () use ($actor, $organization, $group, $target): OrganizationGroupMembership {
            $this->lockOrganization($organization);
            $this->access->authorizeManageLocked($actor, $organization);
            $group = $this->lockedGroup($organization, $group);
            $this->assertGroupActive($group);
            $target = $this->lockedTarget($organization, $target);
            $this->assertActiveTarget($target);

            $existing = OrganizationGroupMembership::query()
                ->where('organization_group_id', $group->id)
                ->where('organization_user_id', $target->id)
                ->lockForUpdate()
                ->first();
            $membership = $existing ?: OrganizationGroupMembership::create([
                'organization_group_id' => $group->id,
                'organization_user_id' => $target->id,
                'added_by' => $actor->id,
            ]);
            $this->audit->record(
                $organization,
                $actor,
                'organization.group.member_added',
                $existing ? OrganizationAuditEvent::OUTCOME_NOOP : OrganizationAuditEvent::OUTCOME_SUCCESS,
                $target->user,
                $group,
                before: ['member' => $existing !== null],
                after: ['member' => true],
            );

            return $membership;
        });
    }

    public function removeGroupMember(
        User $actor,
        Organization $organization,
        OrganizationGroup $group,
        OrganizationUser $target,
    ): void {
        DB::transaction(function () use ($actor, $organization, $group, $target): void {
            $this->lockOrganization($organization);
            $this->access->authorizeManageLocked($actor, $organization);
            $group = $this->lockedGroup($organization, $group);
            $this->assertGroupActive($group);
            $target = $this->lockedTarget($organization, $target);
            $this->assertActiveTarget($target);

            $membership = OrganizationGroupMembership::query()
                ->where('organization_group_id', $group->id)
                ->where('organization_user_id', $target->id)
                ->lockForUpdate()
                ->first();
            $membership?->delete();
            $this->audit->record(
                $organization,
                $actor,
                'organization.group.member_removed',
                $membership ? OrganizationAuditEvent::OUTCOME_SUCCESS : OrganizationAuditEvent::OUTCOME_NOOP,
                $target->user,
                $group,
                before: ['member' => $membership !== null],
                after: ['member' => false],
            );
        });
    }

    private function lockOrganization(Organization $organization): void
    {
        Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
    }

    private function lockedTarget(Organization $organization, OrganizationUser $target): OrganizationUser
    {
        $target = OrganizationUser::query()->with('user')->lockForUpdate()->findOrFail($target->id);
        if ($target->organization_id !== $organization->id) {
            throw (new ModelNotFoundException)->setModel(OrganizationUser::class, [$target->id]);
        }

        return $target;
    }

    private function lockedGroup(Organization $organization, OrganizationGroup $group): OrganizationGroup
    {
        $group = OrganizationGroup::query()->lockForUpdate()->findOrFail($group->id);
        if ($group->organization_id !== $organization->id) {
            throw (new ModelNotFoundException)->setModel(OrganizationGroup::class, [$group->id]);
        }

        return $group;
    }

    private function assertActiveTarget(OrganizationUser $target): void
    {
        if ($target->membership_status !== OrganizationUser::STATUS_ACTIVE || ! $target->user?->is_active) {
            throw ValidationException::withMessages([
                'membership' => 'activeなOrganization所属だけを変更できます。',
            ]);
        }
    }

    private function assertGroupActive(OrganizationGroup $group): void
    {
        if ($group->archived_at) {
            throw ValidationException::withMessages(['group' => '保管済みGroupは変更できません。']);
        }
    }

    private function activeOwnerCount(Organization $organization): int
    {
        return $this->access->activeMembershipQuery($organization)
            ->where('organization_role', OrganizationUser::ORGANIZATION_ROLE_OWNER)
            ->lockForUpdate()
            ->get(['id'])
            ->count();
    }

    private function normalizedGroupName(Organization $organization, string $name, ?int $ignoreId = null): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => 'Group名は1〜100文字で入力してください。']);
        }

        $query = OrganizationGroup::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => '同じ名前のGroupが既にあります。']);
        }

        return $name;
    }

    private function recordRejected(
        Organization $organization,
        User $actor,
        string $event,
        OrganizationUser $target,
        Throwable $exception,
    ): void {
        try {
            $sameOrganization = $target->organization_id === $organization->id;
            $this->audit->record(
                $organization,
                $actor,
                $event,
                OrganizationAuditEvent::OUTCOME_REJECTED,
                $sameOrganization ? $target->user : null,
                metadata: ['reason' => class_basename($exception)],
            );
        } catch (Throwable) {
            // A rejected request must not expose audit storage failures to an unauthorized caller.
        }
    }
}
