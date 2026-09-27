<section class='shared-card' aria-labelledby='shared-session-heading'>
<h2 id='shared-session-heading'>Shared-room Session / Voice</h2>
<p class='shared-muted'>Human Message storage and AI Request are separate. Audio is limited to independently decodable windows of 60 seconds or less.</p>
@if(!$session)
    @if($conversation->status === 'active' && $participant->role === 'owner')
    <form method='post' action='{{ route('ai-common.shared.sessions.prepare', $conversation) }}'>
        @csrf<input type='hidden' name='operation_id' value='{{ Str::uuid() }}'><input type='hidden' name='mode' value='shared_room'>
        <button type='submit'>Prepare shared-room Session</button>
    </form>
    @else<p>The Owner must prepare a Session before each participant records purpose-specific consent.</p>@endif
@else
    <p><strong>State:</strong> {{ $session->state }} / <strong>Mode:</strong> {{ $session->mode }} / <strong>Sequence:</strong> {{ $session->sequence }}</p>
    <p><strong>Bounded roster:</strong>
    @foreach($session->participants()->with('user')->orderBy('id')->get() as $sessionMember)
        {{ $sessionMember->user->name }} ({{ $sessionMember->role }})@if(!$loop->last), @endif
    @endforeach
    </p>
    @if($session->state !== 'ended')
    <form method='post' action='{{ route('ai-common.shared.sessions.consent', [$conversation, $session]) }}'>
        @csrf<input type='hidden' name='operation_id' value='{{ Str::uuid() }}'>
        @foreach(['recording' => 'Recording', 'external_asr' => 'External ASR', 'transcript_sharing' => 'Transcript Sharing', 'ai_reference' => 'AI Reference'] as $purpose => $label)
        <label>{{ $label }}<select name='consents[{{ $purpose }}]' required><option value='granted'>Grant</option><option value='declined'>Decline</option><option value='revoked'>Revoke</option></select></label>
        @endforeach
        <button type='submit'>Record my consent decisions</button>
    </form>
    <div class='shared-row'>
        @if(in_array($session->state, ['prepared','interrupted']))<form method='post' action='{{ route('ai-common.shared.sessions.activate', [$conversation, $session]) }}'>@csrf<button>Start Session</button></form>@endif
        @if($session->state === 'active')<form method='post' action='{{ route('ai-common.shared.sessions.pause', [$conversation, $session]) }}'>@csrf<button>Pause</button></form>@endif
        @if($session->state === 'paused')<form method='post' action='{{ route('ai-common.shared.sessions.resume', [$conversation, $session]) }}'>@csrf<button>Resume</button></form>@endif
        <form method='post' action='{{ route('ai-common.shared.sessions.end', [$conversation, $session]) }}'>@csrf<button>End Session</button></form>
    </div>
    @if($session->state === 'active')
    <div id='shared-session-recorder' data-start-url='{{ route('ai-common.shared.sessions.streams.start', [$conversation, $session]) }}' data-stream-base='{{ url('/company/co/shared-conversations/'.$conversation->getRouteKey().'/sessions/'.$session->getRouteKey().'/streams') }}' data-window-base='{{ url('/company/co/shared-conversations/'.$conversation->getRouteKey().'/sessions/'.$session->getRouteKey().'/windows') }}' data-snapshot-url='{{ route('ai-common.shared.sessions.snapshot', [$conversation, $session]) }}' data-shared-room-sequence='{{ $coState->room_sequence ?? $session->room_sequence ?? 0 }}' data-csrf='{{ csrf_token() }}'>
        <p data-session-recorder-status role='status'>Recorder stopped</p>
        <div class='shared-row'><button type='button' data-session-record-start>Start continuous voice</button><button type='button' data-session-record-stop disabled>Stop normally</button><button type='button' data-session-record-cancel disabled>Cancel</button></div>
        <p class='shared-muted'>Ver.1 permits one shared-room capture stream. Distributed multi-mic is out of scope.</p>
    </div>
    @endif
    @endif
