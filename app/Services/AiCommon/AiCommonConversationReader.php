<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonMessage;
use App\Models\Organization;
use App\Models\User;
use Throwable;

class AiCommonConversationReader
{
    public const MAX_HISTORY_MESSAGES = 10;
    public const MAX_HISTORY_CHARS = 12000;

    public function __construct(
        private readonly AiCommonAccess $access,
        private readonly AiCommonSourceManifest $manifest,
    ) {}

    public function visible(User $actor, Organization $organization, AiCommonConversation $conversation): array
    {
        $this->access->authorizeConversation($actor, $organization, $conversation);
        $result = [];
        foreach ($conversation->messages()->with('sources.conversation')->get() as $message) {
            if ($message->role === AiCommonMessage::ROLE_ASSISTANT && ! $this->sourcesAreCurrent($actor, $organization, $message)) {
                $result[] = ['message' => $message, 'visible' => false, 'sources' => []];
                continue;
            }
            $sources = [];
            foreach ($message->sources as $source) {
                $sources[] = $this->manifest->authorize($actor, $organization, $source);
            }
            $result[] = ['message' => $message, 'visible' => true, 'sources' => $sources];
        }

        return $result;
    }

    public function providerHistory(User $actor, Organization $organization, AiCommonConversation $conversation): array
    {
        $messages = collect($this->visible($actor, $organization, $conversation))
            ->where('visible', true)->pluck('message')->take(-self::MAX_HISTORY_MESSAGES);
        $result = [];
        $chars = 0;
        foreach ($messages as $message) {
            $chars += mb_strlen($message->content);
            if ($chars > self::MAX_HISTORY_CHARS) {
                break;
            }
            $result[] = ['role' => $message->role, 'content' => $message->content];
        }

        return $result;
    }

    private function sourcesAreCurrent(User $actor, Organization $organization, AiCommonMessage $message): bool
    {
        try {
            foreach ($message->sources as $source) {
                $this->manifest->authorize($actor, $organization, $source);
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
