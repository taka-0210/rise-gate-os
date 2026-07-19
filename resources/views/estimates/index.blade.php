@extends('layouts.app', ['title' => '見積書一覧'])
@section('content')
<section class="stack">
    <div class="page-head"><div><div class="meta">帳票管理</div><h1>見積書一覧</h1><p>Workspace内のProjectで作成した見積書を横断して確認します。</p></div><a class="button secondary" href="{{ route('documents.index') }}">帳票管理へ</a></div>
    <div class="card table-wrap"><table><thead><tr><th>見積番号</th><th>発行日</th><th>クライアント / Project</th><th>件名</th><th>状態</th><th>合計</th></tr></thead><tbody>
        @forelse($estimates as $estimate)<tr><td><a href="{{ route('estimates.show',$estimate) }}">{{ $estimate->estimate_number }}</a></td><td>{{ $estimate->issued_on->format('Y/m/d') }}</td><td>{{ $estimate->client->name }}<div class="meta">{{ $estimate->project->name }}</div></td><td>{{ $estimate->title }}</td><td><span class="badge">{{ $statuses[$estimate->status]??$estimate->status }}</span></td><td>￥{{ number_format($estimate->total) }}</td></tr>
        @empty<tr><td colspan="6">見積書はまだありません。Projectの「帳票」から作成できます。</td></tr>@endforelse
    </tbody></table></div>{{ $estimates->links() }}
</section>
<style>.page-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}</style>
@endsection
