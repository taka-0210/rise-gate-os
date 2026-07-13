@extends('layouts.app', ['title' => '改善 - '.$project->name])

@section('content')
    <section class="stack">
        <div class="actions" style="justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="meta">Project / 改善</div>
                <h1>改善</h1>
                <p>{{ $project->name }} で生まれた改善を、知識へ育てる場所です。</p>
            </div>
            <div class="actions">
                @if ($canCreateImprovement)
                    <a class="button" href="{{ route('projects.improvements.create', $project) }}">改善を登録</a>
                @endif
                <a class="button secondary" href="{{ route('projects.show', $project) }}#improvements">Projectへ戻る</a>
            </div>
        </div>

        @if ($improvements->isEmpty())
            <div class="panel stack">
                <h2>まだ改善がありません</h2>
                <p>現状、理想、問題、仮説、行動、結果を残すことで、Projectの経験が会社の資産になります。</p>
                @if ($canCreateImprovement)
                    <div class="actions">
                        <a class="button" href="{{ route('projects.improvements.create', $project) }}">最初の改善を登録</a>
                    </div>
                @endif
            </div>
        @else
            <div class="grid">
                @foreach ($improvements as $improvement)
                    <article class="card stack">
                        <div>
                            <div class="meta">
                                {{ $statuses[$improvement->status] ?? $improvement->status }} / {{ $visibilities[$improvement->visibility] ?? $improvement->visibility }}
                            </div>
                            <h2><a href="{{ route('projects.improvements.show', [$project, $improvement]) }}">{{ $improvement->title }}</a></h2>
                            <p>{{ Str::limit($improvement->problem ?: $improvement->current_state ?: '問題や現状はまだ記録されていません。', 140) }}</p>
                        </div>
                        <div class="meta">
                            提案者: {{ $improvement->proposer?->name ?? '未設定' }}
                        </div>
                    </article>
                @endforeach
            </div>

            {{ $improvements->links() }}
        @endif
    </section>
@endsection