@endif
</section>

@if($session && $transcriptRows !== [])
<section class='shared-card shared-presence' aria-labelledby='shared-presence-heading'
    @if($coState) data-shared-room-snapshot='{{ route('ai-common.shared.sessions.snapshot', [$conversation, $session]) }}' data-shared-room-sequence='{{ $coState->room_sequence ?? 0 }}' @endif>
    <h2 id='shared-presence-heading'>One Shared CO / Conversation Atmosphere</h2>
    <p class='shared-muted'>These labels project server state. Queued is not provider processing, and Context lag is not presented as understanding.</p>
    <dl class='shared-state-grid'>
        <div><dt>CO Presence</dt><dd data-presence-state>{{ $coState->presence_state ?? 'ready' }}</dd></div>
        <div><dt>Capture</dt><dd data-capture-state>{{ $coState->capture_state ?? 'inactive' }}</dd></div>
        <div><dt>ASR</dt><dd data-asr-state>{{ $coState->asr_state ?? 'idle' }}</dd></div>
        <div><dt>Context</dt><dd data-context-state>{{ $coState->context_state ?? ($session->context_status ?? 'empty') }}</dd></div>
    </dl>
    <p role='status' data-context-watermark>Context watermark: segment {{ $coState->context_watermark_segment_id ?? 'none' }}</p>
    <noscript><p>Live multi-device state requires JavaScript. Reload for the current authorized snapshot.</p></noscript>
    @if($p4Checkpoint)
        <details><summary>Rolling Context checkpoint {{ $p4Checkpoint->revision_no }}</summary>
            <pre class='shared-context-text'>{{ json_encode(json_decode($p4Checkpoint->structured_context, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            <p class='shared-muted'>Derived context only. It is not Company Memory or an official Decision.</p>
        </details>
        <form method='get' action='{{ route('ai-common.shared.sessions.historical', [$conversation, $session]) }}'>
            <label>Bounded historical retrieval<input name='query' maxlength='400' required></label>
            <button>Retrieve authorized ranges</button>
        </form>
    @endif
    @if($session->state === 'ended' && $p4Checkpoint)
        <form method='post' action='{{ route('ai-common.shared.sessions.organize', [$conversation, $session]) }}'>
            @csrf<input type='hidden' name='operation_id' value='{{ Str::uuid() }}'>
            <button>Organize this ended Session</button>
            <p class='shared-muted'>Explicit request only. Candidates are not official records and no Action is written automatically.</p>
        </form>
    @endif
    @foreach($sessionEndRuns as $run)
        <h3>Session-end candidates</h3>
        <ul>@foreach($run->candidates as $candidate)<li><strong>{{ $candidate->kind }}</strong>: {{ $candidate->content }}</li>@endforeach</ul>
    @endforeach
</section>
@endif

@if($transcriptRows !== [])
<section class='shared-card' aria-labelledby='shared-transcript-heading'><h2 id='shared-transcript-heading'>Session Transcript</h2>
@foreach($transcriptRows as $row)
<article><strong>{{ $row['segment']->speaker_label }}</strong>@if($row['confirmed_user_id'])<span> / self-confirmed</span>@endif
<p>{{ $row['revision']->content }}</p>
<form method='post' action='{{ route('ai-common.shared.sessions.segments.revise', [$conversation, $session, $row['segment']]) }}'>@csrf<input type='hidden' name='operation_id' value='{{ Str::uuid() }}'><textarea name='content' maxlength='4000' required>{{ $row['revision']->content }}</textarea><button>Save Transcript Revision</button></form>
<form method='post' action='{{ route('ai-common.shared.sessions.segments.identity', [$conversation, $session, $row['segment']]) }}'>@csrf<input type='hidden' name='operation_id' value='{{ Str::uuid() }}'><button>Confirm this speech as me</button></form>
</article>
@endforeach
</section>
@endif
