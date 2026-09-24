@extends('layouts.app', ['title' => 'Project Action - '.$company->name])
@section('content')
@include('project-execution._styles')
<main class="s8-shell">
    <header class="s8-hero"><div class="s8-kicker">PROJECT / ACTION</div><h1>会社の動きを、成果までつなぐ。</h1><p class="s8-lead">目的からActionまでを同じ流れで読み、いま進めることを共有します。</p></header>
    <div class="s8-toolbar"><div><strong>{{ $projects->count() }}</strong> Project</div><a class="s8-button" href="{{ route('project-execution.create') }}">Projectを作成</a></div>
    <section class="s8-grid" aria-label="閲覧可能なProject">
        @forelse($projects as $project)
            <a class="s8-card" href="{{ route('project-execution.show', $project) }}"><div class="s8-meta"><span class="s8-chip">{{ \App\Models\Project::executionStatuses()[$project->status] ?? $project->status }}</span><span>{{ $project->owningWorkspace?->name }}</span></div><h2>{{ $project->name }}</h2><p>{{ $project->purpose }}</p><div class="s8-meta"><span>Owner {{ $project->owner?->name }}</span><span>更新 {{ $project->updated_at?->format('Y.m.d') }}</span></div></a>
        @empty
            <div class="s8-card"><h2>まだProjectはありません</h2><p>最初のProjectを作成すると、ここから会社の実行状況を読めます。</p></div>
        @endforelse
    </section>
</main>
@endsection
