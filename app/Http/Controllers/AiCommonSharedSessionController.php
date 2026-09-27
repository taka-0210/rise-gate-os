<?php

namespace App\Http\Controllers;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedAudioWindow;
use App\Models\AiCommonSharedCaptureStream;
use App\Models\AiCommonSharedSession;
use App\Models\AiCommonSharedTranscriptSegment;
use App\Services\AiCommon\AiCommonSharedLongContext;
use App\Services\AiCommon\AiCommonSharedSessionAudioWriter;
use App\Services\AiCommon\AiCommonSharedSessionWriter;
use App\Services\AiCommon\AiCommonSharedTranscriptWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiCommonSharedSessionController extends Controller
{
    public function prepare(Request $request, AiCommonConversation $conversation, AiCommonSharedSessionWriter $writer): RedirectResponse
    {
        $input = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'mode' => ['required', 'in:shared_room'],
        ]);
        $writer->prepare($request->user(), $request->attributes->get('currentCompany'), $conversation, $input);

        return back()->with('status', 'Shared-room Sessionを準備しました。全参加者が用途別Consentを選択してください。');
    }

    public function consent(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedSessionWriter $writer): RedirectResponse
    {
        $input = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'consents' => ['required', 'array:recording,external_asr,transcript_sharing,ai_reference'],
            'consents.*' => ['required', 'in:granted,declined,revoked'],
        ]);
        $writer->decideConsent($request->user(), $request->attributes->get('currentCompany'), $conversation, $session, $input);

        return back()->with('status', '用途別Consentを記録しました。');
    }

    public function join(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedSessionWriter $writer): RedirectResponse
    {
        $writer->join($request->user(), $request->attributes->get('currentCompany'), $conversation, $session);

        return back()->with('status', '現在のSession rosterへ参加しました。');
    }

    public function activate(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedSessionWriter $writer): RedirectResponse
    {
        $writer->activate($request->user(), $request->attributes->get('currentCompany'), $conversation, $session);

        return back()->with('status', 'Sessionを開始しました。');
    }

    public function pause(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedSessionWriter $writer): RedirectResponse
    {
        $writer->pause($request->user(), $request->attributes->get('currentCompany'), $conversation, $session);

        return back()->with('status', 'Sessionを一時停止しました。');
    }

    public function resume(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedSessionWriter $writer): RedirectResponse
    {
        $writer->resume($request->user(), $request->attributes->get('currentCompany'), $conversation, $session);

        return back()->with('status', 'Sessionを再開しました。');
    }

    public function end(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedSessionWriter $writer): RedirectResponse
    {
        $writer->end($request->user(), $request->attributes->get('currentCompany'), $conversation, $session);

        return back()->with('status', 'Sessionを終了し、遅着eventを遮断しました。');
    }

    public function startStream(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedSessionWriter $writer): JsonResponse
    {
        $input = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'client_instance_id' => ['required', 'uuid'],
            'mode' => ['required', 'in:shared_room'],
        ]);
        $stream = $writer->startStream($request->user(), $request->attributes->get('currentCompany'), $conversation, $session, $input);

        return response()->json(['stream_id' => $stream->public_id, 'generation' => $stream->generation, 'sequence' => $stream->sequence]);
    }

    public function stopStream(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedCaptureStream $stream, AiCommonSharedSessionWriter $writer): JsonResponse
    {
        $stream = $writer->stopStream($request->user(), $request->attributes->get('currentCompany'), $conversation, $session, $stream);

        return response()->json(['state' => $stream->state]);
    }

    public function cancelStream(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedCaptureStream $stream, AiCommonSharedSessionWriter $writer): JsonResponse
    {
        $stream = $writer->stopStream($request->user(), $request->attributes->get('currentCompany'), $conversation, $session, $stream, true);

        return response()->json(['state' => $stream->state]);
    }

    public function recordWindow(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedCaptureStream $stream, AiCommonSharedSessionAudioWriter $writer): JsonResponse
    {
        $input = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'generation' => ['required', 'integer', 'min:1'],
            'sequence' => ['required', 'integer', 'min:1'],
            'audio' => ['required', 'file', 'max:10240'],
        ]);
        $window = $writer->recordWindow(
            $request->user(), $request->attributes->get('currentCompany'), $conversation, $session, $stream,
            $request->file('audio'), (int) $input['generation'], (int) $input['sequence'], $input['operation_id'],
        );

        return response()->json(['window_id' => $window->public_id, 'state' => $window->state]);
    }

    public function transcribe(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedAudioWindow $window, AiCommonSharedSessionAudioWriter $writer): JsonResponse
    {
        $input = $request->validate(['operation_id' => ['required', 'uuid']]);
        $window = $writer->transcribe($request->user(), $request->attributes->get('currentCompany'), $conversation, $session, $window, $input['operation_id']);

        return response()->json(['state' => $window->state, 'segments' => $window->segments->count()]);
    }

    public function revise(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedTranscriptSegment $segment, AiCommonSharedTranscriptWriter $writer): RedirectResponse
    {
        $input = $request->validate(['operation_id' => ['required', 'uuid'], 'content' => ['required', 'string', 'max:4000']]);
        $writer->revise($request->user(), $request->attributes->get('currentCompany'), $conversation, $session, $segment, $input['operation_id'], $input['content']);

        return back()->with('status', 'Transcript Revisionを保存しました。');
    }

    public function confirmIdentity(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedTranscriptSegment $segment, AiCommonSharedTranscriptWriter $writer): RedirectResponse
    {
        $input = $request->validate(['operation_id' => ['required', 'uuid']]);
        $writer->confirmSelfIdentity($request->user(), $request->attributes->get('currentCompany'), $conversation, $session, $segment, $input['operation_id']);

        return back()->with('status', '本人操作として話者Identityを確認しました。');
    }

    public function relateSpeakers(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedTranscriptSegment $segment, AiCommonSharedTranscriptWriter $writer): RedirectResponse
    {
        $input = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'to_segment_id' => ['required', 'string'],
            'evidence_reference' => ['required', 'string', 'max:160'],
        ]);
        $to = AiCommonSharedTranscriptSegment::query()->where('public_id', $input['to_segment_id'])->firstOrFail();
        $writer->relateSpeakers($request->user(), $request->attributes->get('currentCompany'), $conversation, $session, $segment, $to, $input['operation_id'], $input['evidence_reference']);

        return back()->with('status', '明示Evidenceに基づくcross-window話者関係を記録しました。');
    }

    public function snapshot(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedLongContext $context): JsonResponse
    {
        $input = $request->validate([
            'client_instance_id' => ['required', 'uuid'],
            'cursor' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json($context->snapshot(
            $request->user(), $request->attributes->get('currentCompany'), $conversation, $session,
            $input['client_instance_id'], (int) ($input['cursor'] ?? 0),
        ))->header('Cache-Control', 'no-store, private');
    }

    public function historical(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedLongContext $context): JsonResponse
    {
        $input = $request->validate(['query' => ['required', 'string', 'max:400']]);

        return response()->json(['results' => $context->historical(
            $request->user(), $request->attributes->get('currentCompany'), $conversation, $session, $input['query'],
        )])->header('Cache-Control', 'no-store, private');
    }

    public function organize(Request $request, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedLongContext $context): RedirectResponse
    {
        $input = $request->validate(['operation_id' => ['required', 'uuid']]);
        $context->organize($request->user(), $request->attributes->get('currentCompany'), $conversation, $session, $input['operation_id']);

        return back()->with('status', 'Session-end candidates are ready for human review; no official record or Action was written.');
    }
}
