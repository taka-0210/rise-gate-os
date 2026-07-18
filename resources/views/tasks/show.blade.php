@extends('layouts.app', ['title' => $task->title])

@section('content')
    <section class="panel stack">
        <div>
            <div class="meta">Project / <a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></div>
            <h1>{{ $task->title }}</h1>
        </div>

        <div class="grid">
            <div class="card stack">
                <div class="meta">進行状況</div>
                <h2>{{ $statuses[$task->status] ?? $task->status }}</h2>
            </div>
            <div class="card stack">
                <div class="meta">優先度</div>
                <h2>{{ $priorities[$task->priority] ?? $task->priority }}</h2>
            </div>
            <div class="card stack">
                <div class="meta">担当者</div>
                <h2>{{ $task->assignee?->name ?? '未設定' }}</h2>
            </div>
        </div>

        <div class="card stack">
            <h2>説明</h2>
            <p style="white-space: pre-wrap;">{{ $task->description ?: '説明はまだありません。' }}</p>
        </div>

        <div class="actions">
            <span class="meta">期限：{{ $task->due_date?->format('Y年n月j日') ?? '未設定' }}</span>
            @if ($task->improvement)
                <a href="{{ route('projects.improvements.show', [$project, $task->improvement]) }}">元になった改善を見る</a>
            @endif
        </div>

        <div class="actions">
            @if ($canEditTask)
                <a class="button" href="{{ route('projects.tasks.edit', [$project, $task]) }}">編集する</a>
            @endif
            <a class="button secondary" href="{{ route('projects.show', $project) }}#tasks">Projectへ戻る</a>
        </div>
    </section>
@endsection
