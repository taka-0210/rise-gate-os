@extends('layouts.app')

@section('title', $project->name.' | 実績')

@section('content')
<style>
    .plan-actual-switches{display:flex;gap:18px;flex-wrap:wrap}
    .plan-actual-switch{display:flex;align-items:center;gap:8px}
    .plan-actual-switch>span{font-size:12px;font-weight:900;letter-spacing:.12em;color:var(--muted)}
    .plan-actual-tabs{display:flex;gap:8px;padding:6px;border:1px solid var(--line);border-radius:12px;background:#f3f6f7;width:max-content}
    .plan-actual-tabs a{padding:10px 24px;border-radius:8px;color:var(--muted);font-weight:900;text-decoration:none}
    .plan-actual-tabs a.is-current{color:#fff;background:#0f5565}
    .actual-roadmaps{display:grid;gap:18px}
    .actual-roadmap{padding:20px;border:2px solid #9db5c0;border-radius:12px;background:#f7fafb}
    .actual-improvements{display:grid;gap:12px;margin-top:14px}
    .actual-improvement{padding:16px;border:1px solid #b9c8ce;border-radius:10px;background:#fff}
    .actual-tasks{display:grid;gap:10px;margin-top:12px}
    .actual-task{padding:14px;border-left:4px solid #0f5565;background:#f7f9fa}
    .actual-entry{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;padding:12px 0;border-top:1px solid var(--line)}
    .actual-entry:first-child{border-top:0}
    .actual-entry p{margin:4px 0}
    .actual-form{margin-top:12px;padding:14px;border:1px dashed #9db5c0;border-radius:8px;background:#fff}
    .actual-form summary{cursor:pointer;font-weight:900;color:#0f5565}
    .actual-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}
    .actual-form-grid .wide{grid-column:1/-1}
    .actual-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
    .actual-summary>div{padding:15px;border:1px solid var(--line);border-radius:9px;background:#fff}
    .actual-summary strong{display:block;font-size:26px}
    .actual-time{overflow-x:auto;padding-bottom:8px}
    .actual-time-chart{min-width:850px}
    .actual-time-axis,.actual-time-row{display:grid;grid-template-columns:260px minmax(560px,1fr);gap:14px;align-items:center}
    .actual-time-axis{padding-bottom:10px;border-bottom:1px solid var(--line)}
    .actual-time-axis-track{display:flex;justify-content:space-between;font-size:12px;color:var(--muted)}
    .actual-time-row{padding:12px 0;border-bottom:1px solid var(--line)}
    .actual-time-label strong,.actual-time-label span{display:block}
    .actual-time-track{position:relative;height:38px;border-radius:8px;background:repeating-linear-gradient(90deg,#f2f5f6 0,#f2f5f6 calc(12.5% - 1px),#dce3e6 calc(12.5% - 1px),#dce3e6 12.5%)}
    .actual-time-bar{position:absolute;top:7px;height:24px;min-width:12px;border-radius:999px;background:#0f5565;box-shadow:0 4px 10px rgba(15,85,101,.18)}
    .actual-time-bar.is-unplanned{background:#ec5d3b}
    @media(max-width:760px){
        .actual-form-grid,.actual-summary{grid-template-columns:1fr}
        .actual-form-grid .wide{grid-column:auto}
        .actual-entry{grid-template-columns:1fr}
        .plan-actual-tabs{width:100%}
        .plan-actual-tabs a{flex:1;text-align:center}
    }
</style>

@php
    $formatHours = function (?int $minutes): string {
        if ($minutes === null) return '未入力';
        $hours = $minutes / 60;
        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.').'時間';
    };
@endphp

<section class="stack" style="max-width:1440px;margin:0 auto;">
    <div class="actions" style="justify-content:space-between;align-items:center;">
        <div>
            <div class="eyebrow">PROJECT / 予定と実績</div>
            <h1>{{ $project->name }}</h1>
            <p>同じRoadmap・取り組み・Taskを基準に、実際に行った作業を記録します。</p>
        </div>
        <a class="button secondary" href="{{ route('projects.index') }}">PROJECT一覧へ</a>
    </div>

    <div class="plan-actual-switches">
        <div class="plan-actual-switch">
            <span>対象</span>
            <nav class="plan-actual-tabs" aria-label="予定と実績の切り替え">
                <a href="{{ route('projects.show', ['project' => $project, 'view' => $displayView === 'time' ? 'time' : null]) }}">予定</a>
                <a class="is-current" href="{{ route('projects.actuals.index', ['project' => $project, 'view' => $displayView]) }}" aria-current="page">実績</a>
            </nav>
        </div>
        <div class="plan-actual-switch">
            <span>表示</span>
            <nav class="plan-actual-tabs" aria-label="表示形式の切り替え">
                <a class="{{ $displayView === 'focus' ? 'is-current' : '' }}" href="{{ route('projects.actuals.index', $project) }}">フォーカス</a>
                <a class="{{ $displayView === 'time' ? 'is-current' : '' }}" href="{{ route('projects.actuals.index', ['project' => $project, 'view' => 'time']) }}">時間</a>
            </nav>
        </div>
    </div>

    <div class="card stack">
        <div class="eyebrow">実績の扱い</div>
        <h2>予定は変えず、事実を同じ仕事へ紐づけます。</h2>
        <p>予定に存在しない作業は「計画外の実績」として登録できます。実績を登録しても、予定日・予定工数・計画版は書き換わりません。</p>
        <div class="actual-summary">
            <div><strong>{{ $project->roadmaps->count() }}</strong><span>Roadmap</span></div>
            <div><strong>{{ $actualCount }}</strong><span>実績記録</span></div>
            <div><strong>{{ $formatHours($actualEffortMinutes) }}</strong><span>登録済み実工数</span></div>
        </div>
    </div>

    @if($canEdit)
        <details class="card actual-form" @if($errors->any() && !old('task_public_id')) open @endif>
            <summary>＋ 計画外の実績を登録</summary>
            <form method="POST" action="{{ route('projects.actuals.store', $project) }}" class="actual-form-grid">
                @csrf
                <label class="wide">作業名<input name="title" value="{{ old('title') }}" required></label>
                <label>実開始日<input type="date" name="actual_started_at" value="{{ old('actual_started_at') }}"></label>
                <label>実完了日<input type="date" name="actual_completed_at" value="{{ old('actual_completed_at') }}"></label>
                <label>実工数（時間）<input type="number" name="effort_hours" min="0" max="99999" step="0.25" value="{{ old('effort_hours') }}"></label>
                <label>状態<select name="status"><option value="recorded">記録</option><option value="confirmed">確定</option></select></label>
                <label class="wide">実際に行った内容<textarea name="description" rows="4">{{ old('description') }}</textarea></label>
                <label class="wide">成果・結果<textarea name="result" rows="3">{{ old('result') }}</textarea></label>
                <div class="wide"><button type="submit">計画外の実績を登録する</button></div>
            </form>
        </details>
    @endif

    @if($displayView === 'time')
        <section class="card stack">
            <div>
                <div class="eyebrow">ACTUAL TIMELINE</div>
                <h2>実績の時間表示</h2>
                <p>実開始日・実完了日を基準に表示します。オレンジは計画外の実績です。</p>
            </div>
            @if($timelineRows->isEmpty())
                <p class="meta">日付が登録された実績はまだありません。</p>
            @else
                <div class="actual-time">
                    <div class="actual-time-chart">
                        <div class="actual-time-axis">
                            <strong>実績</strong>
                            <div class="actual-time-axis-track">
                                <span>{{ $axisStart?->format('Y/m/d') }}</span>
                                <span>{{ $axisEnd?->format('Y/m/d') }}</span>
                            </div>
                        </div>
                        @foreach($timelineRows as $row)
                            @php($actual = $row['actual'])
                            <div class="actual-time-row">
                                <div class="actual-time-label">
                                    <strong>{{ $actual->title }}</strong>
                                    <span class="meta">{{ $actual->related_entity_type === 'task' ? 'Task実績' : '計画外' }}・{{ $formatHours($actual->effort_minutes) }}</span>
                                </div>
                                <div class="actual-time-track">
                                    <span class="actual-time-bar {{ $actual->related_entity_type === 'task' ? '' : 'is-unplanned' }}"
                                          style="left:{{ $row['left'] }}%;width:{{ $row['width'] }}%"
                                          title="{{ $row['start']->format('Y/m/d') }}〜{{ $row['end']->format('Y/m/d') }}"></span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    @else
    @if($unplannedActuals->isNotEmpty())
        <section class="card stack">
            <div><div class="eyebrow">UNPLANNED ACTUALS</div><h2>計画外の実績</h2></div>
            @foreach($unplannedActuals as $actual)
                @include('projects.actuals._entry', compact('actual', 'project', 'canEdit', 'formatHours'))
            @endforeach
        </section>
    @endif

    <div class="actual-roadmaps">
        @forelse($project->roadmaps as $roadmap)
            <section class="actual-roadmap">
                <div class="eyebrow">ROADMAP</div>
                <h2>{{ $roadmap->title }}</h2>
                <div class="actual-improvements">
                    @forelse($roadmap->improvements as $improvement)
                        <article class="actual-improvement">
                            <div class="eyebrow">取り組み</div>
                            <h3>{{ $improvement->title }}</h3>
                            <div class="actual-tasks">
                                @forelse($improvement->tasks as $task)
                                    @php($taskActuals = $actualsByTask->get($task->public_id, collect()))
                                    <article class="actual-task">
                                        <div class="eyebrow">TASK</div>
                                        <h3>{{ $task->title }}</h3>
                                        <p class="meta">
                                            予定：{{ $task->planned_start_date?->format('Y/m/d') ?? ($task->planned_start_day ? $task->planned_start_day.'日目' : '未設定') }}
                                            〜 {{ $task->due_date?->format('Y/m/d') ?? ($task->due_day ? $task->due_day.'日目' : '未設定') }}
                                        </p>
                                        @forelse($taskActuals as $actual)
                                            @include('projects.actuals._entry', compact('actual', 'project', 'canEdit', 'formatHours'))
                                        @empty
                                            <p class="meta">実績はまだ登録されていません。</p>
                                        @endforelse

                                        @if($canEdit)
                                            <details class="actual-form" @if(old('task_public_id') === $task->public_id) open @endif>
                                                <summary>＋ このTaskの実績を登録</summary>
                                                <form method="POST" action="{{ route('projects.actuals.store', $project) }}" class="actual-form-grid">
                                                    @csrf
                                                    <input type="hidden" name="task_public_id" value="{{ $task->public_id }}">
                                                    <label>実開始日<input type="date" name="actual_started_at"></label>
                                                    <label>実完了日<input type="date" name="actual_completed_at"></label>
                                                    <label>実工数（時間）<input type="number" name="effort_hours" min="0" max="99999" step="0.25"></label>
                                                    <label>状態<select name="status"><option value="recorded">記録</option><option value="confirmed">確定</option></select></label>
                                                    <label class="wide">実際に行った内容<textarea name="description" rows="4"></textarea></label>
                                                    <label class="wide">成果・結果<textarea name="result" rows="3"></textarea></label>
                                                    <div class="wide"><button type="submit">実績を登録する</button></div>
                                                </form>
                                            </details>
                                        @endif
                                    </article>
                                @empty
                                    <p class="meta">Taskはありません。</p>
                                @endforelse
                            </div>
                        </article>
                    @empty
                        <p class="meta">取り組みはありません。</p>
                    @endforelse
                </div>
            </section>
        @empty
            <div class="card"><p>Roadmapはありません。</p></div>
        @endforelse
    </div>
    @endif
</section>
@endsection
