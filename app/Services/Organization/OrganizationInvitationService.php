<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationGroup;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationInvitationOperation;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrganizationInvitationService
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly OrganizationAudit $audit,
        private readonly OrganizationInvitationMailer $mailer,
    ) {}

    public function issue(
        User $actor,
        Organization $organization,
        string $email,
        ?string $requestedRole,
        array $groupIds,
        string $requestId,
    ): OrganizationInvitation {
        $this->mailer->assertConfigured();
        $email = $this->normalizeEmail($email);
        $groupIds = $this->normalizeGroupIds($groupIds);
        $payloadHash = $this->payloadHash([$email, $requestedRole, $groupIds]);
        $token = null;

        $invitation = DB::transaction(function () use (
            $actor, $organization, $email, $requestedRole, $groupIds, $requestId, $payloadHash, &$token,
        ): OrganizationInvitation {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $actorMembership = $this->access->authorizeManageLocked($actor, $organization);
            $role = $this->intendedRole($actorMembership, $requestedRole);
            $groups = $this->groups($organization, $groupIds);

            $existingOperation = $this->existingOperation($organization, 'issue', $requestId, $payloadHash);
            if ($existingOperation) {
                return OrganizationInvitation::query()->findOrFail($existingOperation->organization_invitation_id);
            }

            $pending = OrganizationInvitation::query()
                ->where('organization_id', $organization->id)
                ->where('pending_email_key', $email)
                ->lockForUpdate()
                ->first();
            if ($pending) {
                throw ValidationException::withMessages([
                    'email' => 'このEmailには未完了の招待があります。再送または取消を利用してください。',
                ]);
            }

            $knownUser = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            $knownMembership = $knownUser ? OrganizationUser::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $knownUser->id)
                ->lockForUpdate()
                ->first() : null;
            if ($knownMembership && in_array($knownMembership->membership_status, [
                OrganizationUser::STATUS_SUSPENDED,
                OrganizationUser::STATUS_LEFT,
            ], true)) {
                throw ValidationException::withMessages([
                    'email' => '停止・退職済みの所属は招待では復帰できません。',
                ]);
            }

            $token = Str::random(64);
            $invitation = OrganizationInvitation::create([
                'organization_id' => $organization->id,
                'created_by_user_id' => $actor->id,
                'sponsor_user_id' => $actor->id,
                'normalized_email' => $email,
                'intended_organization_role' => $role,
                'status' => OrganizationInvitation::STATUS_PENDING,
                'pending_email_key' => $email,
                'token_hash' => hash('sha256', $token),
                'token_generation' => 1,
                'expires_at' => now()->addDays((int) config('invitation.expires_days')),
                'claimed_user_id' => $knownUser?->id,
                'organization_user_id' => $knownMembership?->id,
                'delivery_status' => OrganizationInvitation::DELIVERY_QUEUED,
                'delivery_requested_at' => now(),
            ]);
            $invitation->groups()->sync($groups->modelKeys());
            OrganizationInvitationOperation::create([
                'organization_id' => $organization->id,
                'organization_invitation_id' => $invitation->id,
                'actor_user_id' => $actor->id,
                'operation' => 'issue',
                'request_id' => $requestId,
                'payload_hash' => $payloadHash,
            ]);
            $this->audit->record(
                $organization,
                $actor,
                'organization.invitation.issued',
                OrganizationAuditEvent::OUTCOME_SUCCESS,
                metadata: [
                    'invitation_public_id' => $invitation->public_id,
                    'email_fingerprint' => hash('sha256', $email),
                    'intended_role' => $role,
                    'group_count' => $groups->count(),
                ],
            );

            return $invitation;
        });

        if ($token !== null) {
            $this->mailer->dispatch($invitation, $token);
        }

        return $invitation->refresh();
    }

    public function resend(
        User $actor,
        Organization $organization,
        OrganizationInvitation $invitation,
        string $requestId,
    ): OrganizationInvitation {
        $this->mailer->assertConfigured();
        $payloadHash = $this->payloadHash([$invitation->public_id]);
        $token = null;

        $invitation = DB::transaction(function () use (
            $actor, $organization, $invitation, $requestId, $payloadHash, &$token,
        ): OrganizationInvitation {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $actorMembership = $this->access->authorizeManageLocked($actor, $organization);
            $invitation = $this->lockedInvitation($organization, $invitation);
            $this->authorizeInvitationManagement($actorMembership, $invitation);

            $existingOperation = $this->existingOperation($organization, 'resend', $requestId, $payloadHash);
            if ($existingOperation) {
                return $invitation;
            }
            if (! $invitation->isPending()) {
                throw ValidationException::withMessages(['invitation' => '完了または取消済みの招待は再送できません。']);
            }
            if ($invitation->delivery_requested_at
                && $invitation->delivery_requested_at->gt(now()->subSeconds((int) config('invitation.resend_cooldown_seconds')))) {
                throw ValidationException::withMessages(['invitation' => '再送間隔が短すぎます。しばらく待ってから再試行してください。']);
            }

            $this->groups($organization, $invitation->groups()->pluck('organization_groups.id')->all());
            $token = Str::random(64);
            $invitation->forceFill([
                'sponsor_user_id' => $actor->id,
                'token_hash' => hash('sha256', $token),
                'token_generation' => $invitation->token_generation + 1,
                'expires_at' => now()->addDays((int) config('invitation.expires_days')),
                'delivery_status' => OrganizationInvitation::DELIVERY_QUEUED,
                'delivery_requested_at' => now(),
                'delivered_at' => null,
                'delivery_failed_at' => null,
            ])->save();
            OrganizationInvitationOperation::create([
                'organization_id' => $organization->id,
                'organization_invitation_id' => $invitation->id,
                'actor_user_id' => $actor->id,
                'operation' => 'resend',
                'request_id' => $requestId,
                'payload_hash' => $payloadHash,
            ]);
            $this->audit->record(
                $organization,
                $actor,
                'organization.invitation.resent',
                OrganizationAuditEvent::OUTCOME_SUCCESS,
                metadata: ['invitation_public_id' => $invitation->public_id, 'generation' => $invitation->token_generation],
            );

            return $invitation;
        });

        if ($token !== null) {
            $this->mailer->dispatch($invitation, $token);
        }

        return $invitation->refresh();
    }

    public function revoke(
        User $actor,
        Organization $organization,
        OrganizationInvitation $invitation,
        string $requestId,
    ): OrganizationInvitation {
        $payloadHash = $this->payloadHash([$invitation->public_id]);

        return DB::transaction(function () use ($actor, $organization, $invitation, $requestId, $payloadHash): OrganizationInvitation {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $actorMembership = $this->access->authorizeManageLocked($actor, $organization);
            $invitation = $this->lockedInvitation($organization, $invitation);
            $this->authorizeInvitationManagement($actorMembership, $invitation);

            $existingOperation = $this->existingOperation($organization, 'revoke', $requestId, $payloadHash);
            if ($existingOperation) {
                return $invitation;
            }
            $outcome = $invitation->isPending()
                ? OrganizationAuditEvent::OUTCOME_SUCCESS
                : OrganizationAuditEvent::OUTCOME_NOOP;
            if ($invitation->isPending()) {
                $invitation->forceFill([
                    'status' => OrganizationInvitation::STATUS_REVOKED,
                    'pending_email_key' => null,
                    'revoked_by_user_id' => $actor->id,
                    'revoked_at' => now(),
                    'token_generation' => $invitation->token_generation + 1,
                ])->save();
            }
            OrganizationInvitationOperation::create([
                'organization_id' => $organization->id,
                'organization_invitation_id' => $invitation->id,
                'actor_user_id' => $actor->id,
                'operation' => 'revoke',
                'request_id' => $requestId,
                'payload_hash' => $payloadHash,
            ]);
            $this->audit->record(
                $organization,
                $actor,
                'organization.invitation.revoked',
                $outcome,
                metadata: ['invitation_public_id' => $invitation->public_id],
            );

            return $invitation;
        });
    }

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function intendedRole(OrganizationUser $actor, ?string $requestedRole): string
    {
        if ($actor->organization_role === OrganizationUser::ORGANIZATION_ROLE_ADMIN) {
            if ($requestedRole !== null) {
                throw new AuthorizationException('Admin cannot submit an Organization Role.');
            }

            return OrganizationUser::ORGANIZATION_ROLE_MEMBER;
        }

        $role = $requestedRole ?: OrganizationUser::ORGANIZATION_ROLE_MEMBER;
        if (! array_key_exists($role, OrganizationUser::organizationRoles())) {
            throw ValidationException::withMessages(['organization_role' => 'Organization Roleが不正です。']);
        }

        return $role;
    }

    private function authorizeInvitationManagement(OrganizationUser $actor, OrganizationInvitation $invitation): void
    {
        if ($invitation->intended_organization_role !== OrganizationUser::ORGANIZATION_ROLE_MEMBER
            && $actor->organization_role !== OrganizationUser::ORGANIZATION_ROLE_OWNER) {
            throw new AuthorizationException('Only an Owner can manage a privileged invitation.');
        }
    }

    private function groups(Organization $organization, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }
        $groups = OrganizationGroup::query()
            ->where('organization_id', $organization->id)
            ->whereNull('archived_at')
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get();
        if ($groups->count() !== count($ids)) {
            throw ValidationException::withMessages(['group_ids' => '選択したGroupに利用できない項目があります。']);
        }

        return $groups;
    }

    private function lockedInvitation(Organization $organization, OrganizationInvitation $invitation): OrganizationInvitation
    {
        $invitation = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
        if ($invitation->organization_id !== $organization->id) {
            abort(404);
        }

        return $invitation;
    }

    private function existingOperation(
        Organization $organization,
        string $operation,
        string $requestId,
        string $payloadHash,
    ): ?OrganizationInvitationOperation {
        $record = OrganizationInvitationOperation::query()
            ->where('organization_id', $organization->id)
            ->where('operation', $operation)
            ->where('request_id', $requestId)
            ->lockForUpdate()
            ->first();
        if ($record && ! hash_equals($record->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages(['request_id' => '同じRequest IDを異なる操作内容には使用できません。']);
        }

        return $record;
    }

    private function normalizeGroupIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
