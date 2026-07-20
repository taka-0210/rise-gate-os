@extends('layouts.app')

@section('title', $proposal->title.' | AI提案')

@section('content')
    <style>
        .proposal-outline { display:grid; gap:16px; }
        .proposal-roadmap { padding:18px; border:2px solid #4f82c4; border-radius:10px; background:#f8fbff; }
        .proposal-roadmap > h3 { margin:0 0 14px; color:#245ca6; }
        .proposal-improvements { display:grid; gap:12px; }
        .proposal-improvement { padding:16px; border:2px solid #56a27e; border-radius:9px; background:#f6fcf8; }
        .proposal-improvement > h4 { margin:0 0 12px; color:#23845c; }
        .proposal-tasks { display:grid; gap:8px; margin:0; padding:0; list-style:none; counter-reset:proposal-task; }
        .proposal-task { counter-increment:proposal-task; padding:11px 13px; border:2px solid #cc735e; border-radius:8px; background:#fff9f7; }
        .proposal-task::before { content:counter(proposal-task) '．'; margin-right:5px; color:#b5523d; font-weight:900; }
        .proposal-node-title { display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
        .proposal-operation-badge { display:inline-flex; align-items:center; padding:4px 8px; border-radius:999px; font-size:12px; font-weight:900; line-height:1; }
        .proposal-operation-badge.is-context { background:#e3e7ea; color:#59636b; }
        .proposal-operation-badge.is-create { background:#fff; color:inherit; border:1px solid currentColor; }
        .proposal-operation-badge.is-update { background:#fff3cf; color:#795c00; }
        .proposal-operation-badge.is-delete { background:#f9dddd; color:#9f3e3e; }
        .proposal-roadmap.is-context,.proposal-roadmap.is-update { border-color:#aeb7bd; background:#f3f5f6; }
        .proposal-roadmap.is-context > h3,.proposal-roadmap.is-update > h3 { color:#59636b; }
        .proposal-improvement.is-context,.proposal-improvement.is-update { border-color:#b5bdc2; background:#f7f8f8; }
        .proposal-improvement.is-context > h4,.proposal-improvement.is-update > h4 { color:#59636b; }
        .proposal-task.is-context,.proposal-task.is-update { border-color:#c1c7cb; background:#f7f8f8; color:#59636b; }
        .proposal-roadmap.is-delete,.proposal-improvement.is-delete,.proposal-task.is-delete { border-style:dashed; border-color:#bd7777; background:#faf0f0; opacity:.82; }
        .proposal-impact-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
        .proposal-impact-card { padding:16px; border:1px solid var(--line); border-radius:9px; background:#fff; }
        .proposal-impact-values { display:flex; align-items:baseline; gap:10px; margin-top:8px; }
        .proposal-impact-values strong { font-size:28px; }
        .proposal-impact-arrow { color:var(--muted); font-weight:900; }
        .proposal-impact-delta { font-weight:900; }
        .proposal-impact-delta.is-positive { color:#23845c; }
        .proposal-impact-delta.is-negative { color:#b5523d; }
        .proposal-impact-delta.is-neutral { color:var(--muted); }
        .proposal-next-action { border:2px solid #79a991; background:#f5fcf8; }
        .proposal-next-action textarea { margin:0; background:#fff; }
        .proposal-roadmap-editor { margin:12px 0 16px; }
        .proposal-roadmap-editor > summary { width:max-content; margin-left:auto; padding:8px 14px; border:1px solid #245ca6; border-radius:999px; color:#245ca6; background:#fff; font-weight:900; cursor:pointer; list-style:none; }
        .proposal-roadmap-editor > summary::-webkit-details-marker { display:none; }
        .proposal-roadmap-editor[open] > summary { margin-bottom:14px; color:#fff; background:#245ca6; }
        .proposal-roadmap-editor > form { padding:14px; border:1px solid #b8c9df; border-radius:9px; background:#fff; }
        .proposal-review-field { padding:12px; border-radius:8px; background:#f7f9fb; }
        .proposal-review-field--improvement { margin-left:18px; border-left:4px solid #56a27e; }
        .proposal-review-field--task { margin-left:36px; border-left:4px solid #cc735e; }
        .proposal-review-field__title { display:flex; gap:8px; align-items:baseline; margin-bottom:8px; }
        .proposal-review-field__inputs { display:grid; grid-template-columns:minmax(140px,.55fr) minmax(280px,1.8fr) minmax(180px,.75fr); gap:10px; align-items:end; }
        .proposal-review-field label { margin:0; font-size:13px; font-weight:800; }
        .proposal-review-field select,.proposal-review-field textarea { margin-top:5px; background:#fff; }
        @media (max-width:900px) { .proposal-review-field__inputs { grid-template-columns:1fr; } .proposal-review-field--improvement,.proposal-review-field--task { margin-left:0; } }
        @media (max-width:760px) { .proposal-impact-grid { grid-template-columns:1fr; } }
    </style>
    <section class="panel stack">
        @if ($errors->any())
            <div class="card stack" role="alert" style="border:2px solid #c65a46;background:#fff7f5;">
                <div>
                    <div class="eyebrow" style="color:#a33f2d;">反映できませんでした</div>
                    <h2>日程または提案内容を確認してください</h2>
                </div>
                <ul style="margin:0;">
                    @foreach ($errors->all() as $error)
                        @foreach (preg_split('/\R/u', $error) as $line)
                            @if (trim($line) !== '')<li>{{ $line }}</li>@endif
                        @endforeach
                    @endforeach
                </ul>
                <p class="meta">データは変更されていません。表示された内容を直したあと、もう一度承認できます。</p>
            </div>
        @endif
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
            <div class="proposal-outline">
            @forelse ($proposalOutline as $roadmapIndex => $roadmap)
                <section class="proposal-roadmap is-{{ $roadmap['operation'] }}">
                    <h3 class="proposal-node-title">
                        <span>ロードマップ {{ $roadmapIndex + 1 }}．{{ $roadmap['title'] }}</span>
                        <span class="proposal-operation-badge is-{{ $roadmap['operation'] }}">{{ match ($roadmap['operation']) { 'create' => '新設', 'update' => '既存・更新あり', 'delete' => '削除予定', default => '既存' } }}</span>
                    </h3>
                    <div class="proposal-improvements">
                    @forelse ($roadmap['improvements'] as $improvementIndex => $improvement)
                        <div class="proposal-improvement is-{{ $improvement['operation'] }}">
                            <h4 class="proposal-node-title">
                                <span>取り組み {{ $improvementIndex + 1 }}．{{ $improvement['title'] }}</span>
                                <span class="proposal-operation-badge is-{{ $improvement['operation'] }}">{{ match ($improvement['operation']) { 'create' => '新設', 'update' => '既存・更新あり', 'delete' => '削除予定', default => '既存' } }}</span>
                            </h4>
                            @if ($improvement['tasks'])
                                <ol class="proposal-tasks">
                                    @foreach ($improvement['tasks'] as $task)
                                        <li class="proposal-task is-{{ $task['operation'] }}">
                                            {{ $task['title'] }}
                                            <span class="proposal-operation-badge is-{{ $task['operation'] }}">{{ match ($task['operation']) { 'create' => '新設', 'update' => '既存・更新あり', 'delete' => '削除予定', default => '既存' } }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            @else
                                <p class="meta">この提案で追加・更新・削除するタスクはありません。</p>
                            @endif
                        </div>
                    @empty
                        <p class="meta">この提案で追加・更新・削除する取り組みはありません。</p>
                    @endforelse
                    </div>
                    @include('ai-proposals._roadmap-review-editor', ['roadmap' => $roadmap])
                </section>
            @empty
                <p>この提案には、ロードマップ・取り組み・タスクの変更がありません。</p>
            @endforelse
            </div>
        </div>

        <div class="actions">
            <a class="button secondary" href="{{ route('projects.ai-proposals.index', $project) }}">AI提案一覧へ</a>
            <a class="button secondary" href="{{ route('projects.show', $project) }}">Projectへ戻る</a>
        </div>

        @include('ai-proposals._item-review-summary')

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
                        <button type="submit" @disabled($itemCounts['invalid'] > 0 || $unresolvedReviewCount > 0)>承認して反映</button>
                    </form>
                    <form method="POST" action="{{ route('projects.ai-proposals.reject', [$project, $proposal]) }}">
                        @csrf
                        <button class="secondary" type="submit">承認待ちから外す</button>
                    </form>
                </div>
            </div>
        @endif

        @if ($proposal->status === \App\Models\AiProposal::STATUS_APPLIED)
            @php($continuationText = "RISE GATE OSのプロジェクト「{$project->name}」でAI提案「{$proposal->title}」を承認・反映しました。現在のプロジェクト計画を確認し、承認内容に基づく次の作業を開始してください。")
            <div class="card stack proposal-next-action">
                <div>
                    <div class="eyebrow">現在の状態・{{ $proposal->handed_off_at ? 'Codexへ伝達済み' : '反映済み／Codexは待機中' }}</div>
                    <h2>{{ $proposal->handed_off_at ? 'Codexへ伝えました' : 'Codexへ作業開始を伝える' }}</h2>
                    <p>承認内容はRISE GATE OSへ反映済みです。次の作業を始めるには、下の文章をCodexへ送ってください。</p>
                </div>
                <textarea id="proposal-continuation-copy-text" rows="4" readonly>{{ $continuationText }}</textarea>
                <div class="actions">
                    <button type="button" data-copy-proposal-continuation>文章をコピー</button>
                    @if (! $proposal->handed_off_at)
                        <form method="POST" action="{{ route('projects.ai-proposals.handoff', [$project, $proposal]) }}">
                            @csrf
                            <button class="secondary" type="submit">伝えた！</button>
                        </form>
                    @else
                        <span class="badge">{{ $proposal->handed_off_at->timezone(config('app.display_timezone'))->format('Y/m/d H:i') }} 伝達済み</span>
                    @endif
                    <span class="meta" data-copy-proposal-result aria-live="polite"></span>
                </div>
            </div>
        @endif

        <div class="card stack">
            <div>
                <div class="eyebrow">プロジェクト全体への影響</div>
                <h2>{{ $proposal->status === \App\Models\AiProposal::STATUS_APPLIED ? '反映前と反映後の件数' : '現状と承認後の件数' }}</h2>
            </div>
            <div class="proposal-impact-grid">
                @foreach (['roadmap' => 'ロードマップ', 'improvement' => '取り組み', 'task' => 'タスク'] as $entityType => $label)
                    @php($impact = $impactCounts[$entityType])
                    <div class="proposal-impact-card">
                        <div class="meta">{{ $label }}</div>
                        <div class="proposal-impact-values">
                            <strong>{{ $impact['before'] }}</strong>
                            <span class="proposal-impact-arrow">→</span>
                            <strong>{{ $impact['after'] }}</strong>
                            <span class="proposal-impact-delta {{ $impact['delta'] > 0 ? 'is-positive' : ($impact['delta'] < 0 ? 'is-negative' : 'is-neutral') }}">
                                （{{ $impact['delta'] > 0 ? '＋' : ($impact['delta'] < 0 ? '−' : '±') }}{{ abs($impact['delta']) }}）
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid">
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

    @if ($proposal->status === \App\Models\AiProposal::STATUS_APPLIED)
        <script>
            (() => {
                const button = document.querySelector('[data-copy-proposal-continuation]');
                const source = document.getElementById('proposal-continuation-copy-text');
                const result = document.querySelector('[data-copy-proposal-result]');
                if (!button || !source) return;

                button.addEventListener('click', async () => {
                    try {
                        await navigator.clipboard.writeText(source.value);
                    } catch (error) {
                        source.focus();
                        source.select();
                        document.execCommand('copy');
                    }
                    button.textContent = 'コピーしました';
                    if (result) result.textContent = 'Codexの会話へ貼り付けてください。';
                });
            })();
        </script>
    @endif
@endsection
