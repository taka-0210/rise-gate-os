@extends('layouts.app', ['title' => $domain->name.'の管理 - '.$organization->name])

@section('content')
<section class="stack">
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    <div class="page-header actions" style="justify-content:space-between;align-items:flex-end">
        <div><div class="meta">BUSINESS DOMAIN / MANAGE / REVISION {{ $domain->version }}</div><h1>{{ $domain->name }}</h1><p>現在値の編集、保管状態、変更履歴を管理します。</p></div>
        <div class="actions">
            <a class="button secondary" href="{{ route('business-domains.show', $domain) }}">読む画面へ</a>
            <a class="button secondary" href="{{ route('business-domains.manage', ['status' => $domain->status]) }}">管理一覧</a>
            @if($domain->status === 'active')<a class="button" href="{{ route('business-domains.edit', $domain) }}">内容を編集</a>@endif
        </div>
    </div>

    <div class="panel stack">
        <div><div class="meta">STATE</div><h2>{{ $domain->status === 'active' ? '保管する' : '再開する' }}</h2><p>理由とともに新しいRevisionとして記録します。物理削除は行いません。</p></div>
        <form method="POST" action="{{ $domain->status === 'active' ? route('business-domains.archive', $domain) : route('business-domains.reopen', $domain) }}" class="stack">
            @csrf
            <input type="hidden" name="request_id" value="{{ $domain->status === 'active' ? $archiveRequestId : $reopenRequestId }}">
            <input type="hidden" name="expected_version" value="{{ $domain->version }}">
            <div class="field"><label for="state-reason">理由</label><textarea id="state-reason" name="change_reason" rows="3" maxlength="2000" required></textarea></div>
            <div><button class="{{ $domain->status === 'active' ? 'danger' : '' }}" type="submit">{{ $domain->status === 'active' ? '保管する' : '利用中へ戻す' }}</button></div>
        </form>
    </div>

    <div class="panel stack">
        <div><div class="meta">HISTORY / EDITORS ONLY</div><h2>Revision履歴</h2></div>
        @foreach($revisions as $revision)
            <a class="card domain-revision" href="{{ route('business-domains.revisions.show', [$domain, $revision->revision_no]) }}"><strong>Revision {{ $revision->revision_no }}</strong><span>{{ $revision->changed_at?->timezone('Asia/Tokyo')->format('Y-m-d H:i:s') }} JST</span><span>{{ $revision->actor?->name ?? 'Deleted user' }}</span><span>{{ $revision->change_reason ?: '作成' }}</span></a>
        @endforeach
        {{ $revisions->links() }}
    </div>
</section>
<style>.domain-revision{display:grid;grid-template-columns:130px 180px 150px minmax(0,1fr);gap:10px}.domain-revision span{overflow-wrap:anywhere}@media(max-width:700px){.domain-revision{grid-template-columns:1fr}.page-header.actions{align-items:stretch!important}}</style>
@endsection
