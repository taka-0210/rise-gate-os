@extends('layouts.app', ['title' => $improvement->title.' - 改善'])

@section('content')
    <section class="stack">
        <div class="actions" style="justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="meta">改善 / {{ $statuses[$improvement->status] ?? $improvement->status }}</div>
                <h1>{{ $improvement->title }}</h1>
                <p>Project: <a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></p>
            </div>
            @if ($canEditImprovement)<a class="button" href="{{ route('projects.improvements.edit', [$project, $improvement]) }}">改善を編集</a>@endif<a class="button secondary" href="{{ route('projects.improvements.index', $project) }}">改善一覧へ戻る</a>
        </div>

        <div class="grid">
            <div class="card">
                <h2>進行状況</h2>
                <p>{{ $statuses[$improvement->status] ?? $improvement->status }}</p>
            </div>
            <div class="card">
                <h2>公開範囲</h2>
                <p>{{ $visibilities[$improvement->visibility] ?? $improvement->visibility }}</p>
            </div>
            <div class="card">
                <h2>提案者</h2>
                <p>{{ $improvement->proposer?->name ?? '未設定' }}</p>
            </div>
        </div>

        <div class="grid">
            <div class="card">
                <h2>担当者</h2>
                <p>{{ $improvement->assignee?->name ?? '未設定' }}</p>
            </div>
            <div class="card">
                <h2>実施者</h2>
                <p>{{ $improvement->implementer?->name ?? '未実施' }}</p>
            </div>
            <div class="card">
                <h2>実施日</h2>
                <p>{{ $improvement->implemented_at?->format('Y-m-d H:i') ?? '未設定' }}</p>
            </div>
        </div>

        <div class="panel stack">
            @foreach ([
                'current_state' => '現状',
                'desired_state' => '理想の状態',
                'problem' => '問題',
                'hypothesis' => '仮説',
                'action' => '実施内容',
                'result' => '結果',
                'impact' => '影響',
                'next_action' => '次の行動',
            ] as $field => $label)
                <section class="stack" style="gap: 8px;">
                    <h2>{{ $label }}</h2>
                    <p>{{ $improvement->{$field} ?: 'まだ記録されていません。' }}</p>
                </section>
            @endforeach
        </div>
    </section>
@endsection

