<?php

namespace App\Services\AiCommon\Realtime;

use App\Models\AiCommonSharedTranscriptSegment;

final class RealtimeTranscriptSourceGuard
{
    private static ?array $context = null;

    public function within(int $sessionId, int $streamId, int $commitId, callable $callback): mixed
    {
        if (self::$context !== null) {
            throw new \LogicException('Nested realtime Transcript publication is not allowed.');
        }
        self::$context = ['session_id' => $sessionId, 'stream_id' => $streamId, 'commit_id' => $commitId];
        try {
            return $callback();
        } finally {
            self::$context = null;
        }
    }

    public function allows(AiCommonSharedTranscriptSegment $segment): bool
    {
        return self::$context !== null
            && $segment->source_kind === AiCommonSharedTranscriptSegment::SOURCE_REALTIME
            && $segment->ai_common_shared_audio_window_id === null
            && (int) $segment->ai_common_shared_session_id === self::$context['session_id']
            && (int) $segment->ai_common_shared_capture_stream_id === self::$context['stream_id']
            && (int) $segment->realtime_durable_final_commit_id === self::$context['commit_id'];
    }
}
