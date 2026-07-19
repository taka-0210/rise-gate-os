@extends('layouts.app', ['title' => '全体スケジュール - Rise Gate OS'])

@section('content')
    <style>
        .portfolio-page { gap:18px; }
        .portfolio-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; }
        .portfolio-summary { display:flex; flex-wrap:wrap; gap:8px; }
        .portfolio-summary span { padding:7px 10px; border:1px solid var(--line); border-radius:999px; background:#fff; font-size:13px; }
        .portfolio-legend { display:flex; flex-wrap:wrap; gap:14px; color:var(--muted); font-size:12px; }
        .portfolio-legend span::before { content:''; display:inline-block; width:11px; height:11px; margin-right:5px; border-radius:3px; vertical-align:-1px; background:#39759a; }
        .portfolio-legend .is-overlap::before { background:#d78632; }
        .portfolio-scroll { overflow:auto; border:2px solid #52616a; border-radius:10px; background:#fff; }
        .portfolio-canvas { min-width:calc(260px + var(--timeline-width)); }
        .portfolio-row { display:grid; grid-template-columns:260px var(--timeline-width); min-height:72px; border-bottom:1px solid #dce3e7; }
        .portfolio-row:last-child { border-bottom:0; }
        .portfolio-label { position:sticky; left:0; z-index:3; display:grid; align-content:center; gap:4px; padding:10px 12px; border-right:1px solid #cfd9de; background:#fff; }
        .portfolio-label strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .portfolio-label .meta { font-size:11px; }
        .portfolio-track { position:relative; min-height:72px; background:repeating-linear-gradient(to right,#fff 0,#fff calc(7 * var(--day-width) - 1px),#e8edef calc(7 * var(--day-width) - 1px),#e8edef calc(7 * var(--day-width))); }
        .portfolio-months { height:42px; background:#f3f7f8; }
        .portfolio-month { position:absolute; top:0; bottom:0; display:flex; align-items:center; justify-content:center; border-right:1px solid #c5d0d5; color:#43545c; font-size:12px; font-weight:800; }
        .portfolio-tick { position:absolute; top:43px; bottom:0; border-left:1px solid #edf1f3; }
        .portfolio-tick span { position:absolute; top:4px; left:3px; color:#8a979d; font-size:10px; white-space:nowrap; }
        .portfolio-today { position:absolute; z-index:2; top:0; bottom:0; width:2px; background:#c84d3c; pointer-events:none; }
        .portfolio-bar { position:absolute; z-index:1; top:29px; height:32px; display:flex; align-items:center; gap:7px; min-width:18px; padding:0 9px; border-radius:7px; background:#39759a; color:#fff; font-size:12px; font-weight:800; text-decoration:none; box-shadow:0 3px 8px rgba(30,70,90,.18); overflow:hidden; white-space:nowrap; }
        .portfolio-bar.is-overlap { background:#d78632; }
        .portfolio-bar.is-complete { opacity:.55; }
        .portfolio-bar small { font-size:10px; font-weight:600; }
        .portfolio-issues { color:#a33f2d; font-weight:800; }
        .portfolio-unscheduled { padding:16px; border:2px solid #d5a22f; border-radius:10px; background:#fffaf0; }
        .portfolio-unscheduled-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
        .portfolio-unscheduled a { padding:10px; border:1px solid #dec477; border-radius:7px; background:#fff; text-decoration:none; }
        @media(max-width:700px) { .portfolio-head{display:grid}.portfolio-unscheduled-list{grid-template-columns:1fr}.portfolio-row{grid-template-columns:210px var(--timeline-width)}.portfolio-canvas{min-width:calc(210px + var(--timeline-width))} }
    </style>

    @php
        $dayWidth = $axisDays > 180 ? 7 : 12;
        $today = now()->startOfDay();
        $todayVisible = $today->betweenIncluded($axisStart, $axisEnd);
        $todayLeft = $todayVisible ? $axisStart->diffInDays($today) / $axisDays * 100 : null;
        $statusLabels = \App\Models\Project::statuses();
    @endphp
    <section class="stack portfolio-page">
        <div class="portfolio-head">
            <div>
                <div class="meta">{{ $currentWorkspace->name }}・Project横断</div>
                <h1>全体スケジュール</h1>
                <p>Projectの重なりと現在の仕事量を、Workspace全体で確認します。</p>
            </div>
            <div class="actions"><a class="button secondary" href="{{ route('projects.index') }}">Project一覧へ戻る</a></div>
        </div>
        <div class="portfolio-summary">
            <span>表示中 {{ $scheduled->count() }}件</span>
            <span>日程未設定 {{ $unscheduled->count() }}件</span>
            <span>期間 {{ $axisStart->format('Y/m/d') }}〜{{ $axisEnd->format('Y/m/d') }}</span>
        </div>
        <div class="portfolio-legend"><span>単独期間</span><span class="is-overlap">他Projectと期間が重複</span><span>赤線：今日</span></div>

        <div class="portfolio-scroll">
            <div class="portfolio-canvas" style="--timeline-width:{{ $timelineWidth }}px;--day-width:{{ $dayWidth }}px;">
                <div class="portfolio-row" style="min-height:72px;">
                    <div class="portfolio-label"><strong>Project</strong><span class="meta">クリックで時間表示へ</span></div>
                    <div class="portfolio-track portfolio-months">
                        @foreach($months as $month)<div class="portfolio-month" style="left:{{ $month['left'] }}%;width:{{ $month['width'] }}%;">{{ $month['label'] }}</div>@endforeach
                        @foreach($ticks as $tick)<div class="portfolio-tick" style="left:{{ $tick['left'] }}%;"><span>{{ $tick['label'] }}</span></div>@endforeach
                        @if($todayVisible)<div class="portfolio-today" style="left:{{ $todayLeft }}%;"></div>@endif
                    </div>
                </div>
                @forelse($scheduled as $project)
                    @php
                        $left = $axisStart->diffInDays($project->start_date) / $axisDays * 100;
                        $width = ($project->start_date->diffInDays($project->due_date) + 1) / $axisDays * 100;
                        $overlapCount = $overlapCounts[$project->id] ?? 0;
                        $projectIntegrity = $integrity[$project->id];
                    @endphp
                    <div class="portfolio-row">
                        <div class="portfolio-label">
                            <strong>{{ $project->name }}</strong>
                            <span class="meta">{{ $project->client?->name ?? 'クライアント未設定' }} / {{ $statusLabels[$project->status] ?? $project->status }}</span>
                            <span class="meta">未完了Task {{ $project->open_tasks_count }}件 @if($projectIntegrity['issue_count'])・<span class="portfolio-issues">日程確認 {{ $projectIntegrity['issue_count'] }}件</span>@endif</span>
                        </div>
                        <div class="portfolio-track">
                            @foreach($ticks as $tick)<div class="portfolio-tick" style="left:{{ $tick['left'] }}%;"></div>@endforeach
                            @if($todayVisible)<div class="portfolio-today" style="left:{{ $todayLeft }}%;"></div>@endif
                            <a class="portfolio-bar {{ $overlapCount ? 'is-overlap' : '' }} {{ $project->completed_at ? 'is-complete' : '' }}" style="left:{{ $left }}%;width:{{ max($width, .6) }}%;" href="{{ route('projects.show', ['project' => $project, 'view' => 'time']) }}" title="{{ $project->name }}：{{ $project->start_date->format('Y/m/d') }}〜{{ $project->due_date->format('Y/m/d') }}">
                                <span>{{ $project->start_date->format('n/j') }}〜{{ $project->due_date->format('n/j') }}</span>
                                @if($overlapCount)<small>{{ $overlapCount }}件と重複</small>@endif
                            </a>
                        </div>
                    </div>
                @empty
                    <div class="portfolio-row"><div class="portfolio-label">日程設定済みProjectなし</div><div class="portfolio-track"></div></div>
                @endforelse
            </div>
        </div>

        @if($unscheduled->isNotEmpty())
            <section class="portfolio-unscheduled stack">
                <div><h2>日程未設定のProject</h2><p>開始予定日と期限を設定すると、上の全体スケジュールへ表示されます。</p></div>
                <div class="portfolio-unscheduled-list">
                    @foreach($unscheduled as $project)<a href="{{ route('projects.show', ['project' => $project, 'view' => 'time']) }}"><strong>{{ $project->name }}</strong><div class="meta">{{ $project->client?->name ?? 'クライアント未設定' }} / 未完了Task {{ $project->open_tasks_count }}件</div></a>@endforeach
                </div>
            </section>
        @endif
    </section>
@endsection
