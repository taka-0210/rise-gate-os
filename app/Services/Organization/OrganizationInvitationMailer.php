<?php

namespace App\Services\Organization;

use App\Jobs\SendOrganizationInvitationMail;
use App\Models\OrganizationInvitation;
use RuntimeException;

class OrganizationInvitationMailer
{
    public function dispatch(OrganizationInvitation $invitation, string $token): void
    {
        $this->assertConfigured();
        SendOrganizationInvitationMail::dispatch(
            $invitation->id,
            $invitation->token_generation,
            $token,
            (string) config('account.mail.mailer'),
        );
    }

    public function assertConfigured(): void
    {
        $mailer = (string) config('account.mail.mailer');
        $transport = config('mail.mailers.'.$mailer.'.transport');
        if ($mailer === '' || $transport === null || $transport === 'log'
            || (app()->environment('production') && $transport === 'array')) {
            throw new RuntimeException('Invitation mailer must use a non-log configured transport.');
        }
    }

    public function trustedUrl(OrganizationInvitation $invitation, string $token): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $parts = parse_url($base);
        if (! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('APP_URL must be a trusted absolute HTTP(S) URL.');
        }

        return $base.route('invitations.claim', [
            'invitation' => $invitation->public_id,
            'token' => $token,
        ], false);
    }
}
