<?php

namespace App\Console\Commands;

use App\Services\AiCommon\AiCommonSharedAudioCleanup;
use App\Services\AiCommon\AiCommonTemporaryAudioWriter;
use Illuminate\Console\Command;

class CleanupAiCommonTemporaryAudio extends Command
{
    protected $signature = 'ai-common:cleanup-temporary-audio';

    protected $description = 'Expire and physically clean up temporary AI Common voice input';

    public function handle(AiCommonTemporaryAudioWriter $writer, AiCommonSharedAudioCleanup $shared): int
    {
        $private = $writer->cleanupExpired();
        $sharedCount = $shared->cleanupExpired();
        $this->info('processed='.($private + $sharedCount).' private='.$private.' shared_session='.$sharedCount);

        return self::SUCCESS;
    }
}
