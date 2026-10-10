@extends('layouts.app')

@section('title', $conversation->title.' - COに相談')

@section('content')
<style>
.co-wrap{max-width:900px;margin:auto}.co-panel{background:#fff;border:1px solid #d7e1e5;border-radius:14px;padding:18px;margin:14px 0}.co-message{border-left:4px solid #24576a}.co-message--user{border-color:#8aa1ab}.co-source{display:inline-block;padding:4px 8px;margin:3px;border-radius:999px;background:#edf6f6;font-size:12px}.co-actions{display:flex;gap:8px;flex-wrap:wrap}.co-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.co-form-grid label{display:grid;gap:4px}.co-form-grid .wide{grid-column:1/-1}.co-private{color:#6b7280}.co-revoked{background:#fff7ed;border-color:#c2410c}@media(max-width:390px){.co-form-grid{grid-template-columns:1fr}.co-form-grid .wide{grid-column:auto}.co-panel{padding:14px}.co-actions>*{width:100%}}
</style>
<div class="co-wrap">
<p><a href="{{ route('ai-common.index') }}">← Conversation一覧</a></p>
<h1>{{ $conversation->title }}</h1><p class="co-private">本人用Private work history。AI回答・未適用Proposal・Apply済みの事実を区別して表示します。</p>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif
@if(session('error'))<div class="co-panel co-revoked">{{ session('error') }}</div>@endif
@if($conversation->status === 'active')
<form method="post" action="{{ route('ai-common.archive', $conversation) }}">@csrf<button type="submit">ConversationをArchive</button></form>
@else
<div class="co-panel"><strong>Archive済み</strong><p>履歴は本人だけが閲覧できます。新しい相談・提案・Apply・Undoはできません。</p></div>
@endif
@foreach($messageRows as $row)
    <article class="co-panel co-message {{ $row['message']->role === 'user' ? 'co-message--user' : '' }} {{ !$row['visible'] ? 'co-revoked' : '' }}">
        <strong>{{ $row['message']->role === 'user' ? 'あなた' : 'CO（AI回答）' }}</strong>
        @if($row['visible'])<p style="white-space:pre-wrap">{{ $row['message']->content }}</p>
            @include('ai-common._management-citations')
            @foreach($row['sources'] as $source)<span class="co-source">{{ $source['type'] }} / {{ $source['handle'] }}</span>@endforeach
        @else<p>参照元の現在権限・Policy・版を確認できないため、この回答本文と次turnへの再投入を停止しました。</p>@endif
    </article>
@endforeach
@include('ai-common._input')
<section class="co-panel"><h2>Contextを明示選択</h2><p>全件探索はしません。Ownerでも他人のCaptureは選択できません。</p>
@if($conversation->status === 'active')
@include('ai-common._management-context')
<form class="co-form-grid" method="post" action="{{ route('ai-common.sources.store',$conversation) }}">@csrf
<label>Type<select name="resource_type"><option value="project">Project</option><option value="action">Action</option><option value="business_domain">Business Domain</option><option value="capture">Capture</option><option value="attachment_extract">Attachment Extract</option><option value="attachment_transcript">Attachment Transcript</option></select></label>
<label>Public ID<input name="resource_public_id" required></label><label class="wide">選択理由<input name="selection_reason" maxlength="160" required></label><button type="submit">Contextへ追加</button></form>@endif
@foreach($conversation->sources as $source)<span class="co-source">{{ $source->resource_type }} / {{ $source->opaque_handle }}</span>@endforeach</section>
@if($conversation->status === 'active')
<form class="co-panel" method="post" action="{{ route('ai-common.messages.store',$conversation) }}">@csrf
<h2>COへ相談</h2><textarea name="content" maxlength="4000" rows="5" required>{{ old('content') }}</textarea>
@foreach($conversation->sources as $source)<label style="display:block"><input type="checkbox" name="source_ids[]" value="{{ $source->id }}"> {{ $source->resource_type }} / {{ $source->selection_reason }}</label>@endforeach
<button type="submit">許可済みContextだけで相談する</button></form>
<section class="co-panel"><h2>保存Proposalを作る</h2><p>AI回答だけでは保存されません。1 Proposal = 1 Unit = 1 operationです。</p>
<form class="co-form-grid" method="post" action="{{ route('ai-common.proposals.store',$conversation) }}">@csrf
@if($proposalSourceMessage)<input type="hidden" name="source_message_public_id" value="{{ $proposalSourceMessage->public_id }}">@endif
<label>操作<select name="operation"><option value="capture.create">Capture create / L1</option><option value="action.create">Action create / L2</option><option value="action.update">Action update / L2</option><option value="project.update">Project update / L2</option><option value="business_domain.update">Domain update / L3</option></select></label>
<label>Target public ID<input name="target_public_id" placeholder="Capture createでは空欄"></label><label>題名<input name="title" required></label><label>操作ID<input name="idempotency_key" value="{{ Str::uuid() }}" required></label>
<label class="wide">公開する要約<input name="published_summary" maxlength="1000"></label>
<label>Capture type<input name="attributes[type]"></label><label>本文<input name="attributes[body]"></label><label>Recipient user ID<input name="attributes[recipient_user_id]" inputmode="numeric"></label><label>通知 timing<input name="attributes[notification_timing]"></label><label>JST日時<input type="datetime-local" name="attributes[notify_at]"></label>
<input type="hidden" name="attributes[recipient_confirmed]" value="1"><input type="hidden" name="attributes[jst_time_confirmed]" value="1">
<label>title<input name="attributes[title]"></label><label>done condition<input name="attributes[done_condition]"></label><label>assigned user ID<input name="attributes[assigned_to]" inputmode="numeric"></label><label>reviewer user ID<input name="attributes[reviewer_user_id]" inputmode="numeric"></label><label>description / Domain description<input name="attributes[description]"></label><label>due date<input type="date" name="attributes[due_date]"></label>
<label>Project purpose<input name="attributes[purpose]"></label><label>Project expected outcome<input name="attributes[expected_outcome]"></label><label>Domain what<input name="attributes[what_summary]"></label><label>Domain who<input name="attributes[who_summary]"></label><label>Domain value<input name="attributes[value_proposition]"></label><label>Domain geographic scope<input name="attributes[geographic_scope_summary]"></label><label>Domain market position<input name="attributes[market_position_summary]"></label><label>Domain strengths<input name="attributes[self_recognized_strengths]"></label><label class="wide">変更理由<input name="attributes[reason]"></label><input type="hidden" name="attributes[context_impact_confirmed]" value="1">
<button type="submit">Proposalを作成（まだ適用しない）</button></form></section>
@endif
@foreach($conversation->proposals as $proposal)<article class="co-panel"><strong>{{ $proposal->title }}</strong><p>Level {{ $proposal->risk_level }} / {{ $proposal->status }} / {{ $proposal->items->first()?->entity_type }}</p><div class="co-actions">
@if($conversation->status==='active' and $proposal->status==='pending')<form method="post" action="{{ route('ai-common.proposals.approve',[$conversation,$proposal]) }}">@csrf<button>内容を確認して承認</button></form>@endif
@if($conversation->status==='active' and $proposal->status==='approved')<form method="post" action="{{ route('ai-common.proposals.apply',[$conversation,$proposal]) }}">@csrf<button>Writerで適用</button></form>@endif
@if($conversation->status==='active' and $proposal->status==='applied' and $proposal->items->first()?->operation==='update')
@unless($proposal->undos->contains('status','applied'))<form method='post' action='{{ route('ai-common.proposals.undo',[$conversation,$proposal]) }}'>@csrf<button>更新を元に戻す</button></form>@endunless
@endif
</div><p>{{ $proposal->status==='applied' ? 'Apply成功確認済み' : 'Company OS正本には未反映' }}</p></article>@endforeach
</div>
@endsection
