<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationUser;
use App\Models\OwnerOnboarding;
use App\Models\User;
use App\Services\AccountAudit;
use App\Services\ProductOrganization\ProductOrganizationAdmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OwnerOnboardingJourney
{
    public function __construct(
        private readonly OwnerOnboardingClaim $claim,
        private readonly OwnerOnboardingLegal $legal,
        private readonly OwnerOnboardingAudit $audit,
        private readonly OrganizationAudit $organizationAudit,
        private readonly StandardWorkspaceService $standardWorkspace,
        private readonly AccountAudit $accountAudit,
        private readonly ProductOrganizationAdmission $productAdmission,
    ) {}

    public function register(Request $request, string $name, string $password): User
    {
        $this->legal->assertReady();
        $snapshot = $this->claim->current($request);
        if (User::query()->whereRaw('LOWER(email) = ?', [$snapshot->normalized_email])->exists()) {
            throw ValidationException::withMessages(['email' => 'Accountが存在します。既存AccountでLoginしてください。']);
        }

        try {
            return DB::transaction(function () use ($snapshot, $name, $password): User {
                $onboarding = OwnerOnboarding::query()->lockForUpdate()->findOrFail($snapshot->id);
                $this->assertSnapshot($snapshot, $onboarding);
                $this->assertIssuer($onboarding);
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $onboarding->normalized_email,
                    'password' => $password,
                    'is_system_admin' => false,
                    'is_active' => true,
                ]);
                $this->productAdmission->registerUnstarted($user, 'provenance:owner_onboarding');
                $this->legal->record($user, $onboarding);
                $onboarding->forceFill(['claimed_user_id' => $user->id])->save();
                $this->audit->record($onboarding, $user, 'owner_onboarding.account_created', 'success', $user, [
                    'email_fingerprint' => hash('sha256', $onboarding->normalized_email),
                ]);
                $this->accountAudit->record('account.owner_onboarding_created', 'success', $user, $user, [
                    'onboarding_case' => $onboarding->public_id,
                ]);

                return $user;
            });
        } catch (QueryException $exception) {
            if (User::query()->whereRaw('LOWER(email) = ?', [$snapshot->normalized_email])->exists()) {
                throw ValidationException::withMessages(['email' => 'Accountが作成済みです。既存AccountでLoginしてください。']);
            }
            throw $exception;
        }
    }

    public function prepare(Request $request, User $user): OwnerOnboarding
    {
        $this->legal->assertReady();
        $snapshot = $this->claim->current($request);
        $this->productAdmission->assertMayStartNewOrganization(
            $user,
            ProductOrganizationAdmission::ENTRY_OWNER_PREPARE,
        );

        return DB::transaction(function () use ($snapshot, $user): OwnerOnboarding {
            $onboarding = OwnerOnboarding::query()->lockForUpdate()->findOrFail($snapshot->id);
            $this->assertSnapshot($snapshot, $onboarding);
            $this->assertIssuer($onboarding);
            $this->assertIdentity($onboarding, $user, false);
            $this->legal->record($user, $onboarding);
            $onboarding->forceFill(['claimed_user_id' => $user->id])->save();
            $this->audit->record($onboarding, $user, 'owner_onboarding.account_bound', 'success', $user, [
                'legal_signature' => $this->legalSignature(),
            ]);

            return $onboarding;
        });
    }

    public function complete(Request $request, User $user): OwnerOnboarding
    {
        $this->legal->assertReady();
        $snapshot = $this->claim->current($request, false, true);

        return $this->productAdmission->admitNewOrganization(
            $user,
            ProductOrganizationAdmission::ENTRY_OWNER_COMPLETE,
            function () use ($snapshot, $user): array {
                $result = DB::transaction(function () use ($snapshot, $user): OwnerOnboarding {
                    $onboarding = OwnerOnboarding::query()->lockForUpdate()->findOrFail($snapshot->id);
                    if ($onboarding->status === OwnerOnboarding::STATUS_COMPLETED) {
                        return $this->completedResult($onboarding, $user);
                    }
                    $this->assertSnapshot($snapshot, $onboarding);
                    $this->assertIssuer($onboarding);
                    $this->assertIdentity($onboarding, $user, true);
                    if (! $this->legal->hasCurrentConsent($user, $onboarding)) {
                        throw ValidationException::withMessages(['consent' => '現行の利用規約とPrivacyへの同意を確認してください。']);
                    }

                    $payloadHash = $this->completionPayloadHash($onboarding, $user);
                    $organization = Organization::query()->create([
                        'name' => $onboarding->organization_name,
                        'slug' => $this->uniqueOrganizationSlug($onboarding->organization_name),
                        'personal_workspace_creation_enabled' => false,
                    ]);
                    $this->failpoint('organization');

                    $membership = OrganizationUser::query()->create([
                        'organization_id' => $organization->id,
                        'user_id' => $user->id,
                        'role' => OrganizationUser::ROLE_MEMBER,
                        'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
                        'membership_status' => OrganizationUser::STATUS_ACTIVE,
                        'company_role' => OrganizationUser::COMPANY_ROLE_MEMBER,
                        'permissions' => [],
                        'joined_at' => now(),
                    ]);
                    $this->failpoint('membership');

                    $workspace = $this->standardWorkspace->initialize($user, $organization);
                    $this->failpoint('workspace');

                    $onboarding->forceFill([
                        'status' => OwnerOnboarding::STATUS_COMPLETED,
                        'completed_organization_id' => $organization->id,
                        'completed_payload_hash' => $payloadHash,
                        'completed_at' => now(),
                        'pending_case_key' => null,
                    ])->save();
                    $this->failpoint('result');

                    $this->audit->record($onboarding, $user, 'owner_onboarding.completed', 'success', $user, [
                        'organization_public_id' => $organization->public_id,
                        'workspace_public_id' => $workspace->public_id,
                        'membership_id' => $membership->id,
                    ]);
                    $this->organizationAudit->record(
                        $organization,
                        $user,
                        'organization.owner_onboarding.completed',
                        OrganizationAuditEvent::OUTCOME_SUCCESS,
                        $user,
                        metadata: ['onboarding_case_public_id' => $onboarding->public_id],
                    );
                    $this->failpoint('audit');

                    return $onboarding->refresh();
                }, 3);

                return [
                    'result' => $result,
                    'organization' => Organization::query()->findOrFail($result->completed_organization_id),
                ];
            },
            function () use ($snapshot, $user): ?Organization {
                $onboarding = OwnerOnboarding::query()->lockForUpdate()->findOrFail($snapshot->id);
                if ($onboarding->status !== OwnerOnboarding::STATUS_COMPLETED) {
                    return null;
                }

                $this->completedResult($onboarding, $user);

                return Organization::query()->findOrFail($onboarding->completed_organization_id);
            },
        );
    }

    private function completedResult(OwnerOnboarding $onboarding, User $user): OwnerOnboarding
    {
        if ($onboarding->claimed_user_id !== $user->id
            || ! $user->is_active
            || ! hash_equals((string) $onboarding->completed_payload_hash, $this->completionPayloadHash($onboarding, $user))) {
            throw new AuthorizationException;
        }
        $membership = OrganizationUser::query()
            ->where('organization_id', $onboarding->completed_organization_id)
            ->where('user_id', $user->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->first();
        if (! $membership) {
            throw ValidationException::withMessages(['onboarding' => '現在のOrganization所属が停止または終了しています。開始案内から復帰はできません。']);
        }

        return $onboarding;
    }

    private function assertSnapshot(OwnerOnboarding $snapshot, OwnerOnboarding $onboarding): void
    {
        if (! $onboarding->isIssued() || $onboarding->isExpired()
            || $onboarding->token_generation !== $snapshot->token_generation
            || ! hash_equals($onboarding->token_hash, $snapshot->token_hash)) {
            throw ValidationException::withMessages(['onboarding' => 'この開始案内は更新または失効しています。']);
        }
    }

    private function assertIssuer(OwnerOnboarding $onboarding): void
    {
        $issuer = User::query()->lockForUpdate()->find($onboarding->issued_by_user_id);
        if (! $issuer?->is_active || ! $issuer?->is_system_admin) {
            throw ValidationException::withMessages(['onboarding' => '開始承認者の現在の権限を確認できません。System Adminへ再送を依頼してください。']);
        }
    }

    private function assertIdentity(OwnerOnboarding $onboarding, User $user, bool $requireVerified): void
    {
        if (! $user->is_active
            || ! hash_equals($onboarding->normalized_email, Str::lower(trim($user->email)))
            || ($onboarding->claimed_user_id && $onboarding->claimed_user_id !== $user->id)) {
            throw new AuthorizationException;
        }
        if ($requireVerified && ! $user->email_verified_at) {
            throw ValidationException::withMessages(['email' => '現在のEmail確認を完了してから会社を開始してください。']);
        }
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';

        return $base.'-'.Str::lower((string) Str::ulid());
    }

    private function completionPayloadHash(OwnerOnboarding $onboarding, User $user): string
    {
        return hash('sha256', json_encode([
            'case' => $onboarding->public_id,
            'user' => $user->id,
            'organization_name' => $onboarding->organization_name,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function legalSignature(): string
    {
        return $this->legal->signature();
    }

    private function failpoint(string $step): void
    {
        if (app()->environment('testing') && config('owner_onboarding.fail_after_step') === $step) {
            throw new \RuntimeException('Owner onboarding failpoint: '.$step);
        }
    }
}
