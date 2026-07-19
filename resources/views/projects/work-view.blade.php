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
        .focus-view-switch a,.focus-view-switch button { padding:5px 8px; border:1px solid var(--line); border-radius:6px; background:#fff; color:var(--accent-dark); font-size:12px; font-weight:inherit; text-decoration:none; white-space:nowrap; }
        .focus-view-switch a.is-current { background:var(--accent-dark); color:#fff; }
        .focus-ai-trigger { display:inline-flex; align-items:center; gap:6px; }
        .focus-ai-count { display:inline-flex; min-width:19px; height:19px; padding:0 5px; align-items:center; justify-content:center; border-radius:999px; background:#c65a46; color:#fff; font-size:11px; font-weight:900; }
        .schedule-integrity { padding:14px 18px; border:2px solid #d5a22f; border-radius:10px; background:#fffaf0; }
        .schedule-integrity.is-invalid { border-color:#c65a46; background:#fff7f5; }
        .schedule-integrity summary { cursor:pointer; font-weight:900; }
        .schedule-integrity ul { margin:12px 0 0; }
        .schedule-counts { display:flex; flex-wrap:wrap; gap:7px; margin-top:10px; }
        .schedule-counts span { padding:4px 8px; border:1px solid currentColor; border-radius:999px; font-size:12px; }
        .ai-drawer-overlay { position:fixed; z-index:60; inset:0; border:0; background:rgba(20,30,38,.38); opacity:0; visibility:hidden; transition:opacity .2s ease,visibility .2s ease; }
        .ai-drawer { position:fixed; z-index:61; top:0; right:0; width:min(620px,92vw); height:100vh; padding:24px; overflow-y:auto; border-left:1px solid var(--line); background:#f8fafb; box-shadow:-14px 0 38px rgba(20,40,55,.18); transform:translateX(102%); visibility:hidden; transition:transform .24s ease,visibility .24s ease; }
        .ai-drawer.is-open { transform:translateX(0); visibility:visible; }
        .ai-drawer-overlay.is-open { opacity:1; visibility:visible; }
        .ai-drawer-head { position:sticky; z-index:2; top:-24px; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin:-24px -24px 20px; padding:20px 24px; border-bottom:1px solid var(--line); background:rgba(248,250,251,.96); }
        .ai-drawer-head h2 { margin:0; }
        .ai-drawer-close { flex:0 0 auto; width:40px; height:40px; padding:0; border:1px solid var(--line); border-radius:999px; background:#fff; color:var(--ink); font-size:24px; line-height:1; }
        .ai-drawer-section { padding:18px; border:1px solid var(--line); border-radius:10px; background:#fff; }
        .ai-drawer-request-list { display:grid; gap:10px; }
        .ai-drawer-request { padding:13px; border:1px solid var(--line); border-radius:8px; background:#fbfcfd; }
        .time-layer { display:none; padding:22px; border:2px solid #4a5660; border-radius:12px; background:#fff; }
        .focus-page.time-view .time-layer { display:block; }
        .focus-page.time-view > .focus-project,.focus-page.time-view > .focus-footer { display:none; }
        .time-layer-head { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; margin-bottom:18px; }
        .time-legend { display:flex; flex-wrap:wrap; gap:12px; color:var(--muted); font-size:12px; }
        .time-legend span { display:inline-flex; align-items:center; gap:5px; }
        .time-legend label { display:inline-flex; align-items:center; gap:7px; cursor:pointer; }
        .time-legend input { width:auto; margin:0; }
        .time-legend i { width:20px; height:8px; border-radius:999px; background:#66717a; }
        .time-legend .is-roadmap { background:#4f82c4; }
        .time-legend .is-improvement { background:#56a27e; }
        .time-legend .is-task { background:#b5523d; }
        .time-legend .is-inferred { background:transparent; border:2px dashed #66717a; }
        .time-legend .is-overdue { background:repeating-linear-gradient(135deg,#e3a11d 0,#e3a11d 5px,#9f6810 5px,#9f6810 9px); }
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
        .time-row.is-schedule-missing { background:#fff9e9; }
        .time-row.is-schedule-invalid { background:#fff0ed; }
        .time-row.is-schedule-unverifiable { background:#fff6dc; }
        .time-row-label { display:flex; align-items:center; gap:7px; min-width:0; }
        .time-row-label strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .time-row-status { flex:0 0 auto; padding:2px 6px; border:1px solid var(--line); border-radius:999px; background:#fff; color:var(--muted); font-size:10px; }
        .time-row.is-improvement .time-row-label { padding-left:28px; }
        .time-row.is-task .time-row-label { padding-left:46px; }
        .time-row-dot { flex:0 0 auto; width:8px; height:8px; border-radius:50%; background:#66717a; }
        .time-row.is-roadmap .time-row-dot,.time-bar.is-roadmap { background:#4f82c4; }
        .time-row.is-improvement .time-row-dot,.time-bar.is-improvement { background:#56a27e; }
        .time-row.is-task .time-row-dot,.time-bar.is-task { background:#b5523d; }
        .time-bar { position:absolute; top:13px; left:var(--bar-left); width:max(var(--bar-width),6px); height:18px; border-radius:999px; }
        .time-bar.is-editable:not(.is-task) { cursor:grab; }
        .time-bar.is-dragging { cursor:grabbing; opacity:.78; }
        .time-resize-handle { position:absolute; z-index:2; top:-4px; width:10px; height:26px; border:2px solid #fff; border-radius:5px; background:#263f4d; cursor:ew-resize; box-shadow:0 1px 4px rgba(0,0,0,.25); }
        .time-resize-handle.is-start { left:-4px; }
        .time-resize-handle.is-end { right:-4px; }
        .time-save-status { position:fixed; z-index:80; right:20px; bottom:20px; max-width:420px; padding:12px 16px; border-radius:8px; background:#263f4d; color:#fff; box-shadow:0 8px 24px rgba(0,0,0,.2); }
        .time-save-status.is-error { background:#a33f2d; }
        .time-bar.is-inferred { background:rgba(255,255,255,.72); border:2px dashed currentColor; }
        .time-bar.is-roadmap.is-inferred { color:#4f82c4; }
        .time-bar.is-improvement.is-inferred { color:#56a27e; }
        .time-bar.is-overdue { background:repeating-linear-gradient(135deg,#e3a11d 0,#e3a11d 7px,#9f6810 7px,#9f6810 12px); }
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
            .ai-drawer { width:100vw; padding:18px; }
            .ai-drawer-head { top:-18px; margin:-18px -18px 18px; padding:16px 18px; }
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
        $includeTodayInTimeline = request('include_today', '1') !== '0';

        $timeRows = collect();
        $scheduleIssueFor = function (string $type, int $id) use ($scheduleIntegrity): ?string {
            $key = $type.':'.$id;
            if ($scheduleIntegrity['entities']['invalid']->contains($key)) return 'invalid';
            if ($scheduleIntegrity['entities']['missing']->contains($key)) return 'missing';
            if ($scheduleIntegrity['entities']['unverifiable']->contains($key)) return 'unverifiable';
            return null;
        };
        $improvementPlanStarts = $roadmaps
            ->flatMap(fn ($roadmap) => $roadmap->improvements)
            ->mapWithKeys(fn ($improvement) => [$improvement->id => $improvement->planned_start_date]);
        $taskPeriod = function ($task) use ($project, $improvementPlanStarts) {
            $end = ($task->completed_at ?: $task->due_date)?->copy()->startOfDay();
            $start = $improvementPlanStarts->get($task->improvement_id)?->copy()->startOfDay()
                ?: $task->created_at?->copy()->startOfDay();
            $projectStart = $project->start_date?->copy()->startOfDay();
            if ($projectStart && $start && $start->lt($projectStart)) {
                $start = $projectStart->copy();
            }
            if ($start && $end && $start->gt($end)) {
                $start = $projectStart && $projectStart->lte($end) ? $projectStart : $end->copy();
            }
            return [$start, $end];
        };
        $timelineRoadmaps = $roadmaps->sortBy(fn ($roadmap) => [
            $roadmap->planned_start_date?->timestamp ?? PHP_INT_MAX,
            $roadmap->sort_order ?? PHP_INT_MAX,
            $roadmap->id,
        ])->values();
        foreach ($timelineRoadmaps as $roadmap) {
            $timelineImprovements = $roadmap->improvements->sortBy(fn ($improvement) => [
                $improvement->planned_start_date?->timestamp ?? PHP_INT_MAX,
                $improvement->roadmap_sort_order ?? PHP_INT_MAX,
                $improvement->id,
            ])->values();
            $roadmapTasks = $timelineImprovements->flatMap->tasks;
            $roadmapStarts = $roadmapTasks->map(fn ($task) => $taskPeriod($task)[0])->filter();
            $roadmapEnds = $roadmapTasks->map(fn ($task) => $taskPeriod($task)[1])->filter();
            $roadmapHasPlan = $roadmap->planned_start_date && $roadmap->target_date;
            $roadmapStart = $roadmap->planned_start_date ?: $roadmapStarts->min();
            $roadmapEnd = $roadmap->target_date ?: $roadmapEnds->max();
            $timeRows->push(['type' => 'roadmap', 'id' => $roadmap->id, 'title' => $roadmap->title, 'start' => $roadmapStart, 'end' => $roadmapEnd, 'inferred' => !$roadmapHasPlan, 'overdue' => $roadmap->target_date && !$roadmap->reached_at && $roadmap->target_date->isPast(), 'reached' => $roadmap->reached_at, 'schedule_issue' => $scheduleIssueFor('roadmap', $roadmap->id), 'editable' => Gate::allows('update', $roadmap), 'update_url' => route('projects.timeline.update', [$project, 'roadmap', $roadmap->id])]);
            foreach ($timelineImprovements as $improvement) {
                $initiativeStarts = $improvement->tasks->map(fn ($task) => $taskPeriod($task)[0])->filter();
                $initiativeEnds = $improvement->tasks->map(fn ($task) => $taskPeriod($task)[1])->filter();
                $initiativeHasPlan = $improvement->planned_start_date && $improvement->target_date;
                $initiativeStart = $improvement->planned_start_date ?: $initiativeStarts->min();
                $initiativeEnd = $improvement->target_date ?: $initiativeEnds->max();
                $timeRows->push(['type' => 'improvement', 'id' => $improvement->id, 'title' => $improvement->title, 'start' => $initiativeStart, 'end' => $initiativeEnd, 'inferred' => !$initiativeHasPlan, 'overdue' => $improvement->target_date && !$improvement->completed_at && $improvement->target_date->isPast(), 'reached' => $improvement->completed_at, 'schedule_issue' => $scheduleIssueFor('improvement', $improvement->id), 'editable' => Gate::allows('update', $improvement), 'update_url' => route('projects.timeline.update', [$project, 'improvement', $improvement->id])]);
                $timelineTasks = $improvement->tasks->sortBy(fn ($task) => [
                    $task->due_date?->timestamp ?? PHP_INT_MAX,
                    $task->id,
                ])->values();
                foreach ($timelineTasks as $task) {
                    [$taskStart, $taskEnd] = $taskPeriod($task);
                $timeRows->push(['type' => 'task', 'id' => $task->id, 'title' => $task->title, 'status_label' => $taskStatuses[$task->status] ?? $task->status, 'start' => $taskStart, 'end' => $taskEnd, 'inferred' => false, 'overdue' => $task->status === \App\Models\Task::STATUS_IN_PROGRESS && $task->due_date && !$task->completed_at && $task->due_date->isPast(), 'reached' => null, 'schedule_issue' => $scheduleIssueFor('task', $task->id), 'editable' => Gate::allows('update', $task) && $task->status !== \App\Models\Task::STATUS_DONE, 'update_url' => route('projects.timeline.update', [$project, 'task', $task->id])]);
                }
            }
        }
        $timeDates = $timeRows->flatMap(fn ($row) => [$row['start'], $row['end'], $row['reached']])->filter();
        $plannedAxisStart = collect([$project->start_date?->copy()->startOfDay(), $timeDates->min()])->filter()->min() ?: now()->startOfDay();
        $plannedAxisEnd = collect([$project->completed_at?->copy()->startOfDay(), $project->due_date?->copy()->startOfDay(), $timeDates->max()])->filter()->max() ?: $plannedAxisStart->copy()->addDays(14);
        $axisStart = $includeTodayInTimeline
            ? collect([$plannedAxisStart, now()->startOfDay()])->min()
            : $plannedAxisStart->copy()->subDays(2);
        $axisEnd = $includeTodayInTimeline
            ? collect([$plannedAxisEnd, now()->startOfDay()])->max()
            : $plannedAxisEnd->copy()->addDays(2);
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
                    <button type="button" class="focus-ai-trigger" data-ai-drawer-open aria-controls="ai-assistant-drawer" aria-expanded="false">
                        AIアシスタント
                        @if ($pendingAiProposalCount > 0)<span class="focus-ai-count" aria-label="承認待ち {{ $pendingAiProposalCount }}件">{{ $pendingAiProposalCount }}</span>@endif
                    </button>
                    <a class="{{ $isTimeView ? '' : 'is-current' }}" href="{{ route('projects.show', $project) }}">フォーカス表示</a>
                    <a class="{{ $isTimeView ? 'is-current' : '' }}" href="{{ route('projects.show', ['project' => $project, 'view' => 'time']) }}">時間表示</a>
                </div>
                <a class="button secondary focus-manage-link" href="{{ route('projects.legacy', $project) }}">管理詳細を見る</a>
            </div>
        </div>

        @if ($scheduleIntegrity['status'] !== \App\Services\ScheduleIntegrityService::STATUS_OK)
            <details class="schedule-integrity is-{{ $scheduleIntegrity['status'] }}">
                <summary>{{ $scheduleIntegrity['label'] }}</summary>
                @if ($scheduleIntegrity['invalid']->isNotEmpty())
                    <h3>日程要再設定</h3>
                    <div class="schedule-counts">
                        <span>ロードマップ {{ $scheduleIntegrity['counts']['invalid']['roadmap'] }}</span>
                        <span>取り組み {{ $scheduleIntegrity['counts']['invalid']['improvement'] }}</span>
                        <span>タスク {{ $scheduleIntegrity['counts']['invalid']['task'] }}</span>
                    </div>
                    <ul>@foreach ($scheduleIntegrity['invalid'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                @endif
                @if ($scheduleIntegrity['missing']->isNotEmpty())
                    <h3>日程未設定</h3>
                    <div class="schedule-counts">
                        <span>Project {{ $scheduleIntegrity['counts']['missing']['project'] }}</span>
                        <span>ロードマップ {{ $scheduleIntegrity['counts']['missing']['roadmap'] }}</span>
                        <span>取り組み {{ $scheduleIntegrity['counts']['missing']['improvement'] }}</span>
                        <span>タスク {{ $scheduleIntegrity['counts']['missing']['task'] }}</span>
                    </div>
                    <ul>@foreach ($scheduleIntegrity['missing'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                @endif
                @if ($scheduleIntegrity['unverifiable']->isNotEmpty())
                    <h3>親の日程未設定により判定できない項目</h3>
                    <div class="schedule-counts">
                        <span>ロードマップ {{ $scheduleIntegrity['counts']['unverifiable']['roadmap'] }}</span>
                        <span>取り組み {{ $scheduleIntegrity['counts']['unverifiable']['improvement'] }}</span>
                        <span>タスク {{ $scheduleIntegrity['counts']['unverifiable']['task'] }}</span>
                    </div>
                    <ul>@foreach ($scheduleIntegrity['unverifiable'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                @endif
            </details>
        @endif

        <button type="button" class="ai-drawer-overlay {{ $errors->any() ? 'is-open' : '' }}" data-ai-drawer-close aria-label="AIアシスタントを閉じる"></button>
        <aside id="ai-assistant-drawer" class="ai-drawer {{ $errors->any() ? 'is-open' : '' }}" aria-label="AIアシスタント" aria-hidden="{{ $errors->any() ? 'false' : 'true' }}">
            <div class="ai-drawer-head">
                <div>
                    <div class="meta">このProjectをAIと相談する</div>
                    <h2>AIアシスタント</h2>
                </div>
                <button type="button" class="ai-drawer-close" data-ai-drawer-close aria-label="閉じる">×</button>
            </div>
            <div class="stack">
                @if ($pendingAiProposals->isNotEmpty())
                    <section class="ai-drawer-section stack" style="border-color:#d8a092; background:#fff9f7;">
                        <div>
                            <div class="meta">確認が必要です</div>
                            <h3 style="margin-bottom:0;">承認待ちのAI提案 {{ $pendingAiProposals->count() }}件</h3>
                        </div>
                        <div class="ai-drawer-request-list">
                            @foreach ($pendingAiProposals as $pendingProposal)
                                <article class="ai-drawer-request" style="border-color:#e1b3a8; background:#fff;">
                                    <div class="meta">{{ $pendingProposal->created_at->format('Y-m-d H:i') }} に届きました</div>
                                    <strong>{{ $pendingProposal->title }}</strong>
                                    @if ($pendingProposal->summary)<p>{{ Str::limit($pendingProposal->summary, 140) }}</p>@endif
                                    <div class="actions">
                                        <a class="button" href="{{ route('projects.ai-proposals.show', [$project, $pendingProposal]) }}">内容を確認</a>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="ai-drawer-section stack">
                    <div>
                        <h3>AIに提案を依頼</h3>
                        <p>対象WorkspaceとProjectは自動で確定します。回答は承認待ち提案として届きます。</p>
                    </div>
                    <form method="POST" action="{{ route('projects.ai-requests.store', $project) }}" enctype="multipart/form-data" class="stack">
                        @csrf
                        <div class="field">
                            <label for="ai_request_title">依頼名</label>
                            <input id="ai_request_title" name="title" value="{{ old('title', 'このProjectの計画を提案して') }}" required>
                        </div>
                        <div class="field">
                            <label for="ai_request_instructions">Codexへの依頼内容</label>
                            <textarea id="ai_request_instructions" name="instructions" rows="5" required placeholder="例：現状を読み取り、次のロードマップ・取り組み・タスクを提案してください。">{{ old('instructions') }}</textarea>
                        </div>
                        <div class="field">
                            <label for="ai_request_attachments">参考資料（最大5ファイル・各10MB）</label>
                            <input id="ai_request_attachments" type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.csv,.xlsx,.docx">
                            <div class="meta">画像、PDF、Excel、CSV、Wordに対応。資料は非公開領域へ保存されます。</div>
                        </div>
                        <div class="actions"><button type="submit">AIに提案を依頼</button></div>
                    </form>
                </section>

                <section class="ai-drawer-section stack">
                    <div class="actions" style="justify-content:space-between;align-items:center;">
                        <h3 style="margin:0;">最近のAI依頼</h3>
                        <a href="{{ route('projects.ai-proposals.index', $project) }}">AI提案一覧へ</a>
                    </div>
                    <div class="ai-drawer-request-list">
                        @forelse ($aiRequests as $aiRequest)
                            <article class="ai-drawer-request">
                                <div class="meta">{{ $aiRequest->created_at->format('Y-m-d H:i') }} / {{ $aiRequest->status }}</div>
                                <strong>{{ $aiRequest->title }}</strong>
                                <p>{{ Str::limit($aiRequest->instructions, 160) }}</p>
                                @if ($aiRequest->attachments->isNotEmpty())
                                    <div class="actions">
                                        @foreach ($aiRequest->attachments as $attachment)
                                            <a href="{{ route('projects.ai-requests.attachments.download', [$project, $aiRequest, $attachment]) }}">📎 {{ $attachment->original_name }}</a>
                                        @endforeach
                                    </div>
                                @endif
                                @if ($aiRequest->proposal)<a href="{{ route('projects.ai-proposals.show', [$project, $aiRequest->proposal]) }}">届いた提案を確認</a>@endif
                            </article>
                        @empty
                            <p class="meta">AIへの依頼はまだありません。</p>
                        @endforelse
                    </div>
                </section>
            </div>
        </aside>

        <div class="time-layer">
            <div class="time-layer-head">
                <div>
                    <div class="focus-layer-label focus-project-label">時間レイヤー・いつ、どの順番で進めるか</div>
                    <h1>{{ $project->name }}</h1>
                    <p>ROADMAP・取り組み・TASKを、ひとつの時間軸で確認します。</p>
                </div>
                <div class="time-legend">
                    <span><i class="is-roadmap"></i>ロードマップ</span><span><i class="is-improvement"></i>取り組み</span><span><i class="is-task"></i>タスク</span><span><i class="is-inferred"></i>配下から自動算出</span><span><i class="is-overdue"></i>進行中かつ期限超過</span><span><i class="is-reached"></i>実際の完了・到達日</span>
                    <label><input id="time-today-toggle" type="checkbox" @checked($includeTodayInTimeline)>今日を時間軸に含める</label>
                </div>
            </div>
            <div class="time-chart-scroll">
                <div class="time-chart" data-axis-start="{{ $axisStart->toDateString() }}" data-axis-end="{{ $axisEnd->toDateString() }}" style="--time-grid:{{ $axisStep / $axisDays * 100 }}%; --today-left:{{ $todayLeft }}%;">
                    <div class="time-axis">
                        <div class="time-axis-label"><strong>{{ $axisDays <= 45 ? '日表示' : '週表示' }}</strong><div class="meta">{{ $includeTodayInTimeline ? '赤線は今日' : '予定期間を表示' }}</div></div>
                        <div class="time-axis-track">
                            @if ($includeTodayInTimeline)<span class="time-today"></span>@endif
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
                        <div class="time-row is-{{ $row['type'] }} {{ $row['schedule_issue'] ? 'is-schedule-'.$row['schedule_issue'] : '' }}" data-time-row-title="{{ $row['title'] }}">
                            <div class="time-row-label"><span class="time-row-dot"></span><strong title="{{ $row['title'] }}">{{ $row['title'] }}</strong>@if ($row['status_label'] ?? null)<span class="time-row-status">{{ $row['status_label'] }}</span>@endif</div>
                            <div class="time-row-track">
                                @if ($includeTodayInTimeline)<span class="time-today"></span>@endif
                                @if ($barStart && $barEnd)
                                    <span class="time-bar is-{{ $row['type'] }} {{ $row['inferred'] ? 'is-inferred' : '' }} {{ $row['overdue'] ? 'is-overdue' : '' }} {{ $row['editable'] ? 'is-editable' : '' }}" data-bar-start="{{ $barStart->toDateString() }}" data-bar-end="{{ $barEnd->toDateString() }}" @if($row['editable']) data-schedule-url="{{ $row['update_url'] }}" data-entity-type="{{ $row['type'] }}" @endif style="--bar-left:{{ $barLeft }}%; --bar-width:{{ $barWidth }}%;" title="{{ ($row['status_label'] ?? null) ? $row['status_label'].' / ' : '' }}{{ $barStart->format('Y/m/d') }}〜{{ $barEnd->format('Y/m/d') }}{{ $row['overdue'] ? ' / 期限超過' : '' }}">
                                        @if ($row['editable'] && $row['type'] !== 'task')<i class="time-resize-handle is-start" data-resize="start" title="開始日を変更"></i>@endif
                                        @if ($row['editable'])<i class="time-resize-handle is-end" data-resize="end" title="{{ $row['type'] === 'task' ? '期限を変更' : '終了日を変更' }}"></i>@endif
                                    </span>
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
            const chart = document.querySelector('.time-chart');
            if (!chart) return;
            const axisStart = new Date(`${chart.dataset.axisStart}T00:00:00`);
            const axisEnd = new Date(`${chart.dataset.axisEnd}T00:00:00`);
            const axisDays = Math.max(1, Math.round((axisEnd - axisStart) / 86400000));
            const csrf = @json(csrf_token());
            const parseDate = value => new Date(`${value}T00:00:00`);
            const formatDate = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
            const addDays = (date, days) => { const next = new Date(date); next.setDate(next.getDate() + days); return next; };
            const notify = (message, error = false) => {
                document.querySelector('.time-save-status')?.remove();
                const status = document.createElement('div');
                status.className = `time-save-status${error ? ' is-error' : ''}`;
                status.textContent = message;
                document.body.append(status);
                setTimeout(() => status.remove(), 5000);
            };

            document.querySelectorAll('.time-bar.is-editable:not(.is-task)').forEach(bar => {
                bar.addEventListener('pointerdown', event => {
                    if (event.target.closest('.time-resize-handle')) return;
                    event.preventDefault();
                    const track = bar.closest('.time-row-track');
                    const originalStart = parseDate(bar.dataset.barStart);
                    const originalEnd = parseDate(bar.dataset.barEnd);
                    const originX = event.clientX;
                    let dayDelta = 0;
                    bar.classList.add('is-dragging');
                    bar.setPointerCapture(event.pointerId);

                    const move = moveEvent => {
                        dayDelta = Math.round((moveEvent.clientX - originX) / track.clientWidth * axisDays);
                        const nextStart = addDays(originalStart, dayDelta);
                        const nextEnd = addDays(originalEnd, dayDelta);
                        const left = (nextStart - axisStart) / 86400000 / axisDays * 100;
                        bar.style.setProperty('--bar-left', `${left}%`);
                        bar.title = `${formatDate(nextStart)}〜${formatDate(nextEnd)}`;
                    };

                    const finish = async () => {
                        bar.removeEventListener('pointermove', move);
                        bar.removeEventListener('pointerup', finish);
                        bar.removeEventListener('pointercancel', cancel);
                        bar.classList.remove('is-dragging');
                        if (dayDelta === 0) return;
                        const nextStart = addDays(originalStart, dayDelta);
                        const nextEnd = addDays(originalEnd, dayDelta);
                        try {
                            const response = await fetch(bar.dataset.scheduleUrl, {
                                method: 'PATCH',
                                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                                body: JSON.stringify({
                                    start_date: formatDate(nextStart),
                                    end_date: formatDate(nextEnd),
                                    cascade_move: true,
                                }),
                            });
                            const body = await response.json();
                            if (!response.ok) {
                                const message = Object.values(body.errors || {}).flat()[0] || body.message || '日程を保存できませんでした。';
                                throw new Error(message);
                            }
                            notify('親要素と配下の日程をまとめて移動しました。');
                            window.location.reload();
                        } catch (error) {
                            bar.style.removeProperty('--bar-left');
                            bar.title = `${formatDate(originalStart)}〜${formatDate(originalEnd)}`;
                            notify(error.message, true);
                        }
                    };
                    const cancel = () => {
                        bar.removeEventListener('pointermove', move);
                        bar.removeEventListener('pointerup', finish);
                        bar.removeEventListener('pointercancel', cancel);
                        bar.classList.remove('is-dragging');
                        bar.style.removeProperty('--bar-left');
                    };
                    bar.addEventListener('pointermove', move);
                    bar.addEventListener('pointerup', finish);
                    bar.addEventListener('pointercancel', cancel);
                });
            });

            document.querySelectorAll('.time-resize-handle').forEach(handle => {
                handle.addEventListener('pointerdown', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    const bar = handle.closest('.time-bar');
                    const track = bar.closest('.time-row-track');
                    const side = handle.dataset.resize;
                    const originalStart = parseDate(bar.dataset.barStart);
                    const originalEnd = parseDate(bar.dataset.barEnd);
                    const originX = event.clientX;
                    let nextStart = new Date(originalStart);
                    let nextEnd = new Date(originalEnd);
                    bar.classList.add('is-dragging');
                    handle.setPointerCapture(event.pointerId);

                    const move = moveEvent => {
                        const dayDelta = Math.round((moveEvent.clientX - originX) / track.clientWidth * axisDays);
                        if (side === 'start') {
                            nextStart = addDays(originalStart, dayDelta);
                            if (nextStart > nextEnd) nextStart = new Date(nextEnd);
                        } else {
                            nextEnd = addDays(originalEnd, dayDelta);
                            if (nextEnd < nextStart) nextEnd = new Date(nextStart);
                        }
                        const left = Math.max(0, Math.min(100, (nextStart - axisStart) / 86400000 / axisDays * 100));
                        const width = Math.max(.8, (nextEnd - nextStart) / 86400000 / axisDays * 100);
                        bar.style.setProperty('--bar-left', `${left}%`);
                        bar.style.setProperty('--bar-width', `${width}%`);
                        bar.title = `${formatDate(nextStart)}〜${formatDate(nextEnd)}`;
                    };

                    const finish = async () => {
                        handle.removeEventListener('pointermove', move);
                        handle.removeEventListener('pointerup', finish);
                        handle.removeEventListener('pointercancel', cancel);
                        bar.classList.remove('is-dragging');
                        try {
                            const response = await fetch(bar.dataset.scheduleUrl, {
                                method: 'PATCH',
                                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                                body: JSON.stringify({
                                    start_date: formatDate(nextStart),
                                    end_date: formatDate(nextEnd),
                                    cascade_children: bar.dataset.entityType !== 'task',
                                    cascade_anchor: side,
                                }),
                            });
                            const body = await response.json();
                            if (!response.ok) {
                                const message = Object.values(body.errors || {}).flat()[0] || body.message || '日程を保存できませんでした。';
                                throw new Error(message);
                            }
                            notify('日程を更新しました。整合性を再確認しています。');
                            window.location.reload();
                        } catch (error) {
                            bar.style.removeProperty('--bar-left');
                            bar.style.removeProperty('--bar-width');
                            bar.title = `${formatDate(originalStart)}〜${formatDate(originalEnd)}`;
                            notify(error.message, true);
                        }
                    };
                    const cancel = () => {
                        handle.removeEventListener('pointermove', move);
                        handle.removeEventListener('pointerup', finish);
                        handle.removeEventListener('pointercancel', cancel);
                        bar.classList.remove('is-dragging');
                        bar.style.removeProperty('--bar-left');
                        bar.style.removeProperty('--bar-width');
                    };
                    handle.addEventListener('pointermove', move);
                    handle.addEventListener('pointerup', finish);
                    handle.addEventListener('pointercancel', cancel);
                });
            });
        })();

        (() => {
            const toggle = document.getElementById('time-today-toggle');
            if (!toggle) return;
            const storageKey = 'rise-gate-os-include-today-in-timeline';
            const saved = localStorage.getItem(storageKey);
            const url = new URL(window.location.href);
            if (!url.searchParams.has('include_today') && saved !== null && saved !== String(toggle.checked)) {
                url.searchParams.set('include_today', saved === 'true' ? '1' : '0');
                window.location.replace(url);
                return;
            }
            toggle.addEventListener('change', () => {
                localStorage.setItem(storageKey, String(toggle.checked));
                url.searchParams.set('include_today', toggle.checked ? '1' : '0');
                window.location.assign(url);
            });
        })();

        (() => {
            const drawer = document.getElementById('ai-assistant-drawer');
            const overlay = document.querySelector('.ai-drawer-overlay');
            const triggers = document.querySelectorAll('[data-ai-drawer-open]');
            if (!drawer || !overlay) return;

            const setOpen = open => {
                drawer.classList.toggle('is-open', open);
                overlay.classList.toggle('is-open', open);
                drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
                triggers.forEach(trigger => trigger.setAttribute('aria-expanded', open ? 'true' : 'false'));
                document.body.style.overflow = open ? 'hidden' : '';
                if (open) drawer.querySelector('input, textarea, button, a')?.focus();
            };

            triggers.forEach(trigger => trigger.addEventListener('click', () => setOpen(true)));
            document.querySelectorAll('[data-ai-drawer-close]').forEach(trigger => trigger.addEventListener('click', () => setOpen(false)));
            document.addEventListener('keydown', event => { if (event.key === 'Escape') setOpen(false); });
            if (drawer.classList.contains('is-open')) document.body.style.overflow = 'hidden';
        })();

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
