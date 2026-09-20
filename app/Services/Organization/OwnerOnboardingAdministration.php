<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\OwnerOnboarding;
use App\Models\OwnerOnboardingOperation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OwnerOnboardingAdministration
{
    public function __construct(
        private readonly OwnerOnboardingAudit $audit,
        private readonly OwnerOnboardingMailer $mailer,
    ) {}

    public function issue(
        User $actor,
        string $email,
        string $organizationName,
        string $duplicateDecision,
        ?string $distinctReason,
        string $requestId,
    ): OwnerOnboarding {
        $this->authorize($actor);
        $this->mailer->assertConfigured();
        $email = Str::lower(trim($email));
        $organizationName = trim(preg_replace('/\s+/u', ' ', $organizationName) ?? $organizationName);
        $normalizedName = $this->normalizeName($organizationName);
        $payloadHash = $this->payloadHash([$email, $organizationName, $duplicateDecision, $distinctReason]);
        $existingOperation = OwnerOnboardingOperation::query()
            ->where('operation', 'issue')->where('request_id', $requestId)->first();
        if ($existingOperation) {
            if (! hash_equals($existingOperation->payload_hash, $payloadHash)) {
                throw ValidationException::withMessages(['request_id' => '同じRequest IDを異なる開始内容には使用できません。']);
            }

            return OwnerOnboarding::query()->findOrFail($existingOperation->owner_onboarding_id);
        }
        $this->assertDuplicateDecision($normalizedName, $email, $duplicateDecision, $distinctReason);
        $token = Str::random(64);

        try {
            $onboarding = DB::transaction(function () use (
                $actor, $email, $organizationName, $normalizedName, $duplicateDecision, $distinctReason, $requestId, $token, $payloadHash,
            ): OwnerOnboarding {
                $existing = OwnerOnboardingOperation::query()
                    ->where('operation', 'issue')->where('request_id', $requestId)->lockForUpdate()->first();
                if ($existing) {
                    if (! hash_equals($existing->payload_hash, $payloadHash)) {
                        throw ValidationException::withMessages(['request_id' => '同じRequest IDを異なる開始内容には使用できません。']);
                    }

                    return OwnerOnboarding::query()->findOrFail($existing->owner_onboarding_id);
                }

                $onboarding = OwnerOnboarding::query()->create([
                    'issued_by_user_id' => $actor->id,
                    'normalized_email' => $email,
                    'organization_name' => $organizationName,
                    'normalized_organization_name' => $normalizedName,
                    'pending_case_key' => hash('sha256', $normalizedName.'|'.$email.'|'.(
                        $duplicateDecision === 'distinct_company' ? $requestId : 'same-company'
                    )),
                    'duplicate_decision' => $duplicateDecision,
                    'distinct_company_reason' => $distinctReason,
                    'status' => OwnerOnboarding::STATUS_ISSUED,
                    'token_hash' => hash('sha256', $token),
                    'token_generation' => 1,
                    'expires_at' => now()->addDays((int) config('owner_onboarding.expires_days')),
                    'delivery_status' => OwnerOnboarding::DELIVERY_QUEUED,
                    'delivery_requested_at' => now(),
                ]);
                OwnerOnboardingOperation::query()->create([
                    'owner_onboarding_id' => $onboarding->id,
                    'actor_user_id' => $actor->id,
                    'operation' => 'issue',
                    'request_id' => $requestId,
                    'payload_hash' => $payloadHash,
                ]);
                $this->audit->record($onboarding, $actor, 'owner_onboarding.issued', 'success', metadata: [
                    'case_public_id' => $onboarding->public_id,
                    'email_fingerprint' => hash('sha256', $email),
                    'duplicate_decision' => $duplicateDecision,
                ]);

                return $onboarding;
            });
        } catch (QueryException $exception) {
            $payloadHash = $this->payloadHash([$email, $organizationName, $duplicateDecision, $distinctReason]);
            $operation = OwnerOnboardingOperation::query()->where('operation', 'issue')->where('request_id', $requestId)->first();
            if (! $operation && OwnerOnboarding::query()->where(
                'pending_case_key',
                hash('sha256', $normalizedName.'|'.$email.'|same-company'),
            )->exists()) {
                throw ValidationException::withMessages(['email' => '同じ会社・Owner Emailの未完了案件があります。既存案件を再送してください。']);
            }
            if (! $operation || ! hash_equals($operation->payload_hash, $payloadHash)) {
                throw $exception;
            }
            $onboarding = OwnerOnboarding::query()->findOrFail($operation->owner_onboarding_id);
        }

        if ($onboarding->wasRecentlyCreated) {
            $this->dispatchOrRecordFailure($onboarding, $actor, $token);
        }

        return $onboarding->refresh();
    }

    public function resend(User $actor, OwnerOnboarding $onboarding, string $requestId): OwnerOnboarding
    {
        $this->authorize($actor);
        $this->mailer->assertConfigured();
        $token = Str::random(64);
        $send = false;
        $onboarding = DB::transaction(function () use ($actor, $onboarding, $requestId, $token, &$send): OwnerOnboarding {
            $onboarding = OwnerOnboarding::query()->lockForUpdate()->findOrFail($onboarding->id);
            $payloadHash = $this->payloadHash([$onboarding->public_id]);
            $existing = $this->existingOperation($onboarding, 'resend', $requestId, $payloadHash);
            if ($existing) {
                return $onboarding;
            }
            if (! $onboarding->isIssued()) {
                throw ValidationException::withMessages(['onboarding' => '完了または取消済みの開始案内は再送できません。']);
            }
            if ($onboarding->delivery_status !== OwnerOnboarding::DELIVERY_FAILED
                && $onboarding->delivery_requested_at
                && $onboarding->delivery_requested_at->gt(now()->subSeconds((int) config('owner_onboarding.resend_cooldown_seconds')))) {
                throw ValidationException::withMessages(['onboarding' => '再送間隔が短すぎます。']);
            }
            $onboarding->forceFill([
                'issued_by_user_id' => $actor->id,
                'token_hash' => hash('sha256', $token),
                'token_generation' => $onboarding->token_generation + 1,
                'expires_at' => now()->addDays((int) config('owner_onboarding.expires_days')),
                'delivery_status' => OwnerOnboarding::DELIVERY_QUEUED,
                'delivery_requested_at' => now(),
                'delivered_at' => null,
                'delivery_failed_at' => null,
            ])->save();
            $this->operation($onboarding, $actor, 'resend', $requestId, $payloadHash);
            $this->audit->record($onboarding, $actor, 'owner_onboarding.resent', 'success', metadata: ['generation' => $onboarding->token_generation]);
            $send = true;

            return $onboarding;
        });
        if ($send) {
            $this->dispatchOrRecordFailure($onboarding, $actor, $token);
        }

        return $onboarding->refresh();
    }

    public function revoke(User $actor, OwnerOnboarding $onboarding, string $requestId): OwnerOnboarding
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $onboarding, $requestId): OwnerOnboarding {
            $onboarding = OwnerOnboarding::query()->lockForUpdate()->findOrFail($onboarding->id);
            $payloadHash = $this->payloadHash([$onboarding->public_id]);
            if ($this->existingOperation($onboarding, 'revoke', $requestId, $payloadHash)) {
                return $onboarding;
            }
            if ($onboarding->isIssued()) {
                $onboarding->forceFill([
                    'status' => OwnerOnboarding::STATUS_REVOKED,
                    'revoked_by_user_id' => $actor->id,
                    'revoked_at' => now(),
                    'token_generation' => $onboarding->token_generation + 1,
                    'pending_case_key' => null,
                ])->save();
            }
            $this->operation($onboarding, $actor, 'revoke', $requestId, $payloadHash);
            $this->audit->record($onboarding, $actor, 'owner_onboarding.revoked', 'success');

            return $onboarding;
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->is_system_admin, 403);
    }

    private function dispatchOrRecordFailure(OwnerOnboarding $onboarding, User $actor, string $token): void
    {
        try {
            $this->mailer->dispatch($onboarding, $token);
        } catch (Throwable) {
            DB::transaction(function () use ($onboarding, $actor): void {
                $current = OwnerOnboarding::query()->lockForUpdate()->find($onboarding->id);
                if (! $current?->isIssued() || $current->token_generation !== $onboarding->token_generation) {
                    return;
                }
                $current->forceFill([
                    'delivery_status' => OwnerOnboarding::DELIVERY_FAILED,
                    'delivery_failed_at' => now(),
                ])->save();
                $this->audit->record($current, $actor, 'owner_onboarding.mail_enqueue_failed', 'failed', metadata: [
                    'generation' => $current->token_generation,
                ]);
            }, 3);
        }
    }

    private function assertDuplicateDecision(string $name, string $email, string $decision, ?string $reason): void
    {
        $existingOrg = Organization::query()->get(['name'])->contains(
            fn (Organization $organization): bool => $this->normalizeName($organization->name) === $name,
        );
        $existingCase = OwnerOnboarding::query()
            ->where('normalized_organization_name', $name)
            ->whereIn('status', [OwnerOnboarding::STATUS_ISSUED, OwnerOnboarding::STATUS_COMPLETED])
            ->exists();
        if (($existingOrg || $existingCase) && ($decision !== 'distinct_company' || trim((string) $reason) === '')) {
            throw ValidationException::withMessages([
                'duplicate_decision' => '同名候補があります。同じ案件の再送または既存OrganizationへのStaff招待を確認し、別会社なら理由を入力してください。',
            ]);
        }
        if ($decision === 'distinct_company' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['distinct_company_reason' => '別会社として開始する理由を入力してください。']);
        }
        if (OwnerOnboarding::query()->where('normalized_email', $email)->where('normalized_organization_name', $name)->where('status', OwnerOnboarding::STATUS_ISSUED)->exists()) {
            throw ValidationException::withMessages(['email' => '同じ会社・Owner Emailの未完了案件があります。既存案件を再送してください。']);
        }
    }

    private function normalizeName(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }

    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function existingOperation(OwnerOnboarding $onboarding, string $operation, string $requestId, string $payloadHash): ?OwnerOnboardingOperation
    {
        $record = OwnerOnboardingOperation::query()->where('owner_onboarding_id', $onboarding->id)
            ->where('operation', $operation)->where('request_id', $requestId)->lockForUpdate()->first();
        if ($record && ! hash_equals($record->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages(['request_id' => '同じRequest IDを異なる操作には使用できません。']);
        }

        return $record;
    }

    private function operation(OwnerOnboarding $onboarding, User $actor, string $operation, string $requestId, string $payloadHash): void
    {
        OwnerOnboardingOperation::query()->create([
            'owner_onboarding_id' => $onboarding->id,
            'actor_user_id' => $actor->id,
            'operation' => $operation,
            'request_id' => $requestId,
            'payload_hash' => $payloadHash,
        ]);
    }
}
