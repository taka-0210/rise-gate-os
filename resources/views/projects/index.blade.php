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
                                {{ $project->client?->name ?? 'Internal Project' }} / {{ $statuses[$project->status] ?? $project->status }} / {{ $priorities[$project->priority] ?? $project->priority }}
                            </div>
                            <h2><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></h2>
                            @if ($project->sourceImprovementOutput)
                                <div class="badge">改善から生まれたProject</div>
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
@endsection


