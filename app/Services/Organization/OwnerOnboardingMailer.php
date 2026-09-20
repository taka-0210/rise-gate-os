<?php

namespace App\Services\Organization;

use App\Jobs\SendOwnerOnboardingMail;
use App\Models\OwnerOnboarding;
use RuntimeException;

class OwnerOnboardingMailer
{
    public function __construct(private readonly OwnerOnboardingLegal $legal) {}

    public function dispatch(OwnerOnboarding $onboarding, string $token): void
    {
        $this->assertConfigured();
        SendOwnerOnboardingMail::dispatch(
            $onboarding->id,
            $onboarding->token_generation,
            $token,
            (string) config('account.mail.mailer'),
        );
    }

    public function assertConfigured(): void
    {
        $this->legal->assertReady();
        $mailer = (string) config('account.mail.mailer');
        $transport = config('mail.mailers.'.$mailer.'.transport');
        if ($mailer === '' || $transport === null || $transport === 'log'
            || (app()->environment('production') && $transport === 'array')) {
            throw new RuntimeException('Owner onboarding mailer must use a non-log configured transport.');
        }
    }

    public function trustedUrl(OwnerOnboarding $onboarding, string $token): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $parts = parse_url($base);
        if (! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('APP_URL must be a trusted absolute HTTP(S) URL.');
        }

        return $base.route('owner-onboarding.claim', [
            'onboarding' => $onboarding->public_id,
            'token' => $token,
        ], false);
    }
}
