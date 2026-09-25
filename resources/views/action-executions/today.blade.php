@extends('layouts.app', ['title' => '今日のAction - Company OS'])

@section('content')
<section class="today-shell">
    @if(session('status')) <div class="today-notice">{{ session('status') }}</div> @endif
    <header class="today-hero">
        <div><p class="today-kicker">TODAY / CONTINUOUS EXECUTION</p><h1>今日、動かすこと。</h1><p>{{ $company->name }}のActionを、実施・確認・振り返りまで一つの流れで扱います。</p></div>
        <form method="POST" action="{{ route('action-executions.refresh') }}">@csrf<button class="secondary" type="submit">予定を更新</button></form>
    </header>
    <div class="today-metric"><strong>{{ $rate === null ? '—' : $rate.'%' }}</strong><span>今月の実施率（completed ÷ completed + missed）</span><small>正規 {{ $regularCount }}回 / 再実施 {{ $retryCount }}回</small></div>

    @php($groups = [['今、実施できる', $todayExecutions], ['今日期限の単発Action', $todaySingles], ['期限を過ぎた単発Action', $overdueSingles], ['あなたの確認待ち', $reviews]])
    @foreach($groups as [$heading, $items])
        <section class="today-section"><div class="today-section-head"><h2>{{ $heading }}</h2><span>{{ $items->count() }}</span></div>
        <div class="today-grid">
            @forelse($items as $item)
                @if($item instanceof \App\Models\ActionExecution)
                    <article class="today-card"><p class="today-kicker">{{ strtoupper($item->origin) }} / {{ $item->scheduled_date->format('Y.m.d') }}</p><h3>{{ $item->task->title }}</h3><p>{{ $item->task->project->name }}</p>
                        <div class="today-actions"><a href="{{ route('action-executions.show', [$item->task->project, $item->task]) }}">詳細</a>
                        @if($item->status === 'planned')
                            <form method="POST" action="{{ route('action-executions.complete', $item) }}">@csrf<input type="hidden" name="operation_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button type="submit">実施した</button></form>
                        @else
                            <form method="POST" action="{{ route('action-executions.retry', $item) }}">@csrf<input type="hidden" name="operation_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button type="submit">今日、再実施</button></form>
                        @endif</div>
                    </article>
                @else
                    <article class="today-card"><p class="today-kicker">{{ $item->status === 'review_pending' ? 'REVIEW' : 'ONE TIME' }}</p><h3>{{ $item->title }}</h3><p>{{ $item->project->name }} @if($item->due_date) / {{ $item->due_date->format('Y.m.d') }} @endif</p><a href="{{ route('project-execution.show', $item->project) }}">Projectで確認</a></article>
                @endif
            @empty <p class="today-empty">該当するActionはありません。</p> @endforelse
        </div></section>
    @endforeach

    <section class="today-section"><div class="today-section-head"><h2>今月の実績</h2><span>{{ $history->count() }}</span></div><div class="calendar-list">
        @forelse($history as $execution)<div><time>{{ $execution->scheduled_date->format('m/d') }}</time><span>{{ $execution->task->title }}</span><b data-status="{{ $execution->status }}">{{ $execution->status }}</b></div>@empty<p class="today-empty">実績はまだありません。</p>@endforelse
    </div></section>
</section>
<style>
.today-shell{max-width:1180px;margin:auto;padding:clamp(24px,5vw,72px) 20px;color:#13232d}.today-hero{display:flex;justify-content:space-between;gap:32px;align-items:end;margin-bottom:42px}.today-hero h1{font-size:clamp(40px,6vw,76px);font-weight:500;letter-spacing:-.05em;margin:.2em 0}.today-kicker{font-size:11px;letter-spacing:.18em;color:#056071;font-weight:700}.today-notice,.today-metric{border:1px solid #c8dce0;border-radius:18px;background:#f7fbfb;padding:18px;margin-bottom:24px}.today-metric{display:grid;grid-template-columns:auto 1fr auto;gap:18px;align-items:center}.today-metric strong{font-size:36px}.today-metric small{color:#61737c}.today-section{margin:42px 0}.today-section-head{display:flex;align-items:center;gap:12px;border-bottom:1px solid #ccdadd;padding-bottom:10px}.today-section-head h2{font-weight:500}.today-section-head span{border-radius:99px;background:#e4f1f2;padding:4px 9px}.today-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px}.today-card{border:1px solid #d3dfe2;border-radius:18px;padding:22px;background:white}.today-card h3{font-size:20px}.today-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}.today-actions form{margin:0}.today-empty{color:#71818a}.calendar-list>div{display:grid;grid-template-columns:60px 1fr auto;gap:12px;padding:12px 0;border-bottom:1px solid #e2e9eb}.calendar-list b{font-size:11px;text-transform:uppercase;color:#087263}.secondary{background:white;color:#064f60;border:1px solid #aac8cf}@media(max-width:600px){.today-hero{align-items:start;flex-direction:column}.today-grid{grid-template-columns:1fr}.today-metric{grid-template-columns:1fr}.today-shell{padding-inline:14px}.today-card{padding:18px;overflow-wrap:anywhere}}
</style>
@endsection
