<?php

namespace App\Http\Controllers;

use App\Models\AiCommonAttachment;
use App\Models\AiCommonAttachmentDerivative;
use App\Models\AiCommonConversation;
use App\Models\AiCommonTemporaryAudio;
use App\Models\AiCommonTranscriptRevision;
use App\Models\ProjectInternalNoteAttachment;
use App\Services\AiCommon\AiCommonAttachmentAccess;
use App\Services\AiCommon\AiCommonAttachmentDeriver;
use App\Services\AiCommon\AiCommonAttachmentIngestor;
use App\Services\AiCommon\AiCommonAttachmentTranscriptWriter;
use App\Services\AiCommon\AiCommonAttachmentWriter;
use App\Services\AiCommon\AiCommonTemporaryAudioWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiCommonInputController extends Controller
{
    public function upload(
        Request $request,
        AiCommonConversation $conversation,
        AiCommonAttachmentIngestor $ingestor,
    ): RedirectResponse {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:10240'],
        ]);
        $attachment = $ingestor->upload(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $request->file('file'),
            $validated['operation_id'],
        );

        return back()->with(
            $attachment->state === AiCommonAttachment::STATE_READY ? 'status' : 'error',
            $attachment->state === AiCommonAttachment::STATE_READY
                ? 'Attachmentを安全に受け付けました。AI参照はOFFです。'
                : 'Attachmentは非公開quarantineにあります。検査完了まで利用できません。',
        );
    }

    public function reference(
        Request $request,
        AiCommonConversation $conversation,
        AiCommonAttachmentIngestor $ingestor,
    ): RedirectResponse {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'origin_public_id' => ['required', 'string', 'max:64'],
        ]);
        $origin = ProjectInternalNoteAttachment::query()
            ->where('public_id', $validated['origin_public_id'])->firstOrFail();
        $attachment = $ingestor->referenceExisting(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $origin,
            $validated['operation_id'],
        );

        return back()->with(
            $attachment->state === AiCommonAttachment::STATE_READY ? 'status' : 'error',
            $attachment->state === AiCommonAttachment::STATE_READY
                ? '既存FileをcopyせずConversationへ参照接続しました。'
                : '既存File参照は検査完了まで利用できません。',
        );
    }

    public function download(
        Request $request,
        AiCommonConversation $conversation,
        AiCommonAttachment $attachment,
        AiCommonAttachmentAccess $access,
    ): StreamedResponse {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeAttachment($request->user(), $organization, $conversation, $attachment, true);
        $headers = [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
        ];
        if ($attachment->variant === AiCommonAttachment::VARIANT_UPLOAD) {
            return Storage::disk('ai_common_attachments')->download(
                $attachment->storage_key,
                $attachment->display_name,
                $headers,
            );
        }
        $origin = $access->authorizeExistingOrigin(
            $request->user(),
            $organization,
            $attachment->origin_public_id,
        );

        return Storage::disk('local')->download($origin->stored_path, $attachment->display_name, $headers);
    }

    public function revoke(
        Request $request,
        AiCommonConversation $conversation,
        AiCommonAttachment $attachment,
        AiCommonAttachmentWriter $writer,
    ): RedirectResponse {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $writer->revoke(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $attachment,
            $validated,
        );

        return back()->with('status', 'Attachmentの利用を取り消しました。');
    }

    public function extract(Request $request, AiCommonConversation $conversation, AiCommonAttachment $attachment, AiCommonAttachmentDeriver $deriver): RedirectResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'page_from' => ['nullable', 'integer', 'min:1'],
            'page_to' => ['nullable', 'integer', 'min:1'],
            'paragraph_from' => ['nullable', 'integer', 'min:1'],
            'paragraph_to' => ['nullable', 'integer', 'min:1'],
            'sheet_index' => ['nullable', 'integer', 'min:1'],
            'row_from' => ['nullable', 'integer', 'min:1'],
            'row_to' => ['nullable', 'integer', 'min:1'],
        ]);
        $selector = collect($validated)->except('operation_id')->filter(fn ($value) => $value !== null)->all();
        $derivative = $deriver->extract($request->user(), $request->attributes->get('currentCompany'), $conversation, $attachment, $validated['operation_id'], $selector);

        return back()->with(
            $derivative->state === AiCommonAttachmentDerivative::STATE_READY ? 'status' : 'error',
            $derivative->state === AiCommonAttachmentDerivative::STATE_READY
                ? 'Bounded extraction completed. No AI request was sent.'
                : 'Extraction failed safely: '.$derivative->safe_error_code,
        );
    }

    public function preview(Request $request, AiCommonConversation $conversation, AiCommonAttachment $attachment, AiCommonAttachmentDerivative $derivative, AiCommonAttachmentAccess $access): Response
    {
        $access->authorizeAttachment($request->user(), $request->attributes->get('currentCompany'), $conversation, $attachment, true);
        if ($derivative->ai_common_attachment_id !== $attachment->id || $derivative->state !== AiCommonAttachmentDerivative::STATE_READY) {
            abort(404);
        }

        return response($derivative->content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        ]);
    }

    public function aiReference(Request $request, AiCommonConversation $conversation, AiCommonAttachment $attachment, AiCommonAttachmentWriter $writer): RedirectResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        $writer->setAiReference($request->user(), $request->attributes->get('currentCompany'), $conversation, $attachment, $validated);

        return back()->with('status', 'Attachment AI reference preference was updated.');
    }

    public function imagePreview(Request $request, AiCommonConversation $conversation, AiCommonAttachment $attachment, AiCommonAttachmentAccess $access): Response
    {
        if (! in_array($attachment->extension, ['jpg', 'jpeg', 'png'], true)) {
            abort(404);
        }
        $binary = $access->binary($request->user(), $request->attributes->get('currentCompany'), $conversation, $attachment);

        return response($binary, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function playback(Request $request, AiCommonConversation $conversation, AiCommonAttachment $attachment, AiCommonAttachmentAccess $access): Response
    {
        if (! in_array($attachment->extension, ['mp3', 'm4a', 'mp4', 'webm', 'wav'], true)) {
            abort(404);
        }
        $binary = $access->binary($request->user(), $request->attributes->get('currentCompany'), $conversation, $attachment);
        $start = 0;
        $end = strlen($binary) - 1;
        $status = 200;
        if ($range = $request->header('Range')) {
            if (! preg_match('/^bytes=(\d+)-(\d*)$/', $range, $match)) {
                return response('', 416, ['Content-Range' => 'bytes */'.strlen($binary)]);
            }
            $start = (int) $match[1];
            $end = $match[2] === '' ? $end : min($end, (int) $match[2]);
            if ($start > $end) {
                return response('', 416, ['Content-Range' => 'bytes */'.strlen($binary)]);
            }
            $status = 206;
        }
        $body = substr($binary, $start, $end - $start + 1);
        $headers = [
            'Content-Type' => $attachment->mime_type,
            'Content-Length' => (string) strlen($body),
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.strlen($binary);
        }

        return response($body, $status, $headers);
    }

    public function attachmentTranscribe(Request $request, AiCommonConversation $conversation, AiCommonAttachment $attachment, AiCommonAttachmentTranscriptWriter $writer): RedirectResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'transcription_consent' => ['accepted'],
        ]);
        $writer->transcribe(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $attachment,
            $validated['operation_id'],
            true,
        );

        return back()->with('status', 'An immutable transcript revision was created. No chat request was sent.');
    }

    public function transcriptRevise(Request $request, AiCommonConversation $conversation, AiCommonAttachment $attachment, AiCommonTranscriptRevision $revision, AiCommonAttachmentTranscriptWriter $writer): RedirectResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'content' => ['required', 'string', 'max:4000'],
        ]);
        $writer->revise(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $attachment,
            $revision,
            $validated['operation_id'],
            $validated['content'],
        );

        return back()->with('status', 'A new immutable human transcript revision was created.');
    }

    public function voiceRecord(
        Request $request,
        AiCommonConversation $conversation,
        AiCommonTemporaryAudioWriter $writer,
    ): RedirectResponse {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'voice' => ['required', 'file', 'max:10240'],
        ]);
        $writer->record(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $request->file('voice'),
            $validated['operation_id'],
        );

        return back()->with('status', 'Temporary Voiceを保存しました。まだ外部送信も投稿もしていません。');
    }

    public function voiceTranscribe(
        Request $request,
        AiCommonConversation $conversation,
        AiCommonTemporaryAudio $audio,
        AiCommonTemporaryAudioWriter $writer,
    ): RedirectResponse {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'transcription_consent' => ['accepted'],
        ]);
        $writer->transcribe(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $audio,
            $validated['operation_id'],
            true,
        );

        return back()->with('status', '文字起こしdraftを作成しました。確認・編集後に明示投稿してください。');
    }

    public function voicePost(
        Request $request,
        AiCommonConversation $conversation,
        AiCommonTemporaryAudio $audio,
        AiCommonTemporaryAudioWriter $writer,
    ): RedirectResponse {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'content' => ['required', 'string', 'max:4000'],
        ]);
        $writer->postDraft(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $audio,
            $validated['operation_id'],
            $validated['content'],
        );

        return back()->with('status', '確認済みTranscriptをHuman Messageとして保存しました。AI Requestは送信していません。');
    }

    public function voiceCancel(
        Request $request,
        AiCommonConversation $conversation,
        AiCommonTemporaryAudio $audio,
        AiCommonTemporaryAudioWriter $writer,
    ): RedirectResponse {
        $writer->cancel(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $audio,
        );

        return back()->with('status', 'Temporary Voiceを取り消し、cleanup対象へ移しました。');
    }
}
