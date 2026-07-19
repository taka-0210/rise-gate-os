@extends('layouts.app', ['title' => 'PROJECT一覧 - Rise Gate OS'])

@section('content')
    <style>
        .project-index-page { gap:20px; margin-top:-18px; }
        .project-index-toolbar { position:sticky; top:0; z-index:20; width:100vw; margin-left:calc(50% - 50vw); padding:8px 0; border-top:1px solid var(--line); border-bottom:1px solid var(--line); background:rgba(255,255,255,.96); }
        .project-index-toolbar-inner { width:min(1040px,calc(100% - 40px)); margin:0 auto; display:flex; justify-content:space-between; align-items:center; gap:16px; }
        .project-index-context { display:flex; align-items:center; gap:12px; min-width:0; }
        .project-index-toolbar-title { color:var(--muted); font-size:13px; white-space:nowrap; }
        .project-index-path { padding:5px 8px; border:1px solid var(--line); border-radius:6px; background:var(--accent-dark); color:#fff; font-size:13px; }
        .project-index-create { padding:6px 9px; font-size:13px; white-space:nowrap; }
        .project-index-frame { padding:22px; border:2px solid #4a5660; border-radius:12px; background:#fff; }
        .project-index-head { display:grid; grid-template-columns:minmax(0,1fr) auto; align-items:start; gap:18px; margin-bottom:18px; }
        .project-index-label { display:flex; align-items:center; gap:8px; margin-bottom:7px; color:#4a5660; font-size:13px; font-weight:900; letter-spacing:.04em; }
        .project-index-label::before { content:''; width:10px; height:10px; border-radius:50%; background:currentColor; }
        .project-filters { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:18px; padding:14px; border:1px solid var(--line); border-radius:9px; background:#f8fafb; }
        .project-filters label { margin:0; font-size:12px; color:var(--muted); }
        .project-filters select { margin-top:5px; }
        .project-filter-clear { grid-column:1/-1; font-size:13px; }
        .project-focus-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
        .project-focus-card { min-width:0; padding:16px; border:2px solid #66717a; border-radius:10px; background:#fafbfc; transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease; }
        .project-focus-card:hover { transform:translateY(-2px); border-color:var(--accent-dark); box-shadow:0 10px 28px rgba(20,60,90,.10); }
        .project-focus-link { display:block; color:inherit; text-decoration:none; }
        .project-focus-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
        .project-focus-head h2 { margin:0; }
        .project-open-hint { flex:0 0 auto; padding:5px 8px; border-radius:999px; background:#fff; color:var(--accent-dark); font-size:12px; font-weight:800; }
        .project-client { margin:7px 0 0; color:var(--muted); }
        .project-summary { min-height:3em; }
        .project-counts { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:7px; margin-top:14px; }
        .project-counts div { padding:9px; border:1px solid var(--line); border-radius:7px; background:#fff; text-align:center; }
        .project-counts strong { display:block; color:var(--accent-dark); font-size:20px; }
        .origin-project-badge { margin-top:10px; }
        .schedule-badge { display:inline-flex; margin-top:9px; padding:5px 9px; border-radius:999px; font-size:12px; font-weight:900; }
        .schedule-badge.is-missing { background:#fff4d6; color:#8a5b00; }
        .schedule-badge.is-invalid { background:#fff0ed; color:#a33f2d; }
        @media (max-width:760px) {
            .project-index-page { margin-top:-10px; }
            .project-index-toolbar-inner { width:min(100% - 28px,1040px); gap:10px; }
            .project-index-context { gap:8px; }
            .project-index-frame { padding:14px; }
            .project-index-head { grid-template-columns:1fr; }
            .project-filters { grid-template-columns:1fr 1fr; }
            .project-focus-grid { grid-template-columns:1fr; }
        }
    </style>

    <section class="stack project-index-page">
        <div class="project-index-toolbar">
            <div class="project-index-toolbar-inner">
                <div class="project-index-context">
                    <div class="project-index-toolbar-title">フォーカスレイヤー</div>
                    <div class="project-index-path">PROJECT一覧</div>
                </div>
                <div class="actions">
                    <a class="button secondary project-index-create" href="{{ route('projects.schedule') }}">全体スケジュール</a>
                    <a class="button project-index-create" href="{{ route('projects.create') }}">新しいPROJECTを作成</a>
                </div>
            </div>
        </div>

        <div class="project-index-frame">
            <div class="project-index-head">
                <div>
                    <div class="project-index-label">PROJECT一覧・実現したい仕事を選ぶ</div>
                    <h1>PROJECT一覧</h1>
                    <p>取り組むPROJECTを選ぶと、その実現までの道筋に入ります。</p>
                </div>
                <div class="meta">{{ $projects->total() }} PROJECT</div>
            </div>

            <form class="project-filters" method="GET" action="{{ route('projects.index') }}">
                <label>クライアント
                    <select name="client_id" onchange="this.form.submit()">
                        <option value="">すべて</option>
                        @foreach ($clients as $client)<option value="{{ $client->id }}" @selected($selectedClientId === $client->id)>{{ $client->name }}</option>@endforeach
                    </select>
                </label>
                <label>進行状況
                    <select name="status" onchange="this.form.submit()">
                        <option value="">すべて</option>
                        @foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>@endforeach
                    </select>
                </label>
                <label>優先度
                    <select name="priority" onchange="this.form.submit()">
                        <option value="">すべて</option>
                        @foreach ($priorities as $value => $label)<option value="{{ $value }}" @selected($selectedPriority === $value)>{{ $label }}</option>@endforeach
                    </select>
                </label>
                <label>並び順
                    <select name="sort" onchange="this.form.submit()">
                        <option value="latest" @selected($sort === 'latest')>新しい順</option>
                        <option value="oldest" @selected($sort === 'oldest')>古い順</option>
                        <option value="client_asc" @selected($sort === 'client_asc')>クライアント名 昇順</option>
                        <option value="client_desc" @selected($sort === 'client_desc')>クライアント名 降順</option>
                    </select>
                </label>
                @if ($selectedClientId || $selectedStatus || $selectedPriority || $sort !== 'latest')
                    <div class="project-filter-clear"><a href="{{ route('projects.index') }}">絞り込みを解除</a></div>
                @endif
            </form>

            @if ($projects->isEmpty())
                <div class="focus-empty">条件に一致するPROJECTはありません。</div>
            @else
                <div class="project-focus-grid">
                    @foreach ($projects as $project)
                        <article class="project-focus-card">
                            <a class="project-focus-link" href="{{ route('projects.show', $project) }}">
                                <div class="project-index-label">PROJECT・何を実現するか</div>
                                <div class="project-focus-head">
                                    <h2>{{ $project->name }}</h2>
                                    <span class="project-open-hint">この中を見る</span>
                                </div>
                                <p class="project-client">{{ $project->client?->name ?? 'クライアント未設定' }} / {{ $statuses[$project->status] ?? $project->status }} / {{ $priorities[$project->priority] ?? $project->priority }}</p>
                                @php($integrity = $scheduleIntegrity[$project->id])
                                @if ($integrity['status'] !== \App\Services\ScheduleIntegrityService::STATUS_OK)
                                    <span class="schedule-badge is-{{ $integrity['status'] }}">{{ $integrity['label'] }}</span>
                                @endif
                                @php($sourceImprovement = $project->sourceImprovementOutput?->improvement)
                                @if ($project->sourceImprovementOutput)
                                    <div class="badge origin-project-badge">改善から生まれたProject</div>
                                    @if ($sourceImprovement && Gate::allows('view', $sourceImprovement))
                                        <p class="meta">起点：{{ Str::limit($sourceImprovement->title, 48) }}</p>
                                    @else
                                        <p class="meta">起点となった改善は公開範囲により表示されません。</p>
                                    @endif
                                @endif
                                <p class="project-summary">{{ Str::limit($project->summary ?: 'このPROJECTの目的や概要はまだ登録されていません。', 120) }}</p>
                                <div class="project-counts">
                                    <div><strong>{{ $project->roadmaps_count }}</strong><span>Roadmap</span></div>
                                    <div><strong>{{ $project->improvements_count }}</strong><span>取り組み</span></div>
                                    <div><strong>{{ $project->tasks_count }}</strong><span>Task</span></div>
                                </div>
                            </a>
                        </article>
                    @endforeach
                </div>
                {{ $projects->links('components.pagination') }}
            @endif
        </div>
    </section>
@endsection
