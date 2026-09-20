<?php

namespace App\Services\Organization;

use App\Models\AiAccessKey;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembershipLifecycleOperation;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrganizationMembershipLifecycle
{
    public const COMMAND_SUSPEND = 'suspend';

    public const COMMAND_END = 'end';

    public const COMMAND_RESUME = 'resume';

    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly OrganizationAudit $audit,
    ) {}

    public function execute(
        User $actor,
        Organization $organization,
        OrganizationUser $target,
        string $command,
        string $reason,
        int $expectedVersion,
        string $requestId,
    ): OrganizationMembershipLifecycleOperation {
        $reason = trim($reason);
        $requestId = trim($requestId);
        $payloadHash = $this->payloadHash($target->id, $command, $reason, $expectedVersion);

        try {
            return DB::transaction(function () use (
                $actor,
                $organization,
                $target,
                $command,
                $reason,
                $expectedVersion,
                $requestId,
                $payloadHash,
            ): OrganizationMembershipLifecycleOperation {
                $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
                $actorMembership = $this->access->authorizeManageLocked($actor, $organization);
                $target = $this->lockedTarget($organization, $target);
                $this->validateInput($command, $reason, $expectedVersion, $requestId);
                $this->authorizeTarget($actorMembership, $target);

                $existing = OrganizationMembershipLifecycleOperation::query()
                    ->where('organization_id', $organization->id)
                    ->where('request_id', $requestId)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if (! hash_equals($existing->payload_hash, $payloadHash)) {
                        throw ValidationException::withMessages([
                            'request_id' => '同じRequest IDを異なる操作内容には使用できません。',
                        ]);
                    }

                    return $existing;
                }

                if ($target->lifecycle_version !== $expectedVersion) {
                    throw ValidationException::withMessages([
                        'expected_version' => '所属状態が別の操作で更新されています。画面を再読込してください。',
                    ]);
                }

                $nextStatus = $this->nextStatus($target, $command);
                if ($nextStatus === OrganizationUser::STATUS_ACTIVE && ! $target->user?->is_active) {
                    throw ValidationException::withMessages([
                        'membership' => '停止中のAccountはOrganization所属を再開できません。',
                    ]);
                }
                if ($target->organization_role === OrganizationUser::ORGANIZATION_ROLE_OWNER
                    && $target->membership_status === OrganizationUser::STATUS_ACTIVE
                    && $nextStatus !== OrganizationUser::STATUS_ACTIVE
                    && $this->activeOwnerCount($organization) <= 1) {
                    throw ValidationException::withMessages([
                        'membership' => '最後のactive Ownerは停止・退職にできません。',
                    ]);
                }

                $beforeStatus = $target->membership_status;
                $target->forceFill([
                    'membership_status' => $nextStatus,
                    'access_epoch' => $target->access_epoch + 1,
                    'lifecycle_version' => $target->lifecycle_version + 1,
                    'status_changed_at' => now(),
                    'status_changed_by_user_id' => $actor->id,
                    'status_change_reason' => $reason,
                ])->save();

                $revokedAiKeys = 0;
                $revokedInvitations = 0;
                if ($nextStatus !== OrganizationUser::STATUS_ACTIVE) {
                    $revokedAiKeys = $this->revokeAiKeys($organization, $target);
                    $revokedInvitations = $this->revokeInvitations($organization, $target, $actor);
                }

                $operation = OrganizationMembershipLifecycleOperation::create([
                    'organization_id' => $organization->id,
                    'organization_user_id' => $target->id,
                    'actor_user_id' => $actor->id,
                    'command' => $command,
                    'request_id' => $requestId,
                    'payload_hash' => $payloadHash,
                    'expected_version' => $expectedVersion,
                    'result_status' => $nextStatus,
                    'result_version' => $target->lifecycle_version,
                    'result_access_epoch' => $target->access_epoch,
                    'revoked_ai_key_count' => $revokedAiKeys,
                    'revoked_invitation_count' => $revokedInvitations,
                ]);

                $this->audit->record(
                    $organization,
                    $actor,
                    'organization.membership.'.$command,
                    OrganizationAuditEvent::OUTCOME_SUCCESS,
                    $target->user,
                    before: [
                        'membership_status' => $beforeStatus,
                        'organization_role' => $target->organization_role,
                        'lifecycle_version' => $expectedVersion,
                    ],
                    after: [
                        'membership_status' => $nextStatus,
                        'organization_role' => $target->organization_role,
                        'lifecycle_version' => $target->lifecycle_version,
                    ],
                    metadata: [
                        'request_id' => $requestId,
                        'reason' => $reason,
                        'access_epoch' => $target->access_epoch,
                        'revoked_ai_key_count' => $revokedAiKeys,
                        'revoked_invitation_count' => $revokedInvitations,
                    ],
                );

                return $operation;
            }, 3);
        } catch (AuthorizationException|ModelNotFoundException|ValidationException $exception) {
            $this->recordRejected($organization, $actor, $target, $command, $requestId, $exception);
            throw $exception;
        }
    }

    public function availableCommands(OrganizationUser $actor, OrganizationUser $target): array
    {
        if ($actor->membership_status !== OrganizationUser::STATUS_ACTIVE
            || $actor->user_id === $target->user_id
            || ! in_array($actor->organization_role, [
                OrganizationUser::ORGANIZATION_ROLE_OWNER,
                OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            ], true)
            || ($actor->organization_role === OrganizationUser::ORGANIZATION_ROLE_ADMIN
                && $target->organization_role !== OrganizationUser::ORGANIZATION_ROLE_MEMBER)) {
            return [];
        }

        return match ($target->membership_status) {
            OrganizationUser::STATUS_ACTIVE => [self::COMMAND_SUSPEND, self::COMMAND_END],
            OrganizationUser::STATUS_SUSPENDED => [self::COMMAND_RESUME, self::COMMAND_END],
            default => [],
        };
    }

    private function validateInput(string $command, string $reason, int $expectedVersion, string $requestId): void
    {
        if (! in_array($command, [self::COMMAND_SUSPEND, self::COMMAND_END, self::COMMAND_RESUME], true)) {
            throw ValidationException::withMessages(['command' => '所属状態の操作が不正です。']);
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => '理由は1〜500文字で入力してください。']);
        }
        if ($expectedVersion < 1) {
            throw ValidationException::withMessages(['expected_version' => '所属状態のVersionが不正です。']);
        }
        if ($requestId === '' || mb_strlen($requestId) > 64) {
            throw ValidationException::withMessages(['request_id' => 'Request IDが不正です。']);
        }
    }

    private function authorizeTarget(OrganizationUser $actor, OrganizationUser $target): void
    {
        if ($actor->id === $target->id) {
            throw new AuthorizationException('自分自身の所属状態は変更できません。');
        }
        if ($actor->organization_role === OrganizationUser::ORGANIZATION_ROLE_ADMIN
            && $target->organization_role !== OrganizationUser::ORGANIZATION_ROLE_MEMBER) {
            throw new AuthorizationException('Adminが操作できるのはMemberだけです。');
        }
    }

    private function nextStatus(OrganizationUser $target, string $command): string
    {
        return match ([$target->membership_status, $command]) {
            [OrganizationUser::STATUS_ACTIVE, self::COMMAND_SUSPEND] => OrganizationUser::STATUS_SUSPENDED,
            [OrganizationUser::STATUS_ACTIVE, self::COMMAND_END],
            [OrganizationUser::STATUS_SUSPENDED, self::COMMAND_END] => OrganizationUser::STATUS_LEFT,
            [OrganizationUser::STATUS_SUSPENDED, self::COMMAND_RESUME] => OrganizationUser::STATUS_ACTIVE,
            default => throw ValidationException::withMessages([
                'command' => '現在の所属状態ではこの操作を実行できません。',
            ]),
        };
    }

    private function lockedTarget(Organization $organization, OrganizationUser $target): OrganizationUser
    {
        $target = OrganizationUser::query()->with('user')->lockForUpdate()->findOrFail($target->id);
        if ($target->organization_id !== $organization->id) {
            throw (new ModelNotFoundException)->setModel(OrganizationUser::class, [$target->id]);
        }

        return $target;
    }

    private function activeOwnerCount(Organization $organization): int
    {
        return $this->access->activeMembershipQuery($organization)
            ->where('organization_role', OrganizationUser::ORGANIZATION_ROLE_OWNER)
            ->lockForUpdate()
            ->get(['organization_users.id'])
            ->count();
    }

    private function revokeAiKeys(Organization $organization, OrganizationUser $target): int
    {
        return AiAccessKey::query()
            ->where('user_id', $target->user_id)
            ->whereNull('revoked_at')
            ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organization->id))
            ->update(['revoked_at' => now()]);
    }

    private function revokeInvitations(Organization $organization, OrganizationUser $target, User $actor): int
    {
        $user = $target->user;
        $query = OrganizationInvitation::query()
            ->where('organization_id', $organization->id)
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->whereNull('revoked_at')
            ->where(function ($query) use ($target, $user): void {
                $query->where('sponsor_user_id', $target->user_id)
                    ->orWhere('claimed_user_id', $target->user_id)
                    ->orWhere('organization_user_id', $target->id);
                if ($user?->email_verified_at && filled($user->email)) {
                    $query->orWhere('normalized_email', strtolower(trim($user->email)));
                }
            })
            ->lockForUpdate();

        $invitations = $query->get();
        foreach ($invitations as $invitation) {
            $invitation->forceFill([
                'status' => OrganizationInvitation::STATUS_REVOKED,
                'pending_email_key' => null,
                'revoked_by_user_id' => $actor->id,
                'revoked_at' => now(),
                'token_generation' => $invitation->token_generation + 1,
            ])->save();
        }

        return $invitations->count();
    }

    private function payloadHash(int $targetId, string $command, string $reason, int $expectedVersion): string
    {
        return hash('sha256', json_encode([
            'target_id' => $targetId,
            'command' => $command,
            'reason' => $reason,
            'expected_version' => $expectedVersion,
        ], JSON_THROW_ON_ERROR));
    }

    private function recordRejected(
        Organization $organization,
        User $actor,
        OrganizationUser $target,
        string $command,
        string $requestId,
        Throwable $exception,
    ): void {
        try {
            $outcome = $exception instanceof ValidationException
                && array_key_exists('expected_version', $exception->errors())
                    ? OrganizationAuditEvent::OUTCOME_CONFLICT
                    : OrganizationAuditEvent::OUTCOME_REJECTED;
            $this->audit->record(
                $organization,
                $actor,
                'organization.membership.'.$command,
                $outcome,
                $target->organization_id === $organization->id ? $target->user : null,
                metadata: [
                    'request_id' => $requestId,
                    'reason_code' => class_basename($exception),
                ],
            );
        } catch (Throwable) {
            // Rejected requests must not expose audit storage failures.
        }
    }
}
