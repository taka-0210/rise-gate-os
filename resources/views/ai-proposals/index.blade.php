@extends('layouts.app')

@section('title', 'AI提案 | '.$project->name)

@section('content')
    <section class="panel stack">
        <div>
            <div class="eyebrow">AI提案・承認前の変更を確認</div>
            <h1>AI提案</h1>
            <p>{{ $project->name }}への変更案です。この段階では本データは変更されていません。</p>
        </div>

        <div class="actions">
            <a class="button secondary" href="{{ route('projects.show', $project) }}">Projectへ戻る</a>
        </div>

        @if ($proposals->isEmpty())
            <div class="card">
                <h2>承認待ちの提案はありません</h2>
                <p>AI連携APIから受信した提案がここに表示されます。</p>
            </div>
        @else
            <div class="stack">
                @foreach ($proposals as $proposal)
                    <article class="card stack">
                        <div class="actions" style="justify-content:space-between; align-items:flex-start;">
                            <div>
                                <div class="meta">{{ $statuses[$proposal->status] ?? $proposal->status }} / {{ $proposal->items_count }}件の変更</div>
                                <h2><a href="{{ route('projects.ai-proposals.show', [$project, $proposal]) }}">{{ $proposal->title }}</a></h2>
                            </div>
                            <span class="badge">{{ $proposal->source }}</span>
                        </div>
                        @if ($proposal->summary)<p>{{ $proposal->summary }}</p>@endif
                        <div class="meta">受信: {{ $proposal->created_at->format('Y-m-d H:i') }}</div>
                    </article>
                @endforeach
            </div>
            <x-pagination :paginator="$proposals" />
        @endif
    </section>
@endsection
