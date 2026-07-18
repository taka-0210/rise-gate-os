@extends('layouts.app', ['title' => 'フォーカスレイヤー - '.$project->name])

@section('content')
    <style>
        .focus-page { gap:24px; margin-top:-18px; }
        .focus-toolbar { position:sticky; top:0; z-index:20; width:100vw; margin-left:calc(50% - 50vw); padding:8px 0; border-top:1px solid var(--line); border-bottom:1px solid var(--line); background:rgba(255,255,255,.96); }
        .focus-toolbar-inner { width:min(1040px,calc(100% - 40px)); margin:0 auto; display:flex; justify-content:space-between; align-items:center; gap:16px; }
        .focus-toolbar-context { display:flex; align-items:center; gap:12px; min-width:0; }
        .focus-toolbar-title { color:var(--muted); font-size:13px; white-space:nowrap; }
        .focus-path { display:flex; align-items:center; gap:7px; flex-wrap:wrap; }
        .focus-path [hidden] { display:none !important; }
        .focus-path button,.focus-path a { padding:5px 8px; border:1px solid var(--line); border-radius:6px; background:#fff; color:var(--accent-dark); font-size:13px; text-decoration:none; }
        .focus-path [data-focus-reset="project"] { max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .focus-path button.is-current { background:var(--accent-dark); color:#fff; }
        .focus-manage-link { padding:6px 9px; font-size:13px; white-space:nowrap; }
        .focus-project { padding:22px; border:2px solid #4a5660; border-radius:12px; background:#fff; }
        .focus-project-head { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:18px; align-items:start; margin-bottom:20px; }
        .focus-layer-label { display:flex; align-items:center; gap:8px; margin-bottom:7px; font-size:13px; font-weight:900; letter-spacing:.04em; }
        .focus-layer-label::before { content:''; width:10px; height:10px; border-radius:50%; background:currentColor; }
        .focus-project-label { color:#4a5660; }
        .focus-roadmap-label { color:#245ca6; }
        .focus-improvement-label { color:#23845c; }
        .focus-task-label { color:#b5523d; }
        .focus-summary { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:18px; }
        .focus-summary div { padding:12px; border:1px solid var(--line); border-radius:8px; background:#f8fafb; }
        .focus-summary strong { display:block; color:var(--accent-dark); font-size:24px; }
        .focus-roadmaps { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
        .focus-roadmap { min-width:0; padding:16px; border:2px solid #4f82c4; border-radius:10px; background:#f8fbff; transition:.2s ease; }
        .focus-roadmap-trigger,.focus-improvement-trigger,.focus-task-trigger { display:block; width:100%; padding:0; border:0; border-radius:0; background:transparent; color:inherit; text-align:left; font-weight:inherit; }
        .focus-trigger-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
        .focus-open-hint { flex:0 0 auto; padding:5px 8px; border-radius:999px; background:#fff; color:var(--accent-dark); font-size:12px; font-weight:800; }
        .focus-roadmap-body { display:none; margin-top:14px; }
        .focus-roadmap.is-focused { grid-column:1/-1; padding:20px; box-shadow:0 10px 30px rgba(20,60,90,.10); }
        .focus-roadmap.is-focused .focus-roadmap-body { display:block; }
        .focus-roadmaps.has-focus > .focus-roadmap:not(.is-focused) { display:none; }
        .focus-improvements { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; padding:14px; border:1px dashed #8db49f; border-radius:9px; background:#fff; }
        .focus-improvement { min-width:0; padding:14px; border:2px solid #56a27e; border-radius:9px; background:#f6fcf8; }
        .focus-improvement-body { display:none; margin-top:12px; }
        .focus-improvement.is-focused { grid-column:1/-1; }
        .focus-improvement.is-focused .focus-improvement-body { display:block; }
        .focus-improvements.has-focus > .focus-improvement:not(.is-focused) { display:none; }
        .focus-tasks { display:grid; gap:9px; padding:12px; border:1px dashed #d19a8d; border-radius:8px; background:#fff; }
        .focus-task { padding:12px; border:2px solid #cc735e; border-radius:8px; background:#fff9f7; }
        .focus-task-detail { display:none; margin-top:10px; padding-top:10px; border-top:1px solid #ecd4ce; }
        .focus-task.is-focused .focus-task-detail { display:block; }
        .focus-tasks.has-focus > .focus-task:not(.is-focused) { display:none; }
        .focus-empty { padding:14px; border:1px dashed var(--line); border-radius:8px; color:var(--muted); background:#fff; }
        .focus-unclassified { margin-top:14px; padding:14px; border:1px dashed var(--line); border-radius:9px; background:#fbfcfd; }
        .focus-unclassified-items { display:flex; flex-wrap:wrap; gap:8px; }
        .focus-chip { display:inline-flex; padding:7px 10px; border:1px solid var(--line); border-radius:999px; background:#fff; font-size:13px; }
        .focus-footer { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:16px; }
        .focus-history-item { padding-top:10px; border-top:1px solid var(--line); }
        .focus-history-item:first-child { padding-top:0; border-top:0; }
        .focus-view-switch { display:flex; align-items:center; gap:5px; margin-left:auto; }
        .focus-view-switch a { padding:5px 8px; border:1px solid var(--line); border-radius:6px; background:#fff; color:var(--accent-dark); font-size:12px; text-decoration:none; white-space:nowrap; }
        .focus-view-switch a.is-current { background:var(--accent-dark); color:#fff; }
        .time-layer { display:none; padding:22px; border:2px solid #4a5660; border-radius:12px; background:#fff; }
        .focus-page.time-view .time-layer { display:block; }
        .focus-page.time-view > .focus-project,.focus-page.time-view > .focus-footer { display:none; }
        .time-layer-head { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; margin-bottom:18px; }
        .time-legend { display:flex; flex-wrap:wrap; gap:12px; color:var(--muted); font-size:12px; }
        .time-legend span { display:inline-flex; align-items:center; gap:5px; }
        .time-legend i { width:20px; height:8px; border-radius:999px; background:#66717a; }
        .time-legend .is-inferred { background:transparent; border:2px dashed #66717a; }
        .time-legend .is-overdue { background:#c65a46; }
        .time-legend .is-reached { height:14px; background:#fff; border:2px solid #245ca6; }
        .time-chart-scroll { overflow-x:auto; padding-bottom:8px; }
        .time-chart { min-width:820px; border:1px solid var(--line); border-radius:10px; overflow:hidden; }
        .time-axis,.time-row { display:grid; grid-template-columns:260px minmax(540px,1fr); }
        .time-axis { position:sticky; top:0; z-index:3; background:#f8fafb; border-bottom:1px solid var(--line); }
        .time-axis-label,.time-row-label { padding:10px 12px; border-right:1px solid var(--line); }
        .time-axis-track,.time-row-track { position:relative; min-height:44px; background-image:linear-gradient(to right,rgba(78,98,112,.10) 1px,transparent 1px); background-size:var(--time-grid,10%) 100%; }
        .time-axis-track { min-height:48px; }
        .time-axis-date { position:absolute; top:7px; color:var(--muted); font-size:11px; transform:translateX(-50%); white-space:nowrap; }
        .time-axis-date.is-first { left:4px !important; transform:none; }
        .time-axis-date.is-last { right:4px; left:auto !important; transform:none; }
        .time-row { border-bottom:1px solid var(--line); }
        .time-row:last-child { border-bottom:0; }
        .time-row-label { display:flex; align-items:center; gap:7px; min-width:0; }
        .time-row-label strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .time-row.is-improvement .time-row-label { padding-left:28px; }
        .time-row.is-task .time-row-label { padding-left:46px; }
        .time-row-dot { flex:0 0 auto; width:8px; height:8px; border-radius:50%; background:#66717a; }
        .time-row.is-roadmap .time-row-dot,.time-bar.is-roadmap { background:#4f82c4; }
        .time-row.is-improvement .time-row-dot,.time-bar.is-improvement { background:#56a27e; }
        .time-row.is-task .time-row-dot,.time-bar.is-task { background:#cc735e; }
        .time-bar { position:absolute; top:13px; left:var(--bar-left); width:max(var(--bar-width),6px); height:18px; border-radius:999px; }
        .time-bar.is-inferred { background:rgba(255,255,255,.72); border:2px dashed currentColor; }
        .time-bar.is-roadmap.is-inferred { color:#4f82c4; }
        .time-bar.is-improvement.is-inferred { color:#56a27e; }
        .time-bar.is-overdue { background:#c65a46; }
        .time-reached-marker { position:absolute; z-index:2; top:10px; width:10px; height:24px; border:2px solid #245ca6; border-radius:999px; background:#fff; transform:translateX(-50%); }
        .time-today { position:absolute; z-index:2; top:0; bottom:0; left:var(--today-left); width:2px; background:#d24b3b; pointer-events:none; }
        .time-unscheduled { display:inline-flex; margin:11px 12px; padding:3px 8px; border:1px dashed var(--line); border-radius:999px; color:var(--muted); font-size:11px; }
        @media (max-width:760px) {
            .focus-page { margin-top:-10px; }
            .focus-toolbar { top:0; }
            .focus-toolbar-inner { width:min(100% - 28px,1040px); gap:10px; }
            .focus-toolbar-context { gap:8px; flex-wrap:wrap; }
            .focus-toolbar-inner { flex-wrap:wrap; }
            .focus-view-switch { order:3; width:100%; margin-left:0; }
            .focus-project { padding:14px; }
            .time-layer { padding:14px; }
            .time-layer-head { display:block; }
            .focus-project-head,.focus-footer { grid-template-columns:1fr; }
            .focus-summary { grid-template-columns:1fr 1fr; }
            .focus-roadmaps,.focus-improvements { grid-template-columns:1fr; }
            .focus-roadmap.is-focused,.focus-improvement.is-focused { grid-column:auto; }
            .focus-page.has-roadmap .focus-project-head,.focus-page.has-roadmap .focus-summary,.focus-page.has-roadmap .focus-unclassified { display:none; }
            .focus-page.has-improvement .focus-roadmap > .focus-roadmap-trigger { display:none; }
            .focus-page.has-task .focus-improvement > .focus-improvement-trigger { display:none; }
            .focus-footer { display:none; }
        }
    </style>

    @php
        $completedTasks = $allTasks->where('status', \App\Models\Task::STATUS_DONE);
        $directTasks = $allTasks->whereNull('improvement_id');
        $isTimeView = request('view') === 'time';

        $timeRows = collect();
        $taskPeriod = function ($task) use ($project) {
            $end = ($task->completed_at ?: $task->due_date)?->copy()->startOfDay();
            $start = $task->created_at?->copy()->startOfDay();
            if ($start && $end && $start->gt($end)) {
                $projectStart = $project->start_date?->copy()->startOfDay();
                $start = $projectStart && $projectStart->lte($end) ? $projectStart : $end->copy();
            }
            return [$start, $end];
        };
        foreach ($roadmaps as $roadmap) {
            $roadmapTasks = $roadmap->improvements->flatMap->tasks;
            $roadmapStarts = $roadmapTasks->map(fn ($task) => $taskPeriod($task)[0])->filter();
            $roadmapEnds = $roadmapTasks->map(fn ($task) => $taskPeriod($task)[1])->filter();
            $roadmapHasPlan = $roadmap->planned_start_date && $roadmap->target_date;
            $roadmapStart = $roadmap->planned_start_date ?: $roadmapStarts->min();
            $roadmapEnd = $roadmap->target_date ?: $roadmapEnds->max();
            $timeRows->push(['type' => 'roadmap', 'title' => $roadmap->title, 'start' => $roadmapStart, 'end' => $roadmapEnd, 'inferred' => !$roadmapHasPlan, 'overdue' => $roadmap->target_date && !$roadmap->reached_at && $roadmap->target_date->isPast(), 'reached' => $roadmap->reached_at]);
            foreach ($roadmap->improvements as $improvement) {
                $initiativeStarts = $improvement->tasks->map(fn ($task) => $taskPeriod($task)[0])->filter();
                $initiativeEnds = $improvement->tasks->map(fn ($task) => $taskPeriod($task)[1])->filter();
                $initiativeHasPlan = $improvement->planned_start_date && $improvement->target_date;
                $initiativeStart = $improvement->planned_start_date ?: $initiativeStarts->min();
                $initiativeEnd = $improvement->target_date ?: $initiativeEnds->max();
                $timeRows->push(['type' => 'improvement', 'title' => $improvement->title, 'start' => $initiativeStart, 'end' => $initiativeEnd, 'inferred' => !$initiativeHasPlan, 'overdue' => $improvement->target_date && !$improvement->completed_at && $improvement->target_date->isPast(), 'reached' => $improvement->completed_at]);
                foreach ($improvement->tasks as $task) {
                    [$taskStart, $taskEnd] = $taskPeriod($task);
                    $timeRows->push(['type' => 'task', 'title' => $task->title, 'start' => $taskStart, 'end' => $taskEnd, 'inferred' => false, 'overdue' => $task->due_date && !$task->completed_at && $task->due_date->isPast(), 'reached' => null]);
                }
            }
        }
        $timeDates = $timeRows->flatMap(fn ($row) => [$row['start'], $row['end'], $row['reached']])->filter();
        $axisStart = collect([$project->start_date?->copy()->startOfDay(), $timeDates->min(), now()->startOfDay()])->filter()->min() ?: now()->startOfDay();
        $axisEnd = collect([$project->completed_at?->copy()->startOfDay(), $project->due_date?->copy()->startOfDay(), $timeDates->max(), now()->startOfDay()])->filter()->max() ?: $axisStart->copy()->addDays(14);
        if ($axisEnd->lte($axisStart)) $axisEnd = $axisStart->copy()->addDays(14);
        $axisDays = max(1, $axisStart->diffInDays($axisEnd));
        $axisStep = $axisDays <= 45 ? max(1, (int) ceil($axisDays / 8)) : max(7, (int) ceil($axisDays / 8 / 7) * 7);
        $axisTicks = collect(range(0, $axisDays, $axisStep))->push($axisDays)->unique()->sort()->values();
        $todayLeft = max(0, min(100, $axisStart->diffInDays(now()->startOfDay(), false) / $axisDays * 100));
    @endphp

    <section class="stack focus-page {{ $isTimeView ? 'time-view' : '' }}" id="focus-page">
        <div class="focus-toolbar">
            <div class="focus-toolbar-inner">
                <div class="focus-toolbar-context">
                    <div class="focus-toolbar-title">フォーカスレイヤー</div>
                    <div class="focus-path" id="focus-path">
                        <a href="{{ route('projects.index') }}">PROJECT一覧</a>
                        <span>›</span><button type="button" data-focus-reset="project" class="is-current" title="{{ $project->name }}">{{ $project->name }}</button>
                        <span data-path-separator="roadmap" hidden>›</span><button type="button" data-path="roadmap" hidden></button>
                        <span data-path-separator="improvement" hidden>›</span><button type="button" data-path="improvement" hidden></button>
                        <span data-path-separator="task" hidden>›</span><button type="button" data-path="task" hidden></button>
                    </div>
                </div>
                <div class="focus-view-switch">
                    <a href="{{ route('projects.ai-proposals.index', $project) }}">AI提案</a>
                    <a class="{{ $isTimeView ? '' : 'is-current' }}" href="{{ route('projects.show', $project) }}">フォーカス表示</a>
                    <a class="{{ $isTimeView ? 'is-current' : '' }}" href="{{ route('projects.show', ['project' => $project, 'view' => 'time']) }}">時間表示</a>
                </div>
                <a class="button secondary focus-manage-link" href="{{ route('projects.legacy', $project) }}">管理詳細を見る</a>
            </div>
        </div>

        <div class="time-layer">
            <div class="time-layer-head">
                <div>
                    <div class="focus-layer-label focus-project-label">時間レイヤー・いつ、どの順番で進めるか</div>
                    <h1>{{ $project->name }}</h1>
                    <p>ROADMAP・取り組み・TASKを、ひとつの時間軸で確認します。</p>
                </div>
                <div class="time-legend">
                    <span><i></i>登録期間</span><span><i class="is-inferred"></i>配下から自動算出</span><span><i class="is-overdue"></i>遅延</span><span><i class="is-reached"></i>実際の完了・到達日</span>
                </div>
            </div>
            <div class="time-chart-scroll">
                <div class="time-chart" style="--time-grid:{{ $axisStep / $axisDays * 100 }}%; --today-left:{{ $todayLeft }}%;">
                    <div class="time-axis">
                        <div class="time-axis-label"><strong>{{ $axisDays <= 45 ? '日表示' : '週表示' }}</strong><div class="meta">赤線は今日</div></div>
                        <div class="time-axis-track">
                            <span class="time-today"></span>
                            @foreach ($axisTicks as $tick)<span class="time-axis-date {{ $loop->first ? 'is-first' : '' }} {{ $loop->last ? 'is-last' : '' }}" style="left:{{ $tick / $axisDays * 100 }}%">{{ $axisStart->copy()->addDays($tick)->format('n/j') }}</span>@endforeach
                        </div>
                    </div>
                    @forelse ($timeRows as $row)
                        @php
                            $barStart = $row['start'];
                            $barEnd = $row['end'];
                            $barLeft = $barStart ? max(0, min(100, $axisStart->diffInDays($barStart, false) / $axisDays * 100)) : 0;
                            $barWidth = ($barStart && $barEnd) ? max(.8, $barStart->diffInDays($barEnd) / $axisDays * 100) : 0;
                            $reachedLeft = $row['reached'] ? max(0, min(100, $axisStart->diffInDays($row['reached'], false) / $axisDays * 100)) : null;
                        @endphp
                        <div class="time-row is-{{ $row['type'] }}">
                            <div class="time-row-label"><span class="time-row-dot"></span><strong title="{{ $row['title'] }}">{{ $row['title'] }}</strong></div>
                            <div class="time-row-track">
                                <span class="time-today"></span>
                                @if ($barStart && $barEnd)
                                    <span class="time-bar is-{{ $row['type'] }} {{ $row['inferred'] ? 'is-inferred' : '' }} {{ $row['overdue'] ? 'is-overdue' : '' }}" style="--bar-left:{{ $barLeft }}%; --bar-width:{{ $barWidth }}%;" title="{{ $barStart->format('Y/m/d') }}〜{{ $barEnd->format('Y/m/d') }}"></span>
                                @else
                                    <span class="time-unscheduled">{{ $barStart ? '期限未設定' : '日付未設定' }}</span>
                                @endif
                                @if ($reachedLeft !== null)<span class="time-reached-marker" style="left:{{ $reachedLeft }}%" title="実績日：{{ $row['reached']->format('Y/m/d') }}"></span>@endif
                            </div>
                        </div>
                    @empty
                        <div class="time-row"><div class="time-row-label">表示対象なし</div><div class="time-row-track"><span class="time-unscheduled">Roadmap・取り組み・Taskがまだありません</span></div></div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="focus-project">
            <div class="focus-project-head">
                <div>
                    <div class="focus-layer-label focus-project-label">PROJECT・何を実現するか</div>
                    <h1>{{ $project->name }}</h1>
                    <p>{{ $project->summary ?: 'このProjectの目的から、具体的な行動までを内側へたどります。' }}</p>
                </div>
                @if ($canEditProject)<a class="button" href="{{ route('projects.edit', $project) }}">Projectを編集</a>@endif
            </div>

            @php($sourceImprovement = $project->sourceImprovementOutput?->improvement)
            @if ($project->sourceImprovementOutput)
                <div class="card stack origin-panel" style="margin-bottom:18px;">
                    <div>
                        <div class="badge">改善から生まれたProject</div>
                        <h2>このProjectの起点</h2>
                    </div>
                    @if ($sourceImprovement && Gate::allows('view', $sourceImprovement))
                        <p>
                            <a href="{{ route('projects.show', $sourceImprovement->project) }}">{{ $sourceImprovement->project->name }}</a>
                            で生まれた取り組み
                            <a href="{{ route('projects.improvements.show', [$sourceImprovement->project, $sourceImprovement]) }}">{{ $sourceImprovement->title }}</a>
                            から、このProjectへつながりました。
                        </p>
                    @else
                        <p>起点となった改善は公開範囲により表示されません。</p>
                    @endif
                </div>
            @endif

            <div class="focus-summary">
                <div><strong>{{ $roadmaps->count() }}</strong><span>Roadmap</span></div>
                <div><strong>{{ $allImprovements->count() }}</strong><span>改善</span></div>
                <div><strong>{{ $allTasks->count() }}</strong><span>Task</span></div>
                <div><strong>{{ $completedTasks->count() }}/{{ $allTasks->count() }}</strong><span>完了</span></div>
            </div>

            <div class="focus-roadmaps" id="focus-roadmaps">
                @forelse ($roadmaps as $roadmap)
                    <article class="focus-roadmap" data-roadmap="{{ $roadmap->id }}" data-title="{{ $roadmap->title }}">
                        <button type="button" class="focus-roadmap-trigger" data-focus-roadmap="{{ $roadmap->id }}">
                            <div class="focus-layer-label focus-roadmap-label">ROADMAP・実現までの道筋</div>
                            <div class="focus-trigger-head"><h2>{{ $roadmap->title }}</h2><span class="focus-open-hint">この中を見る</span></div>
                            <p>{{ $roadmap->purpose ?: 'この道筋を、具体的な取り組みによって前へ進めます。' }}</p>
                            <div class="meta">取り組み {{ $roadmap->improvements->count() }}件</div>
                        </button>

                        <div class="focus-roadmap-body">
                            @if ($canCreateImprovement)
                                <div class="actions" style="margin-bottom:12px; justify-content:flex-end;">
                                    <a class="button secondary" href="{{ route('projects.roadmaps.edit', [$project, $roadmap]) }}">Roadmapを編集</a>
                                    <a class="button" href="{{ route('projects.improvements.create', $project) }}?roadmap={{ $roadmap->id }}">このRoadmapに取り組みを登録</a>
                                </div>
                            @endif
                            <div class="focus-improvements" data-improvements-for="{{ $roadmap->id }}">
                                @forelse ($roadmap->improvements as $improvement)
                                    <article class="focus-improvement" data-improvement="{{ $improvement->id }}" data-title="{{ $improvement->title }}">
                                        <button type="button" class="focus-improvement-trigger" data-focus-improvement="{{ $improvement->id }}">
                                            <div class="focus-layer-label focus-improvement-label">取り組み・道筋を前へ進める</div>
                                            <div class="focus-trigger-head"><h2>{{ $improvement->title }}</h2><span class="focus-open-hint">この中を見る</span></div>
                                            <p>{{ Str::limit($improvement->next_action ?: $improvement->problem ?: '改善内容を具体的なTaskへつなげます。', 120) }}</p>
                                            <div class="meta">予定 {{ $improvement->planned_start_date?->format('Y/m/d') ?? '未設定' }}〜{{ $improvement->target_date?->format('Y/m/d') ?? '未設定' }} / 完了 {{ $improvement->completed_at?->format('Y/m/d') ?? '未完了' }}</div>
                                            <div class="meta">Task {{ $improvement->tasks->count() }}件</div>
                                        </button>

                                        <div class="focus-improvement-body">
                                            <div class="focus-tasks" data-tasks-for="{{ $improvement->id }}">
                                                @forelse ($improvement->tasks as $task)
                                                    <article class="focus-task" data-task="{{ $task->id }}" data-title="{{ $task->title }}">
                                                        <button type="button" class="focus-task-trigger" data-focus-task="{{ $task->id }}">
                                                            <div class="focus-layer-label focus-task-label">TASK・いま何をするか</div>
                                                            <div class="focus-trigger-head"><h2>{{ $task->title }}</h2><span class="focus-open-hint">内容を見る</span></div>
                                                            <div class="meta">{{ $taskStatuses[$task->status] ?? $task->status }} / 期限 {{ $task->due_date?->format('Y年n月j日') ?? '未設定' }}</div>
                                                        </button>
                                                        <div class="focus-task-detail">
                                                            <p>{{ $task->description ?: '説明はまだありません。' }}</p>
                                                            <a href="{{ route('projects.tasks.edit', [$project, $task]) }}">詳細・編集へ</a>
                                                        </div>
                                                    </article>
                                                @empty<div class="focus-empty">具体的なTaskはまだありません。</div>@endforelse
                                            </div>
                                        </div>
                                    </article>
                                @empty<div class="focus-empty">このRoadmapに紐づく取り組みはまだありません。</div>@endforelse
                            </div>
                        </div>
                    </article>
                @empty<div class="focus-empty">Roadmapはまだありません。</div>@endforelse
            </div>

            @if ($unclassifiedImprovements->isNotEmpty() || $directTasks->isNotEmpty())
                <div class="focus-unclassified">
                    <div class="focus-layer-label focus-project-label">PROJECT直下・まだ整理途中の仕事</div>
                    <p>最初から階層を強制せず、意味が見えた段階でRoadmapや取り組みへ整理できます。</p>
                    <div class="focus-unclassified-items">
                        @foreach ($unclassifiedImprovements as $improvement)<a class="focus-chip" href="{{ route('projects.improvements.show', [$project, $improvement]) }}">取り組み：{{ $improvement->title }}</a>@endforeach
                        @foreach ($directTasks as $task)<a class="focus-chip" href="{{ route('projects.tasks.show', [$project, $task]) }}">Task：{{ $task->title }}</a>@endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="focus-footer">
            <section class="card stack">
                <div><div class="focus-layer-label focus-task-label">いま行うこと</div><h2>未完了のTask</h2></div>
                @forelse ($allTasks->whereNotIn('status', [\App\Models\Task::STATUS_DONE, \App\Models\Task::STATUS_ARCHIVED]) as $task)
                    <a href="{{ route('projects.tasks.show', [$project, $task]) }}">{{ $task->title }}</a>
                @empty<p>現在のTaskはすべて完了しています。</p>@endforelse
            </section>
            <section class="card stack">
                <div><div class="focus-layer-label focus-project-label">最近の動き</div><h2>プロジェクトタイムライン</h2></div>
                @forelse ($projectTimeline->reverse()->take(4) as $event)
                    <div class="focus-history-item">
                        <div class="meta">{{ $event['date']->format('Y年n月j日') }} / {{ $event['type'] }}</div>
                        <div>
                            <a href="{{ $event['url'] ?: '#focus-page' }}">{{ $event['title'] }}</a>
                            <p style="margin:4px 0 0;">{{ $event['description'] }}</p>
                        </div>
                    </div>
                @empty<p>表示できる出来事はまだありません。</p>@endforelse
            </section>
        </div>
    </section>

    <script>
        (() => {
            const page = document.getElementById('focus-page');
            const roadmaps = document.getElementById('focus-roadmaps');
            if (!page || !roadmaps) return;

            const state = { roadmap: null, improvement: null, task: null };
            const pathButtons = Object.fromEntries(['roadmap', 'improvement', 'task'].map(key => [key, document.querySelector(`[data-path="${key}"]`)]));

            const titleOf = (key, id) => id ? document.querySelector(`[data-${key}="${CSS.escape(String(id))}"]`)?.dataset.title : null;
            const setPath = (key, value) => {
                const button = pathButtons[key];
                const separator = document.querySelector(`[data-path-separator="${key}"]`);
                button.hidden = !value;
                separator.hidden = !value;
                if (value) button.textContent = titleOf(key, value) || key;
            };

            const render = (updateUrl = true) => {
                document.querySelectorAll('[data-roadmap]').forEach(el => el.classList.toggle('is-focused', String(el.dataset.roadmap) === String(state.roadmap)));
                roadmaps.classList.toggle('has-focus', Boolean(state.roadmap));
                document.querySelectorAll('[data-improvement]').forEach(el => el.classList.toggle('is-focused', String(el.dataset.improvement) === String(state.improvement)));
                document.querySelectorAll('[data-improvements-for]').forEach(el => el.classList.toggle('has-focus', Boolean(state.improvement) && el.closest('[data-roadmap]')?.classList.contains('is-focused')));
                document.querySelectorAll('[data-task]').forEach(el => el.classList.toggle('is-focused', String(el.dataset.task) === String(state.task)));
                document.querySelectorAll('[data-tasks-for]').forEach(el => el.classList.toggle('has-focus', Boolean(state.task) && el.closest('[data-improvement]')?.classList.contains('is-focused')));
                page.classList.toggle('has-roadmap', Boolean(state.roadmap));
                page.classList.toggle('has-improvement', Boolean(state.improvement));
                page.classList.toggle('has-task', Boolean(state.task));
                setPath('roadmap', state.roadmap); setPath('improvement', state.improvement); setPath('task', state.task);
                document.querySelectorAll('#focus-path button').forEach(el => el.classList.remove('is-current'));
                (state.task ? pathButtons.task : state.improvement ? pathButtons.improvement : state.roadmap ? pathButtons.roadmap : document.querySelector('[data-focus-reset="project"]'))?.classList.add('is-current');

                if (updateUrl) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('layer');
                    ['roadmap', 'improvement', 'task'].forEach(key => state[key] ? url.searchParams.set(key, state[key]) : url.searchParams.delete(key));
                    history.pushState({...state}, '', url);
                }
            };

            document.addEventListener('click', event => {
                const roadmap = event.target.closest('[data-focus-roadmap]');
                const improvement = event.target.closest('[data-focus-improvement]');
                const task = event.target.closest('[data-focus-task]');
                const path = event.target.closest('[data-path]');
                if (roadmap) { state.roadmap = roadmap.dataset.focusRoadmap; state.improvement = null; state.task = null; render(); }
                else if (improvement) { state.improvement = improvement.dataset.focusImprovement; state.task = null; render(); }
                else if (task) { state.task = task.dataset.focusTask; render(); }
                else if (path?.dataset.path === 'roadmap') { state.improvement = null; state.task = null; render(); }
                else if (path?.dataset.path === 'improvement') { state.task = null; render(); }
                else if (event.target.closest('[data-focus-reset="project"]')) {
                    state.roadmap = null;
                    state.improvement = null;
                    state.task = null;
                    render();
                    document.querySelector('.focus-project')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });

            const readUrl = () => {
                const params = new URLSearchParams(window.location.search);
                state.roadmap = params.get('roadmap'); state.improvement = params.get('improvement'); state.task = params.get('task');
                if (params.has('layer')) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('layer');
                    history.replaceState({...state}, '', url);
                }
                render(false);
            };
            window.addEventListener('popstate', readUrl);
            readUrl();
        })();
    </script>
@endsection
