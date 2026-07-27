@extends('layouts.app')

@section('content')
    <style>
        .handoff-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        .handoff-copy { white-space:pre-wrap; line-height:1.8; }
        .handoff-proposal { border:1px solid var(--line); border-radius:14px; padding:18px; }
        .handoff-history { display:grid; gap:12px; }
        .handoff-history details { border-top:1px solid var(--line); padding-top:12px; }
        .handoff-history summary { cursor:pointer; font-weight:700; }
        @media (max-width:760px) { .handoff-grid { grid-template-columns:1fr; } }
    </style>

    <section class="section">
        <div class="container stack">
            <div class="actions" style="justify-content:space-between;align-items:flex-start;">
                <div>
                    <div class="meta">Project / 引継ぎ</div>
                    <h1>{{ $project->name }}</h1>
                    <p>承認済みの到達点と次の開始地点を、Projectの記憶として残します。</p>
                </div>
                <a class="button secondary" href="{{ route('projects.show', $project) }}">Projectへ戻る</a>
            </div>

            @if (session('status'))
                <div class="card">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="card error">{{ $errors->first() }}</div>
            @endif

            <div class="card stack">
                <div>
                    <div class="meta">LATEST HANDOFF</div>
                    <h2>最新の引継ぎ</h2>
                    @if ($latest)
                        <p>{{ $latest->reviewed_at?->timezone('Asia/Tokyo')->format('Y年n月j日 H:i') }} 承認</p>
                    @endif
                </div>
                @if ($latest)
                    <div class="handoff-grid">
                        <section>
                            <h3>① 前回作業ではここまで</h3>
                            <div class="handoff-copy">{{ $latest->completed_work }}</div>
                        </section>
                        <section>
                            <h3>② 次回作業はここから</h3>
                            <div class="handoff-copy">{{ $latest->next_work }}</div>
                        </section>
                    </div>
                @else
                    <p>承認済みの引継ぎはまだありません。</p>
                @endif
            </div>

            @if ($pending->isNotEmpty())
                <div class="card stack">
                    <div>
                        <div class="meta">AI PROPOSALS</div>
                        <h2>承認待ち</h2>
                    </div>
                    @foreach ($pending as $proposal)
                        <article class="handoff-proposal stack">
                            <div class="handoff-grid">
                                <section>
                                    <h3>① 前回作業ではここまで</h3>
                                    <div class="handoff-copy">{{ $proposal->completed_work }}</div>
                                </section>
                                <section>
                                    <h3>② 次回作業はここから</h3>
                                    <div class="handoff-copy">{{ $proposal->next_work }}</div>
                                </section>
                            </div>
                            <div class="meta">{{ $proposal->created_at->timezone('Asia/Tokyo')->format('Y年n月j日 H:i') }} にAIから提案</div>
                            @if ($canUpdate)
                                <div class="actions">
                                    <form method="POST" action="{{ route('projects.handoffs.approve', [$project, $proposal]) }}">
                                        @csrf
                                        <button type="submit">承認して記録</button>
                                    </form>
                                    <form method="POST" action="{{ route('projects.handoffs.reject', [$project, $proposal]) }}">
                                        @csrf
                                        <button class="secondary" type="submit">見送る</button>
                                    </form>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif

            @if ($canUpdate)
                <div class="card stack">
                    <div>
                        <div class="meta">MANUAL UPDATE</div>
                        <h2>引継ぎを手動で更新</h2>
                        <p>手動保存した内容は、そのまま承認済みの最新版になります。</p>
                    </div>
                    <form method="POST" action="{{ route('projects.handoffs.store', $project) }}" class="stack">
                        @csrf
                        <div class="handoff-grid">
                            <div class="field">
                                <label for="completed_work">① 前回作業ではここまで</label>
                                <textarea id="completed_work" name="completed_work" rows="8" required>{{ old('completed_work', $latest?->completed_work) }}</textarea>
                            </div>
                            <div class="field">
                                <label for="next_work">② 次回作業はここから</label>
                                <textarea id="next_work" name="next_work" rows="8" required>{{ old('next_work', $latest?->next_work) }}</textarea>
                            </div>
                        </div>
                        <div class="actions"><button type="submit">最新版として保存</button></div>
                    </form>
                </div>
            @endif

            <div class="card stack handoff-history">
                <div>
                    <div class="meta">HISTORY</div>
                    <h2>過去の引継ぎ</h2>
                </div>
                @forelse ($history as $item)
                    <details @if ($latest?->is($item)) open @endif>
                        <summary>{{ $item->reviewed_at?->timezone('Asia/Tokyo')->format('Y年n月j日 H:i') }}・{{ $item->source === 'codex' ? 'AI提案' : '手動更新' }}</summary>
                        <div class="handoff-grid" style="margin-top:14px;">
                            <section><h3>① 前回作業ではここまで</h3><div class="handoff-copy">{{ $item->completed_work }}</div></section>
                            <section><h3>② 次回作業はここから</h3><div class="handoff-copy">{{ $item->next_work }}</div></section>
                        </div>
                    </details>
                @empty
                    <p>履歴はまだありません。</p>
                @endforelse
                {{ $history->links() }}
            </div>
        </div>
    </section>
@endsection
