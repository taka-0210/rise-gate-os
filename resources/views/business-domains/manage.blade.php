@extends('layouts.app', ['title' => '事業領域の管理 - '.$organization->name])

@section('content')
@php
    $tabParameters = fn (string $nextStatus): array => array_filter([
        'status' => $nextStatus,
        'q' => $search,
        'per_page' => request('per_page'),
    ], fn ($value) => $value !== null && $value !== '');
@endphp
<section class="stack business-domain-manage">
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    <div class="page-header actions business-domain-manage__header">
        <div>
            <div class="meta">BUSINESS DOMAIN / MANAGE</div>
            <h1>事業領域の管理</h1>
            <p>内容の編集、表示順、保管状態、編集担当を管理します。</p>
        </div>
        <div class="actions">
            <a class="button secondary" href="{{ route('business-domains.index', $tabParameters($status)) }}">読む画面へ</a>
            @if($isOwner)<a class="button secondary" href="{{ route('business-domains.editors') }}">編集担当</a>@endif
            <a class="button" href="{{ route('business-domains.create') }}">事業領域を追加</a>
        </div>
    </div>

    <nav class="domain-status-tabs" aria-label="管理する事業領域の表示">
        <a href="{{ route('business-domains.manage', $tabParameters('active')) }}" class="domain-status-tab {{ $status === 'active' ? 'active' : '' }}" @if($status === 'active') aria-current="page" @endif>利用中 <span>{{ $statusCounts['active'] }}</span></a>
        <a href="{{ route('business-domains.manage', $tabParameters('archived')) }}" class="domain-status-tab {{ $status === 'archived' ? 'active' : '' }}" @if($status === 'archived') aria-current="page" @endif>保管済み <span>{{ $statusCounts['archived'] }}</span></a>
    </nav>

    <div class="domain-list">
        @forelse($domains as $domain)
            @php($position = ($domains->firstItem() ?? 1) + $loop->index)
            <article class="card domain-card">
                <a class="domain-card__content" href="{{ route('business-domains.manage.show', $domain) }}">
                    <div class="actions domain-card__meta">
                        <span class="badge">{{ $domain->status === 'active' ? '利用中' : '保管済み' }}</span>
                        @if($domain->direction)<span class="meta">方向性：{{ \App\Models\BusinessDomain::directionLabel($domain->direction) }}</span>@endif
                        @if($domain->items_count > 0)<span class="meta">明細 {{ $domain->items_count }}件</span>@endif
                    </div>
                    <h2>{{ $domain->name }}</h2>
                    @if($domain->description)<p>{{ \Illuminate\Support\Str::limit($domain->description, 180) }}</p>@endif
                </a>
                @if($status !== 'all' && $domains->total() > 1)
                    <div class="domain-order-actions" aria-label="{{ $domain->name }}の表示順">
                        @if($position > 1)
                            <form method="POST" action="{{ route('business-domains.move', $domain) }}">@csrf<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="direction" value="up"><button class="secondary" type="submit" aria-label="{{ $domain->name }}を上へ">↑ 上へ</button></form>
                        @else
                            <button class="secondary" type="button" disabled>↑ 上へ</button>
                        @endif
                        @if($position < $domains->total())
                            <form method="POST" action="{{ route('business-domains.move', $domain) }}">@csrf<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="direction" value="down"><button class="secondary" type="submit" aria-label="{{ $domain->name }}を下へ">↓ 下へ</button></form>
                        @else
                            <button class="secondary" type="button" disabled>↓ 下へ</button>
                        @endif
                    </div>
                @endif
            </article>
        @empty
            <div class="panel stack"><h2>対象の事業領域はありません</h2><p>新しく追加するか、表示状態を切り替えてください。</p></div>
        @endforelse
    </div>
    {{ $domains->links() }}
</section>
<style>
.business-domain-manage{min-width:0;gap:14px}.business-domain-manage__header{justify-content:space-between;align-items:flex-end;gap:16px}.business-domain-manage__header p{margin:8px 0 0}.domain-status-tabs{display:flex;align-items:center;gap:8px}.domain-status-tab{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--muted);font-weight:700}.domain-status-tab span{display:inline-grid;min-width:22px;height:22px;place-items:center;padding:0 6px;border-radius:999px;background:#eef2f5;color:var(--ink);font-size:12px}.domain-status-tab.active{border-color:var(--accent);color:var(--accent-dark)}.domain-list{display:grid;gap:10px}.domain-card{display:flex;align-items:stretch;padding:0;overflow:hidden}.domain-card__content{display:grid;flex:1;min-width:0;gap:8px;padding:16px 18px;color:inherit}.domain-card__content p{margin:0}.domain-order-actions{display:flex;flex-direction:column;justify-content:center;gap:7px;padding:12px;border-left:1px solid var(--line);background:#fbfcfd}.domain-order-actions form{margin:0}.domain-order-actions button{width:100%;min-height:36px;padding:7px 10px;white-space:nowrap;font-size:13px}.domain-order-actions button:disabled{opacity:.42;cursor:not-allowed}@media(max-width:620px){.business-domain-manage__header{align-items:stretch}.business-domain-manage__header>.actions{width:100%}.business-domain-manage__header>.actions>*{flex:1}.domain-card{display:block}.domain-order-actions{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid var(--line);border-left:0}.domain-order-actions form{display:flex}.domain-order-actions form button{flex:1}}
</style>
@endsection
