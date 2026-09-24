@extends('layouts.app', ['title' => $project->name.' - Project'])
@section('content')
@include('project-execution._styles')
<main class="s8-shell">
    <header class="s8-hero">
        <div class="s8-toolbar"><div><div class="s8-kicker">PROJECT / {{ strtoupper($project->status) }}</div><h1>{{ $project->name }}</h1></div>@if($isExplicitMember)<div class="actions"><a class="s8-button" data-testid="ai-plan-entry" href="{{ route('project-execution.ai.index', $project) }}">AIと実行計画をつくる</a><a class="s8-button secondary" href="{{ route('project-execution.manage', $project) }}">実行を管理</a></div>@endif</div>
        <p class="s8-lead">{{ $project->purpose }}</p>
        <div class="s8-meta"><span>Owner {{ $project->owner?->name }}</span><span>{{ $project->due_date ? '期限 '.$project->due_date->format('Y.m.d') : '期限未設定' }}</span><span>{{ \App\Models\Project::visibilities()[$project->visibility] ?? $project->visibility }}</span></div>
    </header>
    <section class="s8-card"><div class="s8-kicker">EXPECTED OUTCOME</div><h2>{{ $project->expected_outcome }}</h2></section>
    @if($project->tasks->isNotEmpty())
        <section><div class="s8-kicker">DIRECT ACTION</div><h2>Projectから直接進める</h2>@foreach($project->tasks as $action)@include('project-execution.partials.action', ['action'=>$action])@endforeach</section>
    @endif
    @foreach($project->roadmaps as $roadmap)
        <section class="s8-card"><div class="s8-meta"><span class="s8-chip">ROADMAP</span><span>{{ $roadmap->status }}</span></div><h2>{{ $roadmap->title }}</h2><p>{{ $roadmap->purpose }}</p>
            @foreach($roadmap->improvements as $theme)<div style="margin-top:24px"><div class="s8-kicker">ACTION THEME</div><h3>{{ $theme->title }}</h3><p>{{ $theme->theme_description }}</p>@foreach($theme->tasks as $action)@include('project-execution.partials.action', ['action'=>$action])@endforeach</div>@endforeach
        </section>
    @endforeach
</main>
@endsection
