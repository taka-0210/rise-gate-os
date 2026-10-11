<?php

namespace App\Http\Controllers;

use App\Models\AiCommonConversation;
use App\Models\AiCommonMessage;
use App\Services\AiCommon\AiCommonConversationReader;
use App\Services\AiCommon\AiCommonSharedConversationReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoVoiceReplyAccessController extends Controller
{
    public function __invoke(Request $request, AiCommonConversation $conversation, string $message): JsonResponse
    {
        $reader = $conversation->conversation_kind === AiCommonConversation::KIND_SHARED
            ? app(AiCommonSharedConversationReader::class) : app(AiCommonConversationReader::class);
        $rows = $reader->visible($request->user(), $request->attributes->get('currentCompany'), $conversation);
        $row = collect($rows)->first(fn ($row) => $row['message']->public_id === $message);
        abort_unless($row && $row['visible'] && $row['message']->role === AiCommonMessage::ROLE_ASSISTANT
            && $row['message']->visibility_status === AiCommonMessage::VISIBILITY_VISIBLE, 403);

        // Reuse the Reader's live membership/audience/source/consent checks.
        // Do not return any message or source body to the playback client.
        return response()->json(['allowed' => true, 'content_hash' => hash('sha256', $row['message']->content)])
            ->header('Cache-Control', 'no-store, private');
    }
}
