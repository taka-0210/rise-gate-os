<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\AiAuditLog;
use App\Models\AiChatMessage;
use App\Models\AiChatThread;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Services\OpenAiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use RuntimeException;

class AiChatController extends Controller
{
    public function store(Request $request, Project $project, OpenAiChatService $chat): JsonResponse
    {
        Gate::authorize('view', $project);
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace && $project->owning_workspace_id === $workspace->id, 404);

        if (! $workspace->aiSetting?->enabled) {
            return response()->json(['message' => 'このWorkspaceではAI機能が有効になっていません。'], 403);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
            'context_key' => ['nullable', 'string', 'max:255'],
            'context_label' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'file_content' => ['nullable', 'string', 'max:1000000'],
        ]);

        $thread = AiChatThread::firstOrCreate([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
        ], [
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
        ]);

        $userMessage = $thread->messages()->create([
            'role' => AiChatMessage::ROLE_USER,
            'content' => $validated['content'],
            'context_key' => $validated['context_key'] ?? null,
            'context_label' => $validated['context_label'] ?? null,
        ]);
        if ($image = $request->file('image')) {
            $path = $image->store("ai-chat/{$thread->id}", 'local');
            $userMessage->update([
                'image_path' => $path,
                'image_name' => $image->getClientOriginalName(),
                'image_mime' => $image->getMimeType(),
                'image_size' => $image->getSize(),
            ]);
        }

        $startedAt = microtime(true);
        try {
            $result = $chat->respond(
                $thread->messages()->reorder()->latest('id')->limit(20)->get()->reverse()->values(),
                $this->projectContext($request, $project, $validated),
                $request->user()->id,
            );
            $assistantMessage = $thread->messages()->create([
                'role' => AiChatMessage::ROLE_ASSISTANT,
                ...$result,
            ]);
            $thread->touch();

            AiAuditLog::create([
                'workspace_id' => $workspace->id,
                'user_id' => $request->user()->id,
                'project_id' => $project->id,
                'event' => 'ai_chat.responded',
                'succeeded' => true,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request_fingerprint' => hash('sha256', $userMessage->content),
                'metadata' => [
                    'model' => $assistantMessage->model,
                    'input_tokens' => $assistantMessage->input_tokens,
                    'output_tokens' => $assistantMessage->output_tokens,
                    'estimated_cost_microusd' => $assistantMessage->estimated_cost_microusd,
                ],
                'occurred_at' => now(),
            ]);

            return response()->json(['message' => $this->messageData($assistantMessage)]);
        } catch (RuntimeException $exception) {
            AiAuditLog::create([
                'workspace_id' => $workspace->id,
                'user_id' => $request->user()->id,
                'project_id' => $project->id,
                'event' => 'ai_chat.failed',
                'succeeded' => false,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request_fingerprint' => hash('sha256', $userMessage->content),
                'error_message' => $exception->getMessage(),
                'occurred_at' => now(),
            ]);

            return response()->json(['message' => $exception->getMessage()], 502);
        }
    }

    public function image(Request $request, Project $project, AiChatMessage $message): StreamedResponse
    {
        Gate::authorize('view', $project);
        abort_unless($message->thread?->project_id === $project->id && $message->image_path, 404);
        abort_unless(Storage::disk('local')->exists($message->image_path), 404);

        return Storage::disk('local')->response($message->image_path, $message->image_name, [
            'Content-Type' => $message->image_mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function markFileChangeApplied(Request $request, Project $project, AiChatMessage $message): JsonResponse
    {
        $this->authorizeFileChange($request, $project, $message);
        abort_unless($message->file_change_status === 'pending', 409, 'この変更提案は処理済みです。');
        $message->update(['file_change_status' => 'applied', 'file_change_applied_at' => now()]);

        return response()->json(['status' => 'applied']);
    }

    public function markFileChangeRejected(Request $request, Project $project, AiChatMessage $message): JsonResponse
    {
        $this->authorizeFileChange($request, $project, $message);
        abort_unless($message->file_change_status === 'pending', 409, 'この変更提案は処理済みです。');
        $message->update(['file_change_status' => 'rejected', 'file_change_applied_at' => null]);

        return response()->json(['status' => 'rejected']);
    }

    private function authorizeFileChange(Request $request, Project $project, AiChatMessage $message): void
    {
        Gate::authorize('view', $project);
        abort_unless(
            $message->thread?->project_id === $project->id
            && $message->thread->user_id === $request->user()->id
            && $message->role === AiChatMessage::ROLE_ASSISTANT
            && $message->file_change_path,
            404
        );
    }

    private function projectContext(Request $request, Project $project, array $validated): array
    {
        $memberRole = $project->members()->where('user_id', $request->user()->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)->value('project_role');
        $project->load(['client', 'roadmaps.improvements.tasks']);

        $filePath = $validated['file_path'] ?? null;
        $fileContent = $validated['file_content'] ?? null;
        $protected = $filePath && (
            preg_match('~(^|/)\.env($|[./])~i', $filePath)
            || preg_match('~^(vendor|storage|\.git)(/|$)~i', $filePath)
        );

        return [
            'currently_open' => $validated['context_label'] ?? null,
            'open_file' => $filePath && $fileContent !== null && ! $protected ? [
                'path' => $filePath,
                'content' => $fileContent,
                'sha256' => hash('sha256', $fileContent),
                'change_contract' => 'Return a complete replacement for this one file only. Never target another path.',
                'response_format' => [
                    'instruction' => 'Return only valid JSON. Do not use Markdown fences.',
                    'schema' => [
                        'answer' => 'Short Japanese explanation',
                        'file_change' => ['path' => 'Exact open_file.path', 'content' => 'Complete updated file content'],
                    ],
                    'when_no_change' => ['answer' => 'Normal Japanese answer', 'file_change' => null],
                ],
            ] : null,
            'project' => [
                'name' => $project->name,
                'client' => $project->client?->name,
                'summary' => $project->summary,
                'current_state' => $project->current_state,
                'desired_future_state' => $project->desired_future_state,
                'status' => $project->status,
                'priority' => $project->priority,
                'period' => [$project->start_date?->toDateString(), $project->due_date?->toDateString()],
            ],
            'roadmaps' => $project->roadmaps->map(fn ($roadmap): array => [
                'title' => $roadmap->title,
                'purpose' => $roadmap->purpose,
                'status' => $roadmap->status,
                'improvements' => $roadmap->improvements
                    ->when($memberRole === ProjectMember::ROLE_CLIENT, fn ($items) => $items->where('visibility', Improvement::VISIBILITY_CLIENT))
                    ->map(fn ($improvement): array => [
                        'title' => $improvement->title,
                        'status' => $improvement->status,
                        'current_state' => $improvement->current_state,
                        'desired_state' => $improvement->desired_state,
                        'tasks' => $improvement->tasks->map(fn ($task): array => [
                            'title' => $task->title,
                            'status' => $task->status,
                            'due_date' => $task->due_date?->toDateString(),
                        ])->values()->all(),
                    ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function messageData(AiChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'model' => $message->model,
            'input_tokens' => $message->input_tokens,
            'output_tokens' => $message->output_tokens,
            'estimated_cost_usd' => $message->estimated_cost_microusd / 1_000_000,
            'created_at' => $message->created_at->toIso8601String(),
            'image_url' => $message->image_path ? route('projects.ai-chat.messages.image', [$message->thread->project_id, $message]) : null,
            'file_change' => $message->file_change_path ? [
                'message_id' => $message->id,
                'path' => $message->file_change_path,
                'content' => $message->file_change_content,
                'original_hash' => $message->file_change_original_hash,
                'status' => $message->file_change_status,
                'apply_url' => route('projects.ai-chat.messages.file-change.applied', [$message->thread->project_id, $message]),
                'reject_url' => route('projects.ai-chat.messages.file-change.rejected', [$message->thread->project_id, $message]),
            ] : null,
        ];
    }
}
