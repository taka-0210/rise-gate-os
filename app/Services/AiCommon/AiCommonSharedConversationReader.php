<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonMessage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Throwable;

class AiCommonSharedConversationReader
{
    public function __construct(private readonly AiCommonSharedAccess $access, private readonly AiCommonSharedContext $context) {}

    public function visible(User $actor, Organization $organization, AiCommonConversation $conversation): array
    {
        $this->access->authorizeParticipant($actor, $organization, $conversation);
        $rows = [];
        foreach ($conversation->messages()->with(['sourceRevisions.conversation', 'sharedAuthor.author'])->get() as $message) {
            if ($message->role !== AiCommonMessage::ROLE_ASSISTANT) {
                $rows[] = ['message' => $message, 'visible' => true, 'sources' => []];

                continue;
            }
            if ($message->source_lineage_version !== AiCommonMessage::SOURCE_LINEAGE_V1) {
                $rows[] = ['message' => $message, 'visible' => false, 'sources' => []];

                continue;
            }
            try {
                $sources = $this->context->authorizedRevisions($actor, $organization, $message->sourceRevisions);
                $rows[] = ['message' => $message, 'visible' => true, 'sources' => $sources];
            } catch (Throwable) {
                $rows[] = ['message' => $message, 'visible' => false, 'sources' => []];
            }
        }

        return $rows;
    }

    public function providerContext(User $actor, Organization $organization, AiCommonConversation $conversation): array
    {
        $visible = collect($this->visible($actor, $organization, $conversation))->where('visible', true)->take(-10);
        $messages = [];
        $revisions = new EloquentCollection;
        $chars = 0;
        foreach ($visible as $row) {
            $chars += mb_strlen($row['message']->content);
            if ($chars > 12000) {
                break;
            }
            $messages[] = ['role' => $row['message']->role, 'content' => $row['message']->content];
            if ($row['message']->role === AiCommonMessage::ROLE_ASSISTANT) {
                foreach ($row['message']->sourceRevisions as $revision) {
                    $revisions->push($revision);
                }
            }
        }

        return ['messages' => $messages, 'revisions' => $revisions->unique('id')->values()];
    }
}
