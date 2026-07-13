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
                    <article class="card stack">
                        <div>
                            <div class="meta">
                                {{ $project->client?->name ?? 'Internal Project' }} / {{ $statuses[$project->status] ?? $project->status }} / {{ $priorities[$project->priority] ?? $project->priority }}
                            </div>
                            <h2><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></h2>
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

            {{ $projects->links() }}
        @endif
    </section>
@endsection

