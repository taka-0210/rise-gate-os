<?php

return [
    'expires_days' => (int) env('OWNER_ONBOARDING_EXPIRES_DAYS', 7),
    'claim_minutes' => (int) env('OWNER_ONBOARDING_CLAIM_MINUTES', 30),
    'resend_cooldown_seconds' => (int) env('OWNER_ONBOARDING_RESEND_COOLDOWN_SECONDS', 60),
    'max_requests_per_minute' => (int) env('OWNER_ONBOARDING_MAX_REQUESTS_PER_MINUTE', 10),
    'legal_documents_published' => (bool) env('OWNER_ONBOARDING_LEGAL_DOCUMENTS_PUBLISHED', false),
    'terms' => [
        'version' => (string) env('OWNER_ONBOARDING_TERMS_VERSION', ''),
        'content_hash' => (string) env('OWNER_ONBOARDING_TERMS_CONTENT_HASH', ''),
        'url' => (string) env('OWNER_ONBOARDING_TERMS_URL', ''),
    ],
    'privacy' => [
        'version' => (string) env('OWNER_ONBOARDING_PRIVACY_VERSION', ''),
        'content_hash' => (string) env('OWNER_ONBOARDING_PRIVACY_CONTENT_HASH', ''),
        'url' => (string) env('OWNER_ONBOARDING_PRIVACY_URL', ''),
    ],
];
