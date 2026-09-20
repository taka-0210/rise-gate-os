<?php

namespace App\Services\Organization;

use App\Models\OwnerOnboarding;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OwnerOnboardingClaim
{
    public const SESSION_KEY = 'owner_onboarding_claim';

    public function remember(Request $request, OwnerOnboarding $onboarding, string $token): void
    {
        $request->session()->forget(OrganizationInvitationClaim::SESSION_KEY);
        $request->session()->put(self::SESSION_KEY, [
            'public_id' => $onboarding->public_id,
            'generation' => $onboarding->token_generation,
            'token_hash' => hash('sha256', $token),
            'claimed_at' => now()->getTimestamp(),
        ]);
    }

    public function current(Request $request, bool $lockForUpdate = false, bool $allowCompleted = false): OwnerOnboarding
    {
        $claim = $request->session()->get(self::SESSION_KEY);
        if (! is_array($claim)
            || ! isset($claim['public_id'], $claim['generation'], $claim['token_hash'], $claim['claimed_at'])
            || (int) $claim['claimed_at'] < now()->subMinutes((int) config('owner_onboarding.claim_minutes'))->getTimestamp()) {
            $this->forget($request);
            throw ValidationException::withMessages(['onboarding' => '開始情報が失効しました。最新の案内Mailからもう一度開いてください。']);
        }

        $query = OwnerOnboarding::query()->where('public_id', $claim['public_id']);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $onboarding = $query->first();
        $completedRetry = $allowCompleted && $onboarding?->status === OwnerOnboarding::STATUS_COMPLETED;
        if (! $onboarding
            || (! $onboarding->isIssued() && ! $completedRetry)
            || (! $completedRetry && $onboarding->isExpired())
            || $onboarding->token_generation !== (int) $claim['generation']
            || ! hash_equals($onboarding->token_hash, (string) $claim['token_hash'])) {
            $this->forget($request);
            throw ValidationException::withMessages(['onboarding' => 'この開始案内は使用できません。最新の案内Mailを確認してください。']);
        }

        return $onboarding;
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}
