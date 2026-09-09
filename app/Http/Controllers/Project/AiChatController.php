<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\AiAuditLog;
use App\Models\AiChatMessage;
use App\Models\AiChatThread;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Services\AiChatUsage;
use App\Services\ImageSavePath;
use App\Services\LocalDevelopmentContract;
use App\Services\OpenAiChatService;
use App\Services\ProjectAppContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        \App\Services\AiChatPayload::decode($request);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
            'generate_image' => ['sometimes', 'boolean'],
            'local_file_access' => ['sometimes', 'boolean'],
            'auto_save_files' => ['sometimes', 'boolean'],
            'development_mode' => ['sometimes', 'boolean'],
            'context_key' => ['nullable', 'string', 'max:255'],
            'context_label' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'file_content' => ['nullable', 'string', 'max:1000000'],
            'project_files' => ['nullable', 'json', 'max:1000000'],
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

        if ($this->requestsBackupRestore($validated['content'])) {
            $assistantMessage = $thread->messages()->create([
                'role' => AiChatMessage::ROLE_ASSISTANT,
                'content' => '変更履歴を開きました。戻したい日時の「差分を見る」で内容を確認し、「元に戻す」を押してください。復元する直前の状態も自動でバックアップされます。',
            ]);
            $thread->touch();

            AiAuditLog::create([
                'workspace_id' => $workspace->id,
                'user_id' => $request->user()->id,
                'project_id' => $project->id,
                'event' => 'ai_chat.change_history_opened',
                'succeeded' => true,
                'duration_ms' => 0,
                'request_fingerprint' => hash('sha256', $userMessage->content),
                'occurred_at' => now(),
            ]);

            return response()->json([
                'message' => $this->messageData($assistantMessage),
                'ui_action' => 'open_change_history',
            ]);
        }

        $context = $this->projectContext($request, $project, $validated);
        $context['generated_images'] = $thread->messages()->reorder()->where('role', AiChatMessage::ROLE_ASSISTANT)
            ->whereNotNull('image_path')->latest('id')->limit(30)->get()
            ->map(fn (AiChatMessage $message): array => ['message_id' => $message->id, 'description' => $message->content, 'name' => $message->image_name])->all();
        $startedAt = microtime(true);
        try {
            $result = $chat->respond(
                $thread->messages()->reorder()->latest('id')->limit(20)->get()->reverse()->values(),
                $context,
                $request->user()->id,
                $request->boolean('generate_image'),
            );
            $batch = $result['file_change_batch'] ?? [];
            unset($result['file_change_batch']);
            [$assistantMessage, $relatedMessages] = DB::transaction(function () use ($thread, $result, $batch) {
                $primary = $thread->messages()->create(['role' => AiChatMessage::ROLE_ASSISTANT, ...$result]);
                $related = [];
                foreach ($batch as $change) {
                    $related[] = $thread->messages()->create([
                        'role' => AiChatMessage::ROLE_ASSISTANT,
                        'content' => '関連ファイル：'.$change['path'],
                        'file_change_path' => $change['path'],
                        'file_change_content' => $change['content'],
                        'file_change_original_hash' => $change['original_hash'],
                        'file_change_status' => 'pending',
                    ]);
                }

                return [$primary, $related];
            });
            if ($assistantMessage->image_save && $assistantMessage->image_save['source_message_id'] === 0) {
                $assistantMessage->update(['image_save' => [...$assistantMessage->image_save, 'source_message_id' => $assistantMessage->id]]);
            }
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
                    'image_input_tokens' => $assistantMessage->image_input_tokens,
                    'image_output_tokens' => $assistantMessage->image_output_tokens,
                ],
                'occurred_at' => now(),
            ]);

            return response()->json(['message' => $this->messageData($assistantMessage), 'related_messages' => array_map(fn ($message) => $this->messageData($message), $relatedMessages), 'usage' => AiChatUsage::summary($thread)]);
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
        abort_unless($message->thread->user_id === $request->user()->id, 404);
        abort_unless(Storage::disk('local')->exists($message->image_path), 404);

        return Storage::disk('local')->response($message->image_path, $message->image_name, [
            'Content-Type' => $message->image_mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function markImageSaved(Request $request, Project $project, AiChatMessage $message): JsonResponse
    {
        Gate::authorize('view', $project);
        abort_unless($message->thread?->project_id === $project->id
            && $message->thread->user_id === $request->user()->id
            && ($message->image_save || ($message->role === AiChatMessage::ROLE_ASSISTANT && $message->image_path && $message->image_mime === 'image/png')), 404);
        $validated = $request->validate(['path' => ['required', 'string', 'max:240']]);
        try {
            $path = ImageSavePath::normalize($validated['path']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        $savedAt = now()->timezone('Asia/Tokyo')->toIso8601String();
        $operation = $message->image_save ?? ['source_message_id' => $message->id];
        $message->update(['image_save' => [...$operation, 'path' => $path, 'status' => 'saved', 'saved_at' => $savedAt]]);

        return response()->json(['status' => 'saved', 'saved_at' => $savedAt]);
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
        if ($message->file_change_status === 'rejected') {
            return response()->json(['status' => 'rejected', 'already_rejected' => true]);
        }
        abort_unless($message->file_change_status === 'pending', 409, 'この変更提案は処理済みです。');
        $message->update(['file_change_status' => 'rejected', 'file_change_applied_at' => null]);

        return response()->json(['status' => 'rejected', 'already_rejected' => false]);
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

    private function requestsBackupRestore(string $content): bool
    {
        return str_contains($content, 'バックアップ')
            && preg_match('/(?:元に戻|戻そ|戻して|復元|もど)/u', $content) === 1;
    }

    private function projectContext(Request $request, Project $project, array $validated): array
    {
        $memberRole = $project->members()->where('user_id', $request->user()->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)->value('project_role');
        $project->load(['client', 'roadmaps.improvements.tasks']);

        $filePath = $validated['file_path'] ?? null;
        $fileContent = $validated['file_content'] ?? null;
        if ($fileContent !== null) {
            $fileContent = str_replace(["\r\n", "\r"], "\n", $fileContent);
        }
        $protected = $filePath && (
            preg_match('~(^|/)\.env($|[./])~i', $filePath)
            || preg_match('~^(vendor|deploy|deployment|\.git|\.rise-gate)(/|$)~i', $filePath)
            || (preg_match('~^storage(/|$)~i', $filePath) && ! preg_match('~^storage/content(/|$)~i', $filePath))
        );
        $projectFiles = collect(json_decode($validated['project_files'] ?? '[]', true))
            ->filter(fn ($file): bool => is_array($file) && is_string($file['path'] ?? null) && is_string($file['content'] ?? null))
            ->map(function (array $file): array {
                $path = str_replace('\\', '/', $file['path']);
                $content = str_replace(["\r\n", "\r"], "\n", $file['content']);

                return ['path' => $path, 'content' => $content, 'sha256' => hash('sha256', $content)];
            })
            ->reject(fn (array $file): bool => preg_match('~(^|/)\.env($|[./])~i', $file['path'])
                || preg_match('~^(vendor|deploy|deployment|\.git|\.rise-gate)(/|$)~i', $file['path'])
                || (preg_match('~^storage(/|$)~i', $file['path']) && ! preg_match('~^storage/content(/|$)~i', $file['path']))
            )
            ->take(100)
            ->values()
            ->all();

        $development = $request->boolean('development_mode') && $request->boolean('local_file_access') && $request->user()->can('update', $project);

        return [
            'development_mode' => $development,
            'development_instructions' => $development ? LocalDevelopmentContract::instructions() : null,
            'server_apps' => [
                'can_create' => ! $development && $request->user()->can('update', $project),
                'instructions' => $development ? null : ProjectAppContract::instructions(),
            ],
            'local_file_access' => $request->boolean('local_file_access'),
            'auto_save_files' => $request->boolean('auto_save_files'),
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
            'project_files' => $projectFiles,
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
            'image_input_tokens' => $message->image_input_tokens,
            'image_output_tokens' => $message->image_output_tokens,
            'output_tokens' => $message->output_tokens,
            'estimated_cost_usd' => $message->estimated_cost_microusd / 1_000_000,
            'created_at' => $message->created_at->toIso8601String(),
            'image_url' => $message->image_path ? route('projects.ai-chat.messages.image', [$message->thread->project_id, $message]) : null,
            'image_suggested_path' => $message->image_path ? $message->suggestedImagePath() : null,
            'image_name' => $message->image_name,
            'image_save_url' => $message->role === AiChatMessage::ROLE_ASSISTANT && $message->image_path
                ? route('projects.ai-chat.messages.image-saved', [$message->thread->project_id, $message]) : null,
            'image_save' => $message->image_save ? [
                ...$message->image_save,
                'image_url' => route('projects.ai-chat.messages.image', [$message->thread->project_id, $message->image_save['source_message_id']]),
                'saved_url' => route('projects.ai-chat.messages.image-saved', [$message->thread->project_id, $message]),
            ] : null,
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
