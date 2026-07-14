@extends('layouts.app', ['title' => 'Evolution Dashboard - Rise Gate OS'])

@section('content')
    <section class="stack evolution-dashboard">
        <div class="dashboard-hero story-hero">
            <div>
                <div class="meta">Evolution Dashboard / {{ $currentWorkspace->name }}</div>
                <h1>今週、会社は{{ $weekEvolutionCount }}件進化しました。</h1>
                <p>
                    新しい改善が{{ $weekImprovementCount }}件生まれ、{{ $weekCompletedCount }}件の改善が前へ進み、{{ $weekKnowledgeCount }}件が知識として積み上がっています。
                </p>
            </div>
            <div class="hero-score">
                <span class="score-number">{{ $weekEvolutionCount }}</span>
                <span class="score-label">今週の進化</span>
            </div>
        </div>

        <aside class="os-message">
            <div class="message-mark">AI</div>
            <div>
                <div class="meta">Rise Gate OSからの一言</div>
                @if ($weekImprovementCount > $previousWeekImprovementCount)
                    <p>今週は改善の動きが先週より増えています。この流れを、次の結果記録へ育てていきましょう。</p>
                @elseif ($resultWaitingCount > 0)
                    <p>結果や影響を待っている改善があります。記録すると、会社の知識として残ります。</p>
                @elseif ($weeklyGoalRemaining > 0)
                    <p>あと{{ $weeklyGoalRemaining }}件改善すると、今週の改善目標に届きます。</p>
                @else
                    <p>今週の改善目標に届いています。次は小さな気づきを、次のImprovementへつなげましょう。</p>
                @endif
            </div>
        </aside>

        <div class="grid dashboard-grid-3">
            <article class="card stack highlight-card story-card">
                <div class="meta">進化のストーリー</div>
                <h2>今週、新しい改善が{{ $weekImprovementCount }}件生まれました。</h2>
                <p>{{ $weekProjectUpdateCount }}件のProjectが動き、{{ $weekCompletedCount }}件の改善が完了・実施済みになりました。</p>
                @if ($weeklyGoalRemaining > 0)
                    <p>あと{{ $weeklyGoalRemaining }}件改善を登録すると、今週の改善目標に届きます。</p>
                @else
                    <p>今週の改善目標に届いています。次は結果と影響を残して、知識へ育てましょう。</p>
                @endif
                <div class="progress-track" aria-label="今週の改善目標">
                    <span style="width: {{ min(100, (int) round(($weekImprovementCount / max($weeklyGoal, 1)) * 100)) }}%"></span>
                </div>
            </article>

            <article class="card stack highlight-card story-card">
                <div class="meta">今日の一歩</div>
                <h2>今日、会社は{{ $todayEvolutionCount }}つ前へ進みました。</h2>
                <p>新しい改善が{{ $todayImprovementCount }}件、完了・実施済みの改善が{{ $todayCompletedCount }}件、動いたProjectが{{ $todayProjectUpdateCount }}件あります。</p>
                <p>今日の小さな変化が、明日の改善の種になります。</p>
            </article>

            <article class="card stack highlight-card story-card">
                <div class="meta">会社の現在地</div>
                <h2>{{ $stats['improvements'] }}件の改善が、会社の中で育っています。</h2>
                <p>{{ $stats['open_improvements'] }}件は育成中、{{ $stats['completed_improvements'] }}件は完了済みです。</p>
                <div class="stat-line">
                    <span>Company {{ $stats['clients'] }}</span>
                    <span>Project {{ $stats['projects'] }}</span>
                    <span>今週追加 {{ $stats['recent_improvements'] }}</span>
                </div>
            </article>
        </div>

        <div class="dashboard-columns">
            <section class="panel stack">
                <div class="actions" style="justify-content: space-between; align-items: flex-start;">
                    <div>
                        <div class="meta">Next Growth</div>
                        <h2>次に育てる改善</h2>
                        <p>結果や次の行動を残すことで、改善は会社の知識へ育ちます。</p>
                    </div>
                    <a class="button secondary" href="{{ route('projects.index') }}">Projectを見る</a>
                </div>

                @forelse ($nextToGrow as $improvement)
                    <article class="evolution-row">
                        <div>
                            <div class="meta">{{ $improvement->project->name }} / {{ $statuses[$improvement->status] ?? $improvement->status }}</div>
                            <h3><a href="{{ route('projects.improvements.show', [$improvement->project, $improvement]) }}">{{ $improvement->title }}</a></h3>
                            <p>{{ Str::limit($improvement->problem ?: $improvement->current_state ?: $improvement->next_action ?: 'この改善は、これから育てる余白があります。', 120) }}</p>
                        </div>
                        <div class="row-aside">
                            <span>{{ $improvement->assignee?->name ?? '担当者未設定' }}</span>
                            <span>{{ $improvement->updated_at->format('Y-m-d') }}</span>
                        </div>
                    </article>
                @empty
                    <div class="soft-empty">
                        <h3>育てる改善は落ち着いています</h3>
                        <p>新しい気づきがあれば、ProjectからImprovementとして残しましょう。</p>
                    </div>
                @endforelse
            </section>

            <aside class="stack">
                <section class="card stack">
                    <div>
                        <div class="meta">My Improvements</div>
                        <h2>自分が育てている改善</h2>
                    </div>
                    @forelse ($assignedImprovements as $improvement)
                        <div class="mini-item">
                            <a href="{{ route('projects.improvements.show', [$improvement->project, $improvement]) }}">{{ $improvement->title }}</a>
                            <span>{{ $improvement->project->name }}</span>
                        </div>
                    @empty
                        <p>今、自分に担当設定された育成中の改善はありません。</p>
                    @endforelse
                </section>

                <section class="card stack">
                    <div>
                        <div class="meta">Needs Care</div>
                        <h2>確認すると進められる改善</h2>
                    </div>
                    @forelse ($stalledImprovements as $improvement)
                        <div class="mini-item care-item">
                            <a href="{{ route('projects.improvements.show', [$improvement->project, $improvement]) }}">{{ $improvement->title }}</a>
                            <span>{{ $improvement->project->name }} / 最終更新から{{ (int) $improvement->updated_at->diffInDays(now()) }}日</span>
                        </div>
                    @empty
                        <p>{{ $stalledDays }}日以上止まっている育成中の改善はありません。</p>
                    @endforelse
                </section>
            </aside>
        </div>

        <section class="panel stack">
            <div>
                <div class="meta">Projects in Motion</div>
                <h2>改善が育っているProject</h2>
                <p>Projectは改善を育てる器です。最後に生まれた改善や最近完了した改善から、Projectの動きが見えてきます。</p>
            </div>

            @if ($recentProjects->isEmpty())
                <div class="soft-empty">
                    <h3>まだ動きのあるProjectがありません</h3>
                    <p>最初のProjectを作成して、改善が育つ場所を用意しましょう。</p>
                    <div class="actions"><a class="button" href="{{ route('projects.create') }}">Projectを作成</a></div>
                </div>
            @else
                <div class="project-motion-grid">
                    @foreach ($recentProjects as $project)
                        <article class="card project-motion-card">
                            <div class="meta">{{ $project->client?->name ?? '社内Project' }} / 最終更新 {{ $project->updated_at->format('Y-m-d') }}</div>
                            <h3><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></h3>
                            @if ($project->latestImprovement)
                                <p>最後に生まれた改善: {{ Str::limit($project->latestImprovement->title, 42) }}</p>
                            @else
                                <p>このProjectには、これから改善が育つ余白があります。</p>
                            @endif
                            @if ($project->recentCompletedImprovement)
                                <p>最近完了した改善: {{ Str::limit($project->recentCompletedImprovement->title, 42) }}</p>
                            @else
                                <p>完了した改善はまだありません。最初の結果を残す準備ができます。</p>
                            @endif
                            <div class="motion-meta">
                                <span>{{ $project->improvements_count }}件の改善が育っています</span>
                                <span>育成中 {{ $project->open_improvements_count }}件</span>
                                <span>完了 {{ $project->completed_improvements_count }}件</span>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </section>

    <style>
        .evolution-dashboard h3 { margin: 0; font-size: 17px; }
        .dashboard-hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 24px;
            align-items: center;
            padding: 30px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
        }
        .story-hero { border-color: #b8d4d8; background: linear-gradient(180deg, #ffffff 0%, #f0f8f9 100%); }
        .dashboard-hero p { margin-bottom: 0; }
        .hero-score {
            min-width: 150px;
            padding: 20px;
            border: 1px solid #c9d8dc;
            border-radius: 8px;
            text-align: center;
            background: #eef7f8;
        }
        .score-number { display: block; font-size: 46px; line-height: 1; font-weight: 800; color: var(--accent-dark); }
        .score-label { display: block; margin-top: 8px; color: var(--muted); font-size: 13px; }
        .os-message {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 14px;
            align-items: start;
            padding: 18px 20px;
            border: 1px solid #d8e0e6;
            border-radius: 8px;
            background: #fff;
        }
        .os-message p { margin: 4px 0 0; }
        .message-mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 8px;
            background: #0f4c5c;
            color: #fff;
            font-weight: 800;
            font-size: 13px;
        }
        .dashboard-grid-3 { align-items: stretch; }
        .highlight-card { min-height: 210px; }
        .story-card p { margin: 0; }
        .progress-track { height: 10px; overflow: hidden; border-radius: 999px; background: #e5edf0; }
        .progress-track span { display: block; height: 100%; border-radius: inherit; background: var(--accent); }
        .stat-line { display: flex; flex-wrap: wrap; gap: 8px; color: var(--muted); font-size: 12px; }
        .stat-line span { padding: 6px 9px; border: 1px solid var(--line); border-radius: 999px; background: #f8fafb; }
        .dashboard-columns { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(280px, .8fr); gap: 18px; align-items: start; }
        .evolution-row { display: grid; grid-template-columns: minmax(0, 1fr) 150px; gap: 16px; padding: 16px 0; border-top: 1px solid var(--line); }
        .evolution-row:first-of-type { border-top: 0; }
        .evolution-row p { margin: 6px 0 0; }
        .row-aside { display: grid; gap: 6px; align-content: start; color: var(--muted); font-size: 13px; text-align: right; }
        .mini-item { display: grid; gap: 4px; padding: 12px 0; border-top: 1px solid var(--line); }
        .mini-item:first-of-type { border-top: 0; }
        .mini-item span { color: var(--muted); font-size: 13px; }
        .care-item a { color: #7a4b12; }
        .soft-empty { padding: 18px; border: 1px dashed var(--line); border-radius: 8px; background: #fbfcfd; }
        .soft-empty p { margin-bottom: 0; }
        .project-motion-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
        .project-motion-card { display: grid; gap: 10px; }
        .project-motion-card p { margin: 0; }
        .motion-meta { display: flex; flex-wrap: wrap; gap: 8px; color: var(--muted); font-size: 12px; }
        .motion-meta span { padding: 5px 8px; border: 1px solid var(--line); border-radius: 999px; background: #f8fafb; }
        @media (max-width: 900px) {
            .dashboard-hero, .dashboard-columns { grid-template-columns: 1fr; }
            .hero-score { width: 100%; }
            .evolution-row { grid-template-columns: 1fr; }
            .row-aside { text-align: left; }
            .project-motion-grid { grid-template-columns: 1fr; }
        }
    </style>
@endsection