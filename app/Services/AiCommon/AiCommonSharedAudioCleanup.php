<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonSharedAudioWindow;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AiCommonSharedAudioCleanup
{
    public function cleanup(AiCommonSharedAudioWindow $window): AiCommonSharedAudioWindow
    {
        try {
            Storage::disk('ai_common_temporary_audio')->delete($window->storage_key);
            $window->update([
                'cleanup_status' => AiCommonSharedAudioWindow::CLEANUP_COMPLETE,
                'cleaned_at_utc' => now(),
            ]);
        } catch (Throwable) {
            $window->update(['cleanup_status' => AiCommonSharedAudioWindow::CLEANUP_PENDING]);
        }

        return $window->fresh();
    }

    public function cleanupExpired(): int
    {
        $count = 0;
        AiCommonSharedAudioWindow::query()
            ->where('expires_at_utc', '<=', now())
            ->where('cleanup_status', '!=', AiCommonSharedAudioWindow::CLEANUP_COMPLETE)
            ->orderBy('id')
            ->chunkById(100, function ($windows) use (&$count): void {
                foreach ($windows as $window) {
                    if (! in_array($window->state, [
                        AiCommonSharedAudioWindow::STATE_TRANSCRIBED,
                        AiCommonSharedAudioWindow::STATE_CANCELLED,
                        AiCommonSharedAudioWindow::STATE_DISCARDED,
                    ], true)) {
                        $window->update([
                            'state' => AiCommonSharedAudioWindow::STATE_EXPIRED,
                            'version' => $window->version + 1,
                        ]);
                    }
                    $this->cleanup($window->fresh());
                    $count++;
                }
            });

        return $count;
    }
}
