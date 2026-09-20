<?php

namespace App\Services\Organization;

use App\Models\OwnerOnboarding;
use App\Models\User;
use App\Models\UserLegalConsent;
use RuntimeException;

class OwnerOnboardingLegal
{
    public function assertReady(): void
    {
        $values = $this->current();
        if (! config('owner_onboarding.legal_documents_published')
            || ! $this->trustedUrl($values['terms_url'])
            || ! $this->trustedUrl($values['privacy_url'])
            || $values['terms_version'] === '' || $values['privacy_version'] === ''
            || preg_match('/\A[a-f0-9]{64}\z/i', $values['terms_hash']) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/i', $values['privacy_hash']) !== 1) {
            throw new RuntimeException('Owner onboarding legal documents are not published.');
        }
    }

    public function current(): array
    {
        return [
            'terms_version' => (string) config('owner_onboarding.terms.version'),
            'terms_hash' => strtolower((string) config('owner_onboarding.terms.content_hash')),
            'terms_url' => (string) config('owner_onboarding.terms.url'),
            'privacy_version' => (string) config('owner_onboarding.privacy.version'),
            'privacy_hash' => strtolower((string) config('owner_onboarding.privacy.content_hash')),
            'privacy_url' => (string) config('owner_onboarding.privacy.url'),
        ];
    }

    public function record(User $user, OwnerOnboarding $onboarding): UserLegalConsent
    {
        $this->assertReady();
        $current = $this->current();

        return UserLegalConsent::query()->firstOrCreate([
            'user_id' => $user->id,
            'owner_onboarding_id' => $onboarding->id,
            'purpose' => UserLegalConsent::PURPOSE_OWNER_ONBOARDING,
            'document_signature' => $this->signature($current),
        ], [
            'terms_version' => $current['terms_version'],
            'terms_content_hash' => $current['terms_hash'],
            'privacy_version' => $current['privacy_version'],
            'privacy_content_hash' => $current['privacy_hash'],
            'recorded_timezone' => 'Asia/Tokyo',
            'consented_at' => now(),
        ]);
    }

    public function hasCurrentConsent(User $user, OwnerOnboarding $onboarding): bool
    {
        $this->assertReady();
        $current = $this->current();

        return UserLegalConsent::query()
            ->where('user_id', $user->id)
            ->where('owner_onboarding_id', $onboarding->id)
            ->where('purpose', UserLegalConsent::PURPOSE_OWNER_ONBOARDING)
            ->where('document_signature', $this->signature($current))
            ->where('terms_version', $current['terms_version'])
            ->where('terms_content_hash', $current['terms_hash'])
            ->where('privacy_version', $current['privacy_version'])
            ->where('privacy_content_hash', $current['privacy_hash'])
            ->exists();
    }

    public function signature(?array $current = null): string
    {
        return hash('sha256', json_encode($current ?? $this->current(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function trustedUrl(string $url): bool
    {
        $parts = parse_url($url);

        return in_array($parts['scheme'] ?? null, ['http', 'https'], true) && ! empty($parts['host']);
    }
}
