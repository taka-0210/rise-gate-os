@extends('layouts.app')

@section('title', 'COに相談')

@section('content')
<style>
.co-shell{max-width:880px;margin:auto}.co-card{background:#fff;border:1px solid #d7e1e5;border-radius:14px;padding:20px;margin:14px 0}.co-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.co-card input,.co-card button{width:100%;box-sizing:border-box}.co-off{border-left:4px solid #b45309}@media(max-width:390px){.co-grid{grid-template-columns:1fr}.co-card{padding:15px}}
</style>
<div class="co-shell">
    <p class="eyebrow">AI COMMON ENTRY</p>
    <h1>COに相談</h1>
    <p>このOrganizationで本人だけが閲覧できる作業Conversationです。AI回答はCompany OSの保存事実ではありません。</p>
    @if(session('status'))<div class="status">{{ session('status') }}</div>@endif
    @if(!$policy?->allows('common_entry'))
        <section class="co-card co-off"><strong>現在、共通AI入口はOFFです。</strong><p>手動のProject、Action、Today、Captureは通常どおり利用できます。</p></section>
    @else
        <form class="co-card" method="post" action="{{ route('ai-common.store') }}">
            @csrf
            <label>新しい相談の名前<input name="title" maxlength="160" required placeholder="例：今週の優先順位"></label>
            <button type="submit">Conversationを始める</button>
        </form>
    @endif
    <div class="co-grid">
        @forelse($conversations as $conversation)
            <a class="co-card" href="{{ route('ai-common.show', $conversation) }}">
                <strong>{{ $conversation->title }}</strong><br>
                <small>{{ $conversation->last_message_at?->timezone('Asia/Tokyo')->format('Y/m/d H:i') ?? '未開始' }} JST</small>
            </a>
        @empty
            <section class="co-card">Conversationはまだありません。</section>
        @endforelse
    </div>
    @if($archivedConversations->isNotEmpty())
        <h2>Archive</h2><div class="co-grid">
        @foreach($archivedConversations as $conversation)
            <a class="co-card" href="{{ route('ai-common.show', $conversation) }}"><strong>{{ $conversation->title }}</strong><br><small>{{ $conversation->archived_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST</small></a>
        @endforeach
        </div>
    @endif
    @if(($canManageOrganization ?? false))<p><a href="{{ route('ai-common.policy.edit') }}">Organization AI Policyを管理</a></p>@endif
</div>
@endsection
