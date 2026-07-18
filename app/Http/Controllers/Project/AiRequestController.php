<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\AiRequestAttachment;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        ]);
        $aiRequest = $project->aiRequests()->create([
            'title' => $validated['title'],
            'instructions' => $validated['instructions'],
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

        return back()->with('status', 'AIへの依頼を受け付けました。Codexが提案を返すまでお待ちください。');
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
