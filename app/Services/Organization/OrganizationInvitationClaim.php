<?php

namespace App\Services\Organization;

use App\Models\OrganizationInvitation;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrganizationInvitationClaim
{
    public const SESSION_KEY = 'organization_invitation_claim';

    public function remember(Request $request, OrganizationInvitation $invitation, string $token): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'public_id' => $invitation->public_id,
            'generation' => $invitation->token_generation,
            'token_hash' => hash('sha256', $token),
            'claimed_at' => now()->getTimestamp(),
        ]);
    }

    public function current(
        Request $request,
        bool $lockForUpdate = false,
        bool $allowAccepted = false,
    ): OrganizationInvitation {
        $claim = $request->session()->get(self::SESSION_KEY);
        if (! is_array($claim)
            || ! isset($claim['public_id'], $claim['generation'], $claim['token_hash'], $claim['claimed_at'])
            || (int) $claim['claimed_at'] < now()->subMinutes((int) config('invitation.claim_minutes'))->getTimestamp()) {
            $this->forget($request);
            throw ValidationException::withMessages(['invitation' => '招待の確認情報が失効しました。招待メールからもう一度開いてください。']);
        }

        $query = OrganizationInvitation::query()->where('public_id', $claim['public_id']);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $invitation = $query->first();
        $acceptedRetry = $allowAccepted
            && $invitation?->status === OrganizationInvitation::STATUS_ACCEPTED;
        if (! $invitation
            || (! $invitation->isPending() && ! $acceptedRetry)
            || (! $acceptedRetry && $invitation->isExpired())
            || $invitation->token_generation !== (int) $claim['generation']
            || ! hash_equals($invitation->token_hash, (string) $claim['token_hash'])) {
            $this->forget($request);
            throw ValidationException::withMessages(['invitation' => 'この招待は使用できません。再送された最新の招待メールを確認してください。']);
        }

        return $invitation;
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}
