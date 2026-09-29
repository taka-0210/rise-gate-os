<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedCaptureStream;
use App\Models\AiCommonSharedProviderSession;
use App\Models\AiCommonSharedRelayLease;
use App\Models\AiCommonSharedSession;
use App\Models\AiCommonSharedSourceRange;
use App\Models\Organization;
use App\Services\AiCommon\Realtime\CanonicalAudioFrame;
use App\Services\AiCommon\Realtime\DeepgramStreamingAdapter;
use App\Services\AiCommon\Realtime\ProviderStreamingEventEnvelope;
use App\Services\AiCommon\Realtime\RealtimeDurableFinalCommitter;
use App\Services\AiCommon\Realtime\RealtimeProviderEventStore;
use App\Services\AiCommon\Realtime\RealtimeSourceLedger;
use App\Services\AiCommon\Realtime\SafeRealtimeReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RealtimeRelayBridgeController extends Controller
{
    public function open(Request $request): JsonResponse
    {
        $this->authorizeBridge($request);
        $input = $request->validate(['lease_id' => ['required', 'uuid'], 'stream_id' => ['required', 'string', 'max:64'], 'generation' => ['required', 'integer', 'min:1']]);
        $provider = DB::transaction(function () use ($input): AiCommonSharedProviderSession {
            $lease = AiCommonSharedRelayLease::query()->where('public_id', $input['lease_id'])->lockForUpdate()->firstOrFail();
            $stream = AiCommonSharedCaptureStream::query()->where('public_id', $input['stream_id'])->lockForUpdate()->firstOrFail();
            if ($lease->state !== AiCommonSharedRelayLease::STATE_ACTIVE || $lease->expires_at_utc->lte(now()) || $stream->state !== AiCommonSharedCaptureStream::STATE_RECORDING
                || $lease->ai_common_shared_capture_stream_id !== $stream->id || $lease->generation !== (int) $input['generation'] || $stream->generation !== (int) $input['generation']) {
                throw ValidationException::withMessages(['relay' => SafeRealtimeReason::AuthorizationChanged->value]);
            }
            $existing = AiCommonSharedProviderSession::query()->where('ai_common_shared_capture_stream_id', $stream->id)->where('generation', $stream->generation)->first();
            if ($existing) {
                if ($existing->relay_lease_id !== $lease->id || ! in_array($existing->state, ['connecting', 'open'], true)) {
                    throw ValidationException::withMessages(['relay' => SafeRealtimeReason::GenerationSuperseded->value]);
                }
                return $existing;
            }
            return AiCommonSharedProviderSession::query()->create([
                'relay_lease_id' => $lease->id, 'ai_common_shared_capture_stream_id' => $stream->id, 'generation' => $stream->generation,
                'adapter' => 'deepgram', 'adapter_version' => (string) config('ai-common-realtime.deepgram.adapter_version'),
                'capability_profile_version' => 'nova3-ja-v1', 'state' => 'connecting',
            ]);
        }, 3);
        return response()->json(['provider_session_id' => $provider->public_id, 'state' => $provider->state]);
    }

    public function opened(Request $request): JsonResponse
    {
        $this->authorizeBridge($request);
        $input = $request->validate(['provider_session_id' => ['required', 'uuid'], 'provider_session_reference' => ['nullable', 'string', 'max:200']]);
        $provider = $this->provider($input['provider_session_id']);
        $provider->update([
            'provider_session_reference_hash' => filled($input['provider_session_reference'] ?? null) ? hash('sha256', $input['provider_session_reference']) : null,
            'state' => 'open', 'opened_at_utc' => now(),
        ]);
        return response()->json(['state' => 'open']);
    }

    public function frame(Request $request, RealtimeSourceLedger $ledger): JsonResponse
    {
        $this->authorizeBridge($request);
        $input = $request->validate(['provider_session_id' => ['required', 'uuid'], 'frame' => ['required', 'array'], 'binary_hash_verified' => ['required', 'accepted']]);
        $provider = $this->provider($input['provider_session_id']);
        $lease = AiCommonSharedRelayLease::query()->findOrFail($provider->relay_lease_id);
        $range = $ledger->accept(CanonicalAudioFrame::fromArray($input['frame']), $lease);
        return response()->json(['source_range_id' => $range->public_id, 'state' => $range->state]);
    }

    public function sent(Request $request, RealtimeSourceLedger $ledger): JsonResponse
    {
        $this->authorizeBridge($request);
        $input = $request->validate(['provider_session_id' => ['required', 'uuid'], 'source_range_id' => ['required', 'uuid']]);
        $provider = $this->provider($input['provider_session_id']);
        $range = AiCommonSharedSourceRange::query()->where('public_id', $input['source_range_id'])->firstOrFail();
        $sent = $ledger->sent($provider, $range);
        return response()->json(['send_ordinal' => $sent->send_ordinal, 'state' => $sent->state]);
    }

    public function event(Request $request, DeepgramStreamingAdapter $adapter, RealtimeSourceLedger $ledger, RealtimeProviderEventStore $store, RealtimeDurableFinalCommitter $committer): JsonResponse
    {
        $this->authorizeBridge($request);
        $input = $request->validate(['provider_session_id' => ['required', 'uuid'], 'receive_order' => ['required', 'integer', 'min:1'], 'event' => ['required', 'array']]);
        $provider = $this->provider($input['provider_session_id']);
        $event = $input['event'];
        $start = isset($event['start']) && is_numeric($event['start']) ? (int) round((float) $event['start'] * 16000) : -1;
        $duration = isset($event['duration']) && is_numeric($event['duration']) ? (int) round((float) $event['duration'] * 16000) : 0;
        $mapping = $start >= 0 && $duration > 0 ? $ledger->sourceMapping($provider, $start, $start + $duration) : ['verification_state' => 'unverified'];
        $envelope = $adapter->normalize($event, $provider->public_id, (int) $input['receive_order'], $mapping);
        if ($envelope->eventType === ProviderStreamingEventEnvelope::PARTIAL) {
            return response()->json(['type' => 'partial', 'provider_session_id' => $provider->public_id, 'expected_final_receive_order' => $envelope->receiveOrder, 'content' => $envelope->content]);
        }
        $receipt = $store->persist($envelope, $provider);
        if ($envelope->eventType !== ProviderStreamingEventEnvelope::FINAL || ! $receipt || $receipt->status !== 'accepted') {
            return response()->json(['type' => $receipt?->status === 'rejected' ? 'rejected' : $envelope->eventType, 'safe_reason_code' => $receipt?->safe_reason_code]);
        }
        [$actor, $organization, $conversation, $session] = $this->commitContext($provider);
        $commit = $committer->commit($actor, $organization, $conversation, $session, $receipt, (string) Str::uuid());
        return response()->json([
            'type' => 'durable_final', 'provider_session_id' => $provider->public_id, 'receive_order' => $envelope->receiveOrder,
            'content' => $envelope->content, 'speaker_count' => count($envelope->anonymousSpeakers), 'commit_id' => $commit->public_id,
        ]);
    }

    public function close(Request $request): JsonResponse
    {
        $this->authorizeBridge($request);
        $input = $request->validate(['provider_session_id' => ['required', 'uuid'], 'normal' => ['required', 'boolean'], 'safe_reason_code' => ['required', 'string', 'max:80']]);
        $provider = $this->provider($input['provider_session_id']);
        $provider->update(['state' => $input['normal'] ? 'closed' : 'failed', 'closed_at_utc' => now(), 'safe_reason_code' => $input['safe_reason_code']]);
        AiCommonSharedRelayLease::query()->whereKey($provider->relay_lease_id)->update([
            'state' => AiCommonSharedRelayLease::STATE_CLOSED, 'closed_at_utc' => now(), 'safe_reason_code' => $input['safe_reason_code'],
        ]);
        return response()->json(['state' => $provider->fresh()->state]);
    }

    private function authorizeBridge(Request $request): void
    {
        $configured = (string) config('ai-common-realtime.bridge_token');
        if (! config('ai-common-realtime.enabled') || ! config('ai-common-realtime.audio_send_enabled') || strlen($configured) < 32
            || ! in_array($request->ip(), ['127.0.0.1', '::1'], true)
            || ! hash_equals($configured, (string) $request->header('X-CompanyOS-Relay-Token'))) {
            abort(404);
        }
    }

    private function provider(string $publicId): AiCommonSharedProviderSession
    {
        $provider = AiCommonSharedProviderSession::query()->where('public_id', $publicId)->firstOrFail();
        if (! in_array($provider->state, ['connecting', 'open'], true)) {
            throw ValidationException::withMessages(['relay' => SafeRealtimeReason::ProviderUnavailable->value]);
        }
        return $provider;
    }

    private function commitContext(AiCommonSharedProviderSession $provider): array
    {
        $stream = AiCommonSharedCaptureStream::query()->with('operatorParticipant.user')->findOrFail($provider->ai_common_shared_capture_stream_id);
        $lease = AiCommonSharedRelayLease::query()->findOrFail($provider->relay_lease_id);
        return [
            $stream->operatorParticipant->user, Organization::query()->findOrFail($lease->organization_id),
            AiCommonConversation::query()->findOrFail($lease->ai_common_conversation_id), AiCommonSharedSession::query()->findOrFail($lease->ai_common_shared_session_id),
        ];
    }
}
