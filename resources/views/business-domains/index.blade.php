@extends('layouts.app', ['title' => '事業領域 - '.$organization->name])

@section('content')
<section class="stack business-domain-screen">
    @if (session('status')) <div class="notice">{{ session('status') }}</div> @endif
    <div class="page-header actions" style="justify-content:space-between;align-items:flex-end;">
        <div>
            <div class="meta">COMPANY OS / BUSINESS DOMAIN</div>
            <h1>事業領域</h1>
            <p>会社が「何を・誰に・どんな価値として・どこで・どの位置づけで」届けるかを共有します。登録は任意です。</p>
        </div>
        <div class="actions">
            @if ($isOwner)<a class="button secondary" href="{{ route('business-domains.editors') }}">編集担当</a>@endif
            @if ($canEdit)<a class="button" href="{{ route('business-domains.create') }}">事業領域を追加</a>@endif
        </div>
    </div>

    <form method="GET" class="panel domain-filter">
        <div class="field"><label for="domain-q">検索</label><input id="domain-q" name="q" value="{{ $search }}" maxlength="100" placeholder="名称・説明・明細・属性"></div>
        <div class="field"><label for="domain-status">表示</label><select id="domain-status" name="status"><option value="active" @selected($status === 'active')>利用中</option><option value="archived" @selected($status === 'archived')>保管済み</option><option value="all" @selected($status === 'all')>すべて</option></select></div>
        <button type="submit">絞り込む</button>
    </form>

    <div class="stack">
        @forelse ($domains as $domain)
            <a class="card domain-card" href="{{ route('business-domains.show', $domain) }}">
                <div class="actions" style="justify-content:space-between;">
                    <div><span class="badge">{{ $domain->status === 'active' ? '利用中' : '保管済み' }}</span> <span class="meta">Revision {{ $domain->version }}</span></div>
                    <span class="meta">明細 {{ $domain->items_count }}件</span>
                </div>
                <h2>{{ $domain->name }}</h2>
                @if ($domain->description)<p>{{ \Illuminate\Support\Str::limit($domain->description, 180) }}</p>@endif
            </a>
        @empty
            <div class="panel stack">
                <h2>{{ $status === 'active' ? '事業領域はまだ登録されていません' : '対象の事業領域はありません' }}</h2>
                <p>名称だけでも開始できます。5つの観点や明細は、必要になった時点で追加できます。</p>
                @if ($canEdit && $status === 'active')<div><a class="button" href="{{ route('business-domains.create') }}">最初の事業領域を追加</a></div>@endif
            </div>
        @endforelse
    </div>
    {{ $domains->links() }}
</section>
<style>
.domain-filter{display:grid;grid-template-columns:minmax(0,2fr) minmax(150px,1fr) auto;align-items:end;gap:14px}.domain-card{display:grid;gap:10px}.domain-card p{margin:0}.business-domain-screen{min-width:0}@media(max-width:620px){.domain-filter{grid-template-columns:1fr}.page-header.actions{align-items:stretch!important}.page-header.actions>.actions{width:100%}.page-header.actions>.actions>*{flex:1}}
</style>
@endsection
