<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonMessage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        $legacyLineageUnknown = false;
        $messages = $conversation->messages()
            ->with(['sources.conversation', 'sourceRevisions.conversation'])
            ->get();
        foreach ($messages as $message) {
            if ($message->role !== AiCommonMessage::ROLE_ASSISTANT) {
                $result[] = ['message' => $message, 'visible' => true, 'sources' => []];

                continue;
            }
            if ($message->source_lineage_version !== AiCommonMessage::SOURCE_LINEAGE_V1) {
                if ($message->sources->isNotEmpty()) {
                    $legacyLineageUnknown = true;
                }
                if ($legacyLineageUnknown) {
                    $result[] = ['message' => $message, 'visible' => false, 'sources' => []];

                    continue;
                }
            }
            if (! $this->revisionsAreCurrent($actor, $organization, $message)) {
                $result[] = ['message' => $message, 'visible' => false, 'sources' => []];

                continue;
            }
            $sources = [];
            foreach ($message->sourceRevisions as $revision) {
                $sources[] = $this->manifest->authorizeRevision($actor, $organization, $revision);
            }
            $result[] = ['message' => $message, 'visible' => true, 'sources' => $sources];
        }

        return $result;
    }

    public function providerHistory(User $actor, Organization $organization, AiCommonConversation $conversation): array
    {
        return $this->providerContext($actor, $organization, $conversation)['messages'];
    }

    public function providerContext(User $actor, Organization $organization, AiCommonConversation $conversation): array
    {
        $rows = collect($this->visible($actor, $organization, $conversation))
            ->where('visible', true)->take(-self::MAX_HISTORY_MESSAGES);
        $messages = [];
        $revisions = new EloquentCollection;
        $chars = 0;
        foreach ($rows as $row) {
            $message = $row['message'];
            $chars += mb_strlen($message->content);
            if ($chars > self::MAX_HISTORY_CHARS) {
                break;
            }
            $messages[] = ['role' => $message->role, 'content' => $message->content];
            if ($message->role === AiCommonMessage::ROLE_ASSISTANT
                && $message->source_lineage_version === AiCommonMessage::SOURCE_LINEAGE_V1) {
                foreach ($message->sourceRevisions as $revision) {
                    $revisions->push($revision);
                }
            }
        }

        return ['messages' => $messages, 'revisions' => $revisions->unique('id')->values()];
    }

    private function revisionsAreCurrent(User $actor, Organization $organization, AiCommonMessage $message): bool
    {
        try {
            foreach ($message->sourceRevisions as $revision) {
                $this->manifest->authorizeRevision($actor, $organization, $revision);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
