<?php

namespace Tests\Unit;

use App\Services\AiCommon\Realtime\CanonicalAudioFrame;
use App\Services\AiCommon\Realtime\DeepgramRequestGuard;
use App\Services\AiCommon\Realtime\DeepgramStreamingAdapter;
use App\Services\AiCommon\Realtime\ProviderNetworkTransport;
use App\Services\AiCommon\Realtime\ProviderStreamingEventEnvelope;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class RealtimeContractTest extends TestCase
{
    public function test_canonical_frame_requires_exact_pcm_contract_and_sample_count(): void
    {
        $frame = CanonicalAudioFrame::fromArray($this->frame());

        $this->assertSame(1600, $frame->endSample);
        $this->assertArrayNotHasKey('provider', $frame->toArray());

        $this->expectException(ValidationException::class);
        CanonicalAudioFrame::fromArray([...$this->frame(), 'sample_count' => 1599]);
    }

    public function test_partial_is_ephemeral_and_cannot_become_a_durable_receipt(): void
    {
        $event = new ProviderStreamingEventEnvelope(
            'synthetic', 'v1', 'provider-session', ProviderStreamingEventEnvelope::PARTIAL,
            null, null, 1, '途中です', null, null, null, 'unverified', [], [],
        );

        $this->expectException(LogicException::class);
        $event->durableReceipt();
    }

    public function test_mip_and_audio_send_fences_run_before_transport(): void
    {
        config()->set('ai-common-realtime.enabled', true);
        config()->set('ai-common-realtime.audio_send_enabled', false);
        $transport = new RealtimeFakeTransport;

        try {
            app(DeepgramRequestGuard::class)->open(['mip_opt_out' => true, 'automatic_retry' => false], $transport);
            $this->fail('The disabled audio-send fence must reject before transport.');
        } catch (ValidationException) {
            $this->assertSame(0, $transport->calls);
        }

        config()->set('ai-common-realtime.audio_send_enabled', true);
        try {
            app(DeepgramRequestGuard::class)->open(['mip_opt_out' => false, 'automatic_retry' => false], $transport);
            $this->fail('A false MIP flag must fail closed.');
        } catch (ValidationException) {
            $this->assertSame(0, $transport->calls);
        }

        $result = app(DeepgramRequestGuard::class)->open(['mip_opt_out' => true, 'automatic_retry' => false], $transport);
        $this->assertSame('opened', $result);
        $this->assertSame(1, $transport->calls);
    }

    public function test_deepgram_payload_is_normalized_without_raw_payload_persistence(): void
    {
        $event = app(DeepgramStreamingAdapter::class)->normalize([
            'type' => 'Results',
            'request_id' => 'provider-raw-identity',
            'is_final' => true,
            'channel' => ['alternatives' => [[
                'transcript' => 'こんにちは。',
                'words' => [['start' => 0.1, 'end' => 0.4, 'confidence' => 0.98, 'speaker' => 0]],
            ]]],
        ], 'internal-provider-session', 1, [
            'verification_state' => 'verified', 'start_sample' => 0, 'end_sample' => 1600,
        ]);

        $receipt = $event->durableReceipt();
        $this->assertSame(ProviderStreamingEventEnvelope::FINAL, $event->eventType);
        $this->assertSame(hash('sha256', 'こんにちは。'), $receipt['final_content_sha256']);
        $this->assertSame(['speaker-0'], $event->anonymousSpeakers);
        $this->assertArrayNotHasKey('raw_payload', $receipt);
        $this->assertNotSame('provider-raw-identity', $receipt['provider_event_identity_hash']);
    }

    public function test_deepgram_final_results_without_text_are_metadata_and_never_durable_finals(): void
    {
        foreach ([
            [],
            ['channel' => ['alternatives' => [['transcript' => null, 'words' => []]]]],
            ['channel' => ['alternatives' => [['transcript' => '   ', 'words' => []]]]],
        ] as $index => $shape) {
            $event = app(DeepgramStreamingAdapter::class)->normalize([
                'type' => 'Results',
                'request_id' => 'empty-final-'.$index,
                'is_final' => true,
                'start' => 0,
                'duration' => 0.74,
                ...$shape,
            ], 'internal-provider-session', $index + 1, [
                'verification_state' => 'verified', 'start_sample' => 0, 'end_sample' => 11840,
            ]);

            $receipt = $event->durableReceipt();
            $this->assertSame(ProviderStreamingEventEnvelope::METADATA, $event->eventType);
            $this->assertNull($event->content);
            $this->assertNull($event->contentSha256);
            $this->assertSame(ProviderStreamingEventEnvelope::METADATA, $receipt['normalized_event_type']);
            $this->assertNull($receipt['normalized_final_metadata']);
        }
    }

    private function frame(): array
    {
        return [
            'lease_id' => '11111111-1111-4111-8111-111111111111',
            'stream_id' => 'stream-01',
            'generation' => 1,
            'sequence' => 1,
            'client_event_id' => '22222222-2222-4222-8222-222222222222',
            'start_sample' => 0,
            'end_sample' => 1600,
            'sample_count' => 1600,
            'sample_rate' => 16000,
            'bit_depth' => 16,
            'channels' => 1,
            'format' => 'pcm_s16le',
            'content_sha256' => str_repeat('a', 64),
        ];
    }
}

class RealtimeFakeTransport implements ProviderNetworkTransport
{
    public int $calls = 0;

    public function open(array $request): mixed
    {
        $this->calls++;

        return 'opened';
    }
}
