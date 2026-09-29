<?php

return [
    'enabled' => (bool) env('COMPANY_OS_REALTIME_ENABLED', false),
    'audio_send_enabled' => (bool) env('COMPANY_OS_REALTIME_AUDIO_SEND_ENABLED', false),
    'relay_url' => env('COMPANY_OS_REALTIME_RELAY_URL'),
    'bridge_token' => env('COMPANY_OS_REALTIME_BRIDGE_TOKEN'),
    'human_verification' => [
        'max_provider_sessions' => (int) env('COMPANY_OS_REALTIME_MAX_PROVIDER_SESSIONS', 3),
        'max_audio_seconds' => (int) env('COMPANY_OS_REALTIME_MAX_AUDIO_SECONDS', 300),
        'cost_limit_usd' => (float) env('COMPANY_OS_REALTIME_COST_LIMIT_USD', 0.05),
    ],
    'lease_ttl_seconds' => (int) env('COMPANY_OS_REALTIME_LEASE_TTL_SECONDS', 12),
    'lease_refresh_seconds' => (int) env('COMPANY_OS_REALTIME_LEASE_REFRESH_SECONDS', 4),
    'reorder_window_frames' => (int) env('COMPANY_OS_REALTIME_REORDER_WINDOW_FRAMES', 5),
    'finalization_grace_ms' => (int) env('COMPANY_OS_REALTIME_FINALIZATION_GRACE_MS', 1500),
    'canonical_audio' => [
        'format' => 'pcm_s16le',
        'sample_rate' => 16000,
        'bit_depth' => 16,
        'channels' => 1,
        'frame_ms' => 100,
    ],
    'deepgram' => [
        'adapter_version' => 'company-os-deepgram-v1',
        'sdk_candidate' => '@deepgram/sdk@5.10.0',
        'model' => 'nova-3',
        'language' => 'ja',
        'diarize' => true,
        'interim_results' => true,
        'mip_opt_out' => true,
        'automatic_retry' => false,
    ],
];
