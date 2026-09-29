<?php

namespace App\Services\AiCommon\Realtime;

enum SafeRealtimeReason: string
{
    case AuthorizationChanged = 'authorization_changed';
    case ConsentChanged = 'consent_changed';
    case LeaseExpired = 'lease_expired';
    case GenerationSuperseded = 'generation_superseded';
    case IntegrityConflict = 'integrity_conflict';
    case SourceGap = 'source_gap';
    case SourceOverlap = 'source_overlap';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderEventLate = 'provider_event_late';
    case ProviderMappingUnverified = 'provider_mapping_unverified';
    case MipGuardFailed = 'mip_guard_failed';
    case AudioSendDisabled = 'audio_send_disabled';
    case WriterRejected = 'writer_rejected';
    case GraceTimedOut = 'grace_timed_out';
    case NormalStop = 'normal_stop';
    case HardAbort = 'hard_abort';
}
