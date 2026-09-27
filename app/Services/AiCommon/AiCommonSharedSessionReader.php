<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedIdentityRevision;
use App\Models\AiCommonSharedSession;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\Organization;
use App\Models\User;

class AiCommonSharedSessionReader
{
    public function __construct(private readonly AiCommonSharedSessionAccess $access) {}

    public function transcript(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedSession $session,
    ): array {
        $this->access->authorize($actor, $organization, $conversation, $session);
        $this->access->assertConsents($actor, $organization, $conversation, $session, [
            AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING,
        ]);

        return $session->transcriptSegments()
            ->with(['currentRevision', 'identityRevisions'])
            ->orderBy('ai_common_shared_audio_window_id')
            ->orderBy('segment_index')
            ->get()
            ->map(function ($segment): array {
                $identity = $segment->identityRevisions
                    ->sortByDesc('revision_no')
                    ->first();

                return [
                    'segment' => $segment,
                    'revision' => $segment->currentRevision,
                    'confirmed_user_id' => $identity?->status === AiCommonSharedIdentityRevision::STATUS_CONFIRMED
                        ? $identity->confirmed_user_id
                        : null,
                ];
            })->all();
    }
}
