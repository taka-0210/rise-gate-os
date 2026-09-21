@extends('layouts.app', ['title' => $domain->name.' - 事業領域'])

@section('content')
@php
    $axes = [
        'what_summary' => 'WHAT / 何を', 'who_summary' => 'WHO / 誰に',
        'value_proposition' => 'VALUE / どんな価値', 'geographic_scope_summary' => 'WHERE / どこで',
        'market_position_summary' => 'POSITION / 位置づけ',
    ];
@endphp
<section class="stack business-domain-screen">
    @if (session('status')) <div class="notice">{{ session('status') }}</div> @endif
    <div class="page-header actions" style="justify-content:space-between;align-items:flex-end;">
        <div><div class="meta">BUSINESS DOMAIN / {{ strtoupper($domain->status) }} / REVISION {{ $domain->version }}</div><h1>{{ $domain->name }}</h1><p>{{ $domain->description ?: '概要はまだ登録されていません。' }}</p></div>
        <div class="actions"><a class="button secondary" href="{{ route('business-domains.index', ['status' => $domain->status]) }}">一覧</a>@if($canEdit && $domain->status === 'active')<a class="button" href="{{ route('business-domains.edit', $domain) }}">編集</a>@endif</div>
    </div>

    @if ($domain->status === 'archived')<div class="notice">この事業領域は保管済みです。現在値と履歴は維持されています。</div>@endif

    <div class="domain-axis-grid">
        @foreach($axes as $field => $label)
            <article class="card"><div class="meta">{{ $label }}</div><p>{{ $domain->{$field} ?: '未登録' }}</p></article>
        @endforeach
    </div>
    <div class="panel stack"><div class="meta">SELF-RECOGNIZED STRENGTHS</div><h2>自社認識の強み</h2><p>{{ $domain->self_recognized_strengths ?: '未登録' }}</p></div>
    <div class="panel stack domain-direction-summary">
        <div><div class="meta">DIRECTION</div><h2>今後の方向性</h2></div>
        <div><span class="badge">{{ \App\Models\BusinessDomain::directionLabel($domain->direction) }}</span></div>
        @if ($domain->direction_memo)<p>{{ $domain->direction_memo }}</p>@else<p class="meta">方向性メモは未登録です。</p>@endif
    </div>

    <div class="panel stack">
        <div><div class="meta">ITEMS</div><h2>明細</h2></div>
        @forelse($domain->items as $item)
            <article class="card stack"><div><span class="badge">{{ $item->kind }}</span><h3>{{ $item->name }}</h3>@if($item->description)<p>{{ $item->description }}</p>@endif</div>
                @if($item->attributes->isNotEmpty())<dl class="domain-attributes">@foreach($item->attributes as $attribute)<div><dt>{{ strtoupper($attribute->axis) }} / {{ $attribute->label }}</dt><dd>{{ $attribute->value_text }}</dd></div>@endforeach</dl>@endif
            </article>
        @empty<p class="meta">明細はまだありません。</p>@endforelse
    </div>

    @if($canEdit)
        <div class="panel stack">
            <div><div class="meta">STATE</div><h2>{{ $domain->status === 'active' ? '保管する' : '再開する' }}</h2><p>理由とともに新しいRevisionとして記録します。物理削除は行いません。</p></div>
            <form method="POST" action="{{ $domain->status === 'active' ? route('business-domains.archive', $domain) : route('business-domains.reopen', $domain) }}" class="stack">
                @csrf
                <input type="hidden" name="request_id" value="{{ $domain->status === 'active' ? $archiveRequestId : $reopenRequestId }}"><input type="hidden" name="expected_version" value="{{ $domain->version }}">
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
    @endif
</section>
<style>
.business-domain-screen{min-width:0}.domain-axis-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.domain-axis-grid p,.domain-direction-summary p{white-space:pre-wrap;overflow-wrap:anywhere}.domain-direction-summary p{margin:0}.domain-attributes{display:grid;gap:10px;margin:0}.domain-attributes div{border-top:1px solid var(--line);padding-top:10px}.domain-attributes dt{font-weight:800}.domain-attributes dd{margin:5px 0 0;color:var(--muted);white-space:pre-wrap;overflow-wrap:anywhere}.domain-revision{display:grid;grid-template-columns:130px 180px 150px minmax(0,1fr);gap:10px}.domain-revision span{overflow-wrap:anywhere}@media(max-width:700px){.domain-axis-grid{grid-template-columns:1fr}.domain-revision{grid-template-columns:1fr}.page-header.actions{align-items:stretch!important}}
</style>
@endsection
