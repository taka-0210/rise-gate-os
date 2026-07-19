<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectInternalNote;
use App\Models\ProjectInternalNoteAttachment;
use App\Models\ProjectMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectInternalNoteController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeInternalMember($request, $project);
        Gate::authorize('update', $project);
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:10000', 'required_without:attachments'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,csv,xlsx,docx'],
        ]);
        $note = $project->internalNotes()->create([
            'body' => $validated['body'] ?? '',
            'user_id' => $request->user()->id,
        ]);
        foreach ($request->file('attachments', []) as $file) {
            $attachment = new ProjectInternalNoteAttachment(['public_id' => (string) Str::ulid()]);
            $extension = strtolower($file->getClientOriginalExtension());
            $path = $file->storeAs(
                'project-internal-notes/'.$project->public_id.'/'.$note->id,
                $attachment->public_id.($extension ? '.'.$extension : ''),
                'local'
            );
            $attachment->fill([
                'project_internal_note_id' => $note->id,
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

        return redirect()->route('projects.show', $project)->with('status', '社内メモを追加しました。');
    }

    public function destroy(Request $request, Project $project, ProjectInternalNote $internalNote): RedirectResponse
    {
        $this->authorizeInternalMember($request, $project);
        Gate::authorize('update', $project);
        abort_unless($internalNote->project_id === $project->id, 404);
        $internalNote->load('attachments');
        foreach ($internalNote->attachments as $attachment) {
            Storage::disk('local')->delete($attachment->stored_path);
        }
        $internalNote->delete();

        return redirect()->route('projects.show', $project)->with('status', '社内メモを削除しました。');
    }

    public function view(Request $request, Project $project, ProjectInternalNote $internalNote, ProjectInternalNoteAttachment $attachment): StreamedResponse
    {
        $this->authorizeAttachment($request, $project, $internalNote, $attachment);
        abort_unless($attachment->isImage(), 404);

        return Storage::disk('local')->response($attachment->stored_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(Request $request, Project $project, ProjectInternalNote $internalNote, ProjectInternalNoteAttachment $attachment): StreamedResponse
    {
        $this->authorizeAttachment($request, $project, $internalNote, $attachment);

        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeAttachment(Request $request, Project $project, ProjectInternalNote $note, ProjectInternalNoteAttachment $attachment): void
    {
        $this->authorizeInternalMember($request, $project);
        abort_unless($note->project_id === $project->id && $attachment->project_internal_note_id === $note->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404);
    }

    private function authorizeInternalMember(Request $request, Project $project): void
    {
        Gate::authorize('view', $project);
        $role = $project->members()->where('user_id', $request->user()->id)->where('status', ProjectMember::STATUS_ACTIVE)->value('project_role');
        if ($role === ProjectMember::ROLE_CLIENT) {
            throw new HttpException(403);
        }
    }
}
