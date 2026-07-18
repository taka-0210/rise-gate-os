@extends('layouts.app')

@section('title', $proposal->title.' | AI提案')

@section('content')
    <section class="panel stack">
        <div>
            <div class="eyebrow">AI提案・{{ $statuses[$proposal->status] ?? $proposal->status }}</div>
            <h1>{{ $proposal->title }}</h1>
            <p>{{ $proposal->summary ?: '概要はありません。' }}</p>
        </div>

        <div class="card stack">
            <div>
                <div class="eyebrow">提案内容・承認後の仕事の構造</div>
                <h2>ロードマップ・取り組み・タスク</h2>
            </div>
            @forelse ($proposalOutline as $roadmapIndex => $roadmap)
                <section style="border-top:1px solid var(--line); padding-top:16px;">
                    <h3>ロードマップ {{ $roadmapIndex + 1 }}．{{ $roadmap['title'] }}</h3>
                    @forelse ($roadmap['improvements'] as $improvementIndex => $improvement)
                        <div style="margin-left:24px; margin-top:14px;">
                            <h4>取り組み {{ $improvementIndex + 1 }}．{{ $improvement['title'] }}</h4>
                            @if ($improvement['tasks'])
                                <ol style="margin-left:24px;">
                                    @foreach ($improvement['tasks'] as $task)
                                        <li>{{ $task['title'] }}</li>
                                    @endforeach
                                </ol>
                            @else
                                <p class="meta" style="margin-left:24px;">この提案で追加・更新・削除するタスクはありません。</p>
                            @endif
                        </div>
                    @empty
                        <p class="meta" style="margin-left:24px;">この提案で追加・更新・削除する取り組みはありません。</p>
                    @endforelse
                </section>
            @empty
                <p>この提案には、ロードマップ・取り組み・タスクの変更がありません。</p>
            @endforelse
        </div>

        <div class="actions">
            <a class="button secondary" href="{{ route('projects.ai-proposals.index', $project) }}">AI提案一覧へ</a>
            <a class="button secondary" href="{{ route('projects.show', $project) }}">Projectへ戻る</a>
        </div>

        @if ($canReview && $proposal->status === \App\Models\AiProposal::STATUS_PENDING)
            <div class="card stack">
                <h2>確認と反映</h2>
                @if ($itemCounts['invalid'] > 0)
                    <p class="error">検証エラーがあるため反映できません。提案内容を修正して再送してください。</p>
                @else
                    <p>承認すると、下記の変更を一つの処理として本データへ反映します。</p>
                @endif
                <div class="actions">
                    <form method="POST" action="{{ route('projects.ai-proposals.apply', [$project, $proposal]) }}">
                        @csrf
                        <button type="submit" @disabled($itemCounts['invalid'] > 0)>承認して反映</button>
                    </form>
                    <form method="POST" action="{{ route('projects.ai-proposals.reject', [$project, $proposal]) }}">
                        @csrf
                        <button class="secondary" type="submit">却下</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="grid">
            <div class="card"><div class="meta">ロードマップ</div><h2>{{ $itemCounts['roadmap'] }}</h2></div>
            <div class="card"><div class="meta">取り組み</div><h2>{{ $itemCounts['improvement'] }}</h2></div>
            <div class="card"><div class="meta">タスク</div><h2>{{ $itemCounts['task'] }}</h2></div>
            <div class="card"><div class="meta">状態</div><h2>{{ $statuses[$proposal->status] ?? $proposal->status }}</h2></div>
            <div class="card"><div class="meta">変更合計</div><h2>{{ $proposal->items->count() }}</h2></div>
        </div>

        <div class="card stack">
            <div>
                <div class="eyebrow">技術的な変更内容</div>
                <h2>処理の内訳</h2>
                <p>ここから下は、システムが実際に行う追加・更新・削除と検証結果です。</p>
            </div>
        <div class="grid">
            <div class="card"><div class="meta">追加</div><h2>{{ $itemCounts['create'] }}</h2></div>
            <div class="card"><div class="meta">更新</div><h2>{{ $itemCounts['update'] }}</h2></div>
            <div class="card"><div class="meta">削除</div><h2>{{ $itemCounts['delete'] }}</h2></div>
            <div class="card"><div class="meta">検証OK</div><h2>{{ $itemCounts['valid'] }}</h2></div>
            <div class="card"><div class="meta">エラー</div><h2>{{ $itemCounts['invalid'] }}</h2></div>
        </div>
        </div>

        <div class="card stack">
            <div>
                <h2>変更内容</h2>
                <p>現在は閲覧のみです。承認・反映処理は次の段階で追加します。</p>
            </div>
            @forelse ($proposal->items as $item)
                <article style="border-top:1px solid var(--line); padding-top:16px;">
                    <div class="meta">
                        {{ match ($item->operation) { 'create' => '追加', 'update' => '更新', 'delete' => '削除' } }} /
                        {{ $item->entity_type }} /
                        {{ $item->validation_status === 'valid' ? '検証OK' : ($item->validation_status === 'invalid' ? 'エラー' : '未検証') }}
                    </div>
                    <h3>{{ $item->attributes['title'] ?? $item->target_public_id ?? '変更項目' }}</h3>
                    @if ($item->reference_key || $item->parent_reference)
                        <p class="meta">参照キー: {{ $item->reference_key ?: '—' }} / 親参照: {{ $item->parent_reference ?: '—' }}</p>
                    @endif
                    <pre style="white-space:pre-wrap; overflow-wrap:anywhere;">{{ json_encode($item->attributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    @if ($item->validation_message)<div class="error">{{ $item->validation_message }}</div>@endif
                </article>
            @empty
                <p>変更明細はありません。</p>
            @endforelse
        </div>

        @if ($proposal->evidence)
            <div class="card stack">
                <h2>提案の根拠</h2>
                <pre style="white-space:pre-wrap; overflow-wrap:anywhere;">{{ json_encode($proposal->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        @endif
    </section>
@endsection
