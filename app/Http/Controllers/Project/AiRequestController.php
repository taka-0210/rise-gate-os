<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\AiRequestAttachment;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiRequestController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace && $project->owning_workspace_id === $workspace->id, 404);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['required', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,csv,xlsx,docx'],
            'internal_note_ids' => ['nullable', 'array', 'max:10'],
            'internal_note_ids.*' => ['integer', 'distinct'],
        ]);
        $internalNotes = collect();
        if (! empty($validated['internal_note_ids'])) {
            $role = $project->members()
                ->where('user_id', $request->user()->id)
                ->where('status', ProjectMember::STATUS_ACTIVE)
                ->value('project_role');
            abort_if($role === ProjectMember::ROLE_CLIENT, 403);

            $internalNotes = $project->internalNotes()
                ->with(['user', 'attachments', 'references'])
                ->whereIn('id', $validated['internal_note_ids'])
                ->get();
            if ($internalNotes->count() !== count($validated['internal_note_ids'])) {
                throw ValidationException::withMessages([
                    'internal_note_ids' => '選択した社内メモを確認できませんでした。画面を更新して選び直してください。',
                ]);
            }
        }

        $selectedAttachmentCount = $internalNotes->sum(fn ($note) => $note->attachments->count());
        if (count($request->file('attachments', [])) + $selectedAttachmentCount > 10) {
            throw ValidationException::withMessages([
                'attachments' => '直接添付と社内メモの資料を合わせて10件以内にしてください。',
            ]);
        }

        $instructions = $validated['instructions'];
        if ($internalNotes->isNotEmpty()) {
            $instructions .= "\n\n---\n【この依頼で選択された社内メモ・資料】";
            foreach ($internalNotes as $note) {
                $author = $note->user?->name ?: '投稿者不明';
                $instructions .= "\n\n社内メモ #{$note->id}（{$author}／{$note->created_at->format('Y-m-d H:i')}）";
                $instructions .= "\n".($note->body ?: '本文なし（添付資料のみ）');
                if ($note->attachments->isNotEmpty()) {
                    $instructions .= "\n添付資料: ".$note->attachments->pluck('original_name')->join('、');
                }
                foreach ($note->references->where('share_with_ai', true) as $reference) {
                    $instructions .= "\n参考URL: {$reference->url}";
                    if ($reference->title) $instructions .= "\n名称: {$reference->title}";
                    if ($reference->reference_points) $instructions .= "\n参考にする点: {$reference->reference_points}";
                    if ($reference->avoid_points) $instructions .= "\n取り入れない点: {$reference->avoid_points}";
                    $instructions .= "\n注意: そのまま模倣せず、このProjectの要件とブランドに合わせて再構成してください。";
                }
            }
        }
        $aiRequest = $project->aiRequests()->create([
            'title' => $validated['title'],
            'instructions' => $instructions,
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'requested_by' => $request->user()->id,
            'status' => AiRequest::STATUS_PENDING,
        ]);
        foreach ($request->file('attachments', []) as $file) {
            $attachment = new AiRequestAttachment(['public_id' => (string) Str::ulid()]);
            $extension = strtolower($file->getClientOriginalExtension());
            $path = $file->storeAs(
                'ai-requests/'.$aiRequest->public_id,
                $attachment->public_id.($extension ? '.'.$extension : ''),
                'local'
            );
            $attachment->fill([
                'ai_request_id' => $aiRequest->id,
                'workspace_id' => $workspace->id,
                'project_id' => $project->id,
                'uploaded_by' => $request->user()->id,
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'extension' => $extension ?: null,
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
            ])->save();
        }
        foreach ($internalNotes as $note) {
            foreach ($note->attachments as $source) {
                if (! Storage::disk('local')->exists($source->stored_path)) {
                    continue;
                }
                $attachment = new AiRequestAttachment(['public_id' => (string) Str::ulid()]);
                $targetPath = 'ai-requests/'.$aiRequest->public_id.'/'.$attachment->public_id
                    .($source->extension ? '.'.$source->extension : '');
                Storage::disk('local')->copy($source->stored_path, $targetPath);
                $attachment->fill([
                    'ai_request_id' => $aiRequest->id,
                    'workspace_id' => $workspace->id,
                    'project_id' => $project->id,
                    'uploaded_by' => $request->user()->id,
                    'original_name' => $source->original_name,
                    'stored_path' => $targetPath,
                    'mime_type' => $source->mime_type,
                    'extension' => $source->extension,
                    'size_bytes' => $source->size_bytes,
                    'sha256' => $source->sha256,
                ])->save();
            }
        }

        $copyText = "RISE GATE OSのプロジェクト「{$project->name}」にAI依頼「{$aiRequest->title}」を登録しました。"
            .'未処理のAI依頼を確認し、この依頼を進めてください。';

        return back()->with([
            'status' => 'AIへの依頼を受け付けました。下の文章をコピーしてCodexへ送ってください。',
            'ai_request_copy_text' => $copyText,
        ]);
    }

    public function download(Request $request, Project $project, AiRequest $aiRequest, AiRequestAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $project);
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace && $project->owning_workspace_id === $workspace->id, 404);
        abort_unless($aiRequest->project_id === $project->id && $attachment->ai_request_id === $aiRequest->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404);

        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
