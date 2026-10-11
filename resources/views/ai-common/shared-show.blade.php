@extends('layouts.app')

@section('title', $conversation->title.' - Shared Conversation')

@section('content')
@if($conversation->status === 'active')<p><a href="#co-text-meeting">テキスト会議へ（音声の準備は不要です）</a></p>@endif
@include('ai-common._shared-session')
<style>
.shared-wrap{max-width:900px;margin:auto}.shared-card{background:#fff;border:1px solid #d7e1e5;border-radius:14px;padding:18px;margin:14px 0}.shared-row{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}.shared-message{border-left:4px solid #24576a}.shared-muted{color:#64748b}.shared-card input,.shared-card textarea,.shared-card select{width:100%;box-sizing:border-box}.shared-state-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.shared-state-grid div{border:1px solid #d7e1e5;border-radius:10px;padding:8px}.shared-state-grid dt{font-size:.8rem;color:#64748b}.shared-state-grid dd{margin:4px 0 0}.shared-context-text{white-space:pre-wrap;overflow-wrap:anywhere;max-height:20rem;overflow:auto}.shared-waveform{width:100%;height:96px;background:#0f172a;border-radius:10px}.shared-partial{min-height:1.5rem;color:#475569;font-style:italic}.shared-durable-finals{padding-left:1.5rem}.shared-realtime-states dd{overflow-wrap:anywhere}@media(max-width:390px){.shared-card{padding:14px}.shared-row form,.shared-row button{width:100%}.shared-state-grid{grid-template-columns:1fr 1fr}}@media(prefers-reduced-motion:reduce){.shared-presence *{scroll-behavior:auto!important;transition:none!important;animation:none!important}}
</style>
<div class="shared-wrap">
<p><a href="{{ route('ai-common.index') }}">← Conversation一覧</a></p>
<p class="eyebrow">SHARED CONVERSATION</p>
<h1>{{ $conversation->title }}</h1>
<p>{{ $shared->currentPurposeRevision->purpose }}</p>
@if($coState)<p class=shared-muted>One Shared CO: {{ $coState->state }} / {{ $coState->phase }} / sequence {{ $coState->sequence }}</p>@endif
<p class="shared-muted">Purpose Revision {{ $shared->currentPurposeRevision->revision_no }} / Owner {{ $shared->owner->name }} / Participant {{ $shared->participants->where('status','active')->count() }}名</p>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif
@if(session('error'))<div class="co-panel co-revoked" role="alert">{{ session('error') }}</div>@endif
@if($conversation->status === 'archived')<section class="shared-card"><strong>Archive済み</strong><p>現在権限を満たすParticipantだけが履歴を閲覧できます。新規投稿・招待はできません。</p></section>@endif

@foreach($messageRows as $row)
@php($message = $row['message'])
@if($row['visible'])
<article class="shared-card shared-message">
    <strong>{{ $message->sharedAuthor?->author?->name ?? 'Author未記録' }}</strong>
    <p style="white-space:pre-wrap">{{ $message->content }}</p>
    @include('ai-common._voice-reply')
    @include('ai-common._management-citations')
    <small>{{ $message->created_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST</small>
</article>
@endif
@endforeach

@if($conversation->status === 'active')
@include('ai-common._management-context')
<form class="shared-card" method="post" action="{{ route('ai-common.shared.human-messages.store', $conversation) }}">
    @csrf
    <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
    <h2>Human Message</h2>
    <textarea name="content" rows="4" maxlength="4000" required></textarea>
    <button type="submit">投稿する（AI Requestは送信しない）</button>
</form>
@endif

<section class="shared-card">
<h2>Participant</h2>
@foreach($shared->participants as $member)
    <div class="shared-row">
        <span>{{ $member->user->name }} / {{ $member->role }} / {{ $member->status }}</span>
        @if($conversation->status === 'active' && $participant->role === 'owner' && $member->role !== 'owner' && in_array($member->status, ['active','invited']))
            <form method="post" action="{{ route('ai-common.shared.participants.remove', [$conversation, $member]) }}">@csrf @method('delete')<button>除外</button></form>
            @if($member->status === 'active')<form method="post" action="{{ route('ai-common.shared.owner-transfer.request', [$conversation, $member]) }}">@csrf<button>Owner交代を依頼</button></form>@endif
        @endif
    </div>
@endforeach
@if($conversation->status === 'active' && $participant->role === 'owner' && $inviteCandidates->isNotEmpty())
<form method="post" action="{{ route('ai-common.shared.invitations.store', $conversation) }}">
    @csrf
    <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
    <label>同じOrganizationのUser<select name="invitee_user_id">@foreach($inviteCandidates as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name }}</option>@endforeach</select></label>
    <button>招待する</button>
</form>
@endif
@if($conversation->status === 'active' && $shared->pending_owner_user_id === auth()->id())
<form method="post" action="{{ route('ai-common.shared.owner-transfer.accept', $conversation) }}">@csrf<button>Owner責任を承諾</button></form>
@endif
</section>

@if($conversation->status === 'active' && $participant->role === 'owner')
<section class="shared-card">
<h2>Name / Purpose</h2>
<form method="post" action="{{ route('ai-common.shared.update', $conversation) }}">@csrf @method('put')
<input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
<label>Name<input name="name" maxlength="160" value="{{ $conversation->title }}" required></label>
<label>Purpose<textarea name="purpose" maxlength="4000" rows="4" required>{{ $shared->currentPurposeRevision->purpose }}</textarea></label>
<button>Revisionを保存</button></form>
<form method="post" action="{{ route('ai-common.shared.archive', $conversation) }}">@csrf<button>Archive</button></form>
</section>
@elseif($participant->role !== 'owner')
<form class="shared-card" method="post" action="{{ route('ai-common.shared.leave', $conversation) }}">@csrf<button>退出する</button></form>
@endif

@if($shared->purposeRevisions->count() > 1)
<section class="shared-card"><h2>Purpose Revision</h2>
@foreach($shared->purposeRevisions as $revision)<p>Revision {{ $revision->revision_no }} / {{ $revision->created_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST<br>{{ $revision->purpose }}</p>@endforeach
</section>
@endif
</div>
<script defer src='{{ asset('js/ai-common-shared-session.js') }}'></script>
@endsection
