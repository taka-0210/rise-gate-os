@extends('layouts.app', ['title' => 'Projects - Rise Gate OS'])

@section('content')
    <section class="stack">
        <div class="actions" style="justify-content: space-between; align-items: flex-start;">
            <div>
                <h1>Projects</h1>
                <p>Project is where everything begins. Current Workspaceで参加しているProjectだけを表示します。</p>
            </div>
            <a class="button" href="{{ route('projects.create') }}">Create Project</a>
        </div>

        <form class="actions" method="GET" action="{{ route('projects.index') }}">
            <label for="client_id" style="margin:0;">クライアント</label>
            <select id="client_id" name="client_id" style="width:auto;" onchange="this.form.submit()">
                <option value="">すべてのクライアント</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected($selectedClientId === $client->id)>{{ $client->name }}</option>
                @endforeach
            </select>
            <label for="status" style="margin:0;">進行状況</label>
            <select id="status" name="status" style="width:auto;" onchange="this.form.submit()">
                <option value="">すべて</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <label for="priority" style="margin:0;">優先度</label>
            <select id="priority" name="priority" style="width:auto;" onchange="this.form.submit()">
                <option value="">すべて</option>
                @foreach ($priorities as $value => $label)
                    <option value="{{ $value }}" @selected($selectedPriority === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <label for="sort" style="margin:0;">並び順</label>
            <select id="sort" name="sort" style="width:auto;" onchange="this.form.submit()">
                <option value="latest" @selected($sort === 'latest')>新しい順</option>
                <option value="oldest" @selected($sort === 'oldest')>古い順</option>
                <option value="client_asc" @selected($sort === 'client_asc')>クライアント名 昇順</option>
                <option value="client_desc" @selected($sort === 'client_desc')>クライアント名 降順</option>
            </select>
            <noscript><button type="submit">並び替え</button></noscript>
            @if ($selectedClientId || $selectedStatus || $selectedPriority)
                <a href="{{ route('projects.index', ['sort' => $sort]) }}">絞り込みを解除</a>
            @endif
        </form>

        @if ($projects->isEmpty())
            <div class="panel stack">
                <h2>まだProjectがありません</h2>
                <p>最初のProjectを作成して、Rise Gate OSの業務ドメインを始めましょう。</p>
                <div class="actions">
                    <a class="button" href="{{ route('projects.create') }}">Create first Project</a>
                </div>
            </div>
        @else
            <div class="grid">
                @foreach ($projects as $project)
                    @php($sourceImprovement = $project->sourceImprovementOutput?->improvement)
                    <article class="card stack">
                        <div>
                            <div class="meta">
                                {{ $project->client?->name ?? 'クライアント未設定' }} / {{ $statuses[$project->status] ?? $project->status }} / {{ $priorities[$project->priority] ?? $project->priority }}
                            </div>
                            <h2><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></h2>
                            @if ($project->sourceImprovementOutput)
                                <div class="badge origin-project-badge">改善から生まれたProject</div>
                                @if ($sourceImprovement && Gate::allows('view', $sourceImprovement))
                                    <p class="meta">
                                        起点: <a href="{{ route('projects.improvements.show', [$sourceImprovement->project, $sourceImprovement]) }}">{{ Str::limit($sourceImprovement->title, 48) }}</a>
                                    </p>
                                @else
                                    <p class="meta">起点の改善は公開範囲により表示されません。</p>
                                @endif
                            @endif
                            @if ($project->summary)
                                <p>{{ Str::limit($project->summary, 120) }}</p>
                            @else
                                <p>概要はまだありません。</p>
                            @endif
                        </div>
                        <div class="meta">
                            @if ($project->due_date)
                                Due: {{ $project->due_date->format('Y-m-d') }}
                            @else
                                Due date not set
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            {{ $projects->links('components.pagination') }}
        @endif
    </section>
    <style>
        .origin-project-badge { margin-top: 10px; }
    </style>
@endsection


