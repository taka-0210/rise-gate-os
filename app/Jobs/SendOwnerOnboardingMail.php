<?php

namespace App\Jobs;

use App\Mail\AccountActionMail;
use App\Models\OwnerOnboarding;
use App\Models\User;
use App\Services\Organization\OwnerOnboardingLegal;
use App\Services\Organization\OwnerOnboardingMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOwnerOnboardingMail implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $onboardingId,
        private readonly int $generation,
        private readonly string $token,
        private readonly string $mailer,
    ) {}

    public function handle(OwnerOnboardingMailer $mailer, OwnerOnboardingLegal $legal): void
    {
        $onboarding = OwnerOnboarding::query()->find($this->onboardingId);
        if (! $this->isCurrentAndAuthorized($onboarding)) {
            return;
        }
        try {
            $legal->assertReady();
        } catch (\RuntimeException) {
            return;
        }

        Mail::mailer($this->mailer)->to($onboarding->normalized_email)->send(new AccountActionMail(
            'Company OS Owner開始のご案内',
            $onboarding->organization_name.' のCompany OSを開始する承認案内です。心当たりがある場合のみ期限内に手続きを進めてください。',
            $mailer->trustedUrl($onboarding, $this->token),
            '会社の開始手続きへ',
        ));

        OwnerOnboarding::query()
            ->whereKey($onboarding->id)
            ->where('status', OwnerOnboarding::STATUS_ISSUED)
            ->where('token_generation', $this->generation)
            ->update([
                'delivery_status' => OwnerOnboarding::DELIVERY_SENT,
                'delivered_at' => now(),
                'delivery_failed_at' => null,
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        OwnerOnboarding::query()
            ->whereKey($this->onboardingId)
            ->where('status', OwnerOnboarding::STATUS_ISSUED)
            ->where('token_generation', $this->generation)
            ->update([
                'delivery_status' => OwnerOnboarding::DELIVERY_FAILED,
                'delivery_failed_at' => now(),
            ]);
    }

    private function isCurrentAndAuthorized(?OwnerOnboarding $onboarding): bool
    {
        if (! $onboarding || ! $onboarding->isIssued() || $onboarding->isExpired()
            || $onboarding->token_generation !== $this->generation
            || ! hash_equals($onboarding->token_hash, hash('sha256', $this->token))) {
            return false;
        }

        $issuer = User::query()->find($onboarding->issued_by_user_id);

        return (bool) ($issuer?->is_active && $issuer?->is_system_admin);
    }
}
