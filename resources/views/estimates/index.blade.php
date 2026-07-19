@extends('layouts.app', ['title' => '見積書一覧'])
@section('content')
<section class="stack">
    <div class="panel estimate-issuer-link">
        <span>見積書に表示する会社情報・ロゴ・振込先</span>
        <a class="button secondary" href="{{ route('workspace-business-profile.edit') }}">事業者情報を設定</a>
    </div>
    <div class="page-head"><div><div class="meta">帳票管理</div><h1>見積書一覧</h1><p>Workspace内のProjectで作成した見積書を横断して確認します。</p></div><a class="button secondary" href="{{ route('documents.index') }}">帳票管理へ</a></div>
    @if(session('status'))<div class="panel">{{ session('status') }}</div>@endif
    <form class="card filters" method="GET"><select name="status"><option value="">すべての状態</option>@foreach($statuses as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select><label><input type="checkbox" name="history" value="1" @checked(request('history'))>旧版も表示</label><button type="submit">絞り込む</button></form>
    <div class="card table-wrap"><table><thead><tr><th>見積番号</th><th>版</th><th>発行日</th><th>クライアント / Project</th><th>件名</th><th>状態</th><th>合計</th></tr></thead><tbody>
        @forelse($estimates as $estimate)<tr><td><a href="{{ route('estimates.show',$estimate) }}">{{ $estimate->estimate_number }}</a></td><td>第{{ $estimate->revision_no }}版 @if(!$estimate->is_current)<span class="meta">旧版</span>@endif</td><td>{{ $estimate->issued_on->format('Y/m/d') }}</td><td>{{ $estimate->client->name }}<div class="meta">{{ $estimate->project->name }}</div></td><td>{{ $estimate->title }}</td><td><span class="badge">{{ $statuses[$estimate->status]??$estimate->status }}</span></td><td>￥{{ number_format($estimate->total) }}</td></tr>
        @empty<tr><td colspan="7">見積書はまだありません。Projectの「帳票」から作成できます。</td></tr>@endforelse
    </tbody></table></div>{{ $estimates->links() }}
</section>
<style>.page-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.filters{display:flex;align-items:center;gap:10px}.filters select{width:auto}.filters label{display:flex;gap:6px}.filters input{width:auto}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}</style>
<style>
    .estimate-issuer-link { display:flex; align-items:center; justify-content:space-between; gap:16px; }
    @media(max-width:700px) { .estimate-issuer-link { align-items:stretch; flex-direction:column; } }
</style>
@endsection
