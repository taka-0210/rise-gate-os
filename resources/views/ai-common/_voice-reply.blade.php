@if($message->role === \App\Models\AiCommonMessage::ROLE_ASSISTANT && $message->visibility_status === 'visible')
<div data-co-voice-reply data-access-url="{{ route('ai-common.voice-reply.access', [$conversation, $message->public_id]) }}" data-content-hash="{{ hash('sha256', $message->content) }}">
    <button type="button" data-voice-start>読み上げ開始</button>
    <button type="button" data-voice-stop disabled>途中停止</button>
    <button type="button" data-voice-replay>もう一度再生</button>
    <span role="status" aria-live="polite" data-voice-status>端末内の日本語音声を確認します。</span>
    <small style="display:block">自動再生・音声保存・外部TTS送信はしません。権限失効の検知には短い遅れがあり、再生済み音声は取り消せません。</small>
</div>
@once
@push('head')<script defer src="{{ asset('js/co-voice-reply.js') }}"></script>@endpush
@endonce
@endif
