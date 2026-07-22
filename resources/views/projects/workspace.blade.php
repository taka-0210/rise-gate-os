@extends('layouts.app', ['title' => $project->name.' | 3ペイン表示'])

@section('content')
@php
    $activeTasks = $allTasks->whereNotIn('status', [\App\Models\Task::STATUS_DONE, \App\Models\Task::STATUS_ARCHIVED]);
@endphp
<style>
    .main:has(.company-workbench) { width: min(1680px, calc(100% - 24px)); padding: 18px 0 24px; }
    .company-workbench { --wb-line:#d5dde3; --wb-soft:#f3f6f8; display:grid; grid-template-rows:auto minmax(680px, calc(100vh - 150px)); border:1px solid var(--wb-line); border-radius:12px; overflow:hidden; background:#fff; box-shadow:0 16px 45px rgba(20,42,55,.09); }
    .workbench-bar { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:10px 14px; border-bottom:1px solid var(--wb-line); background:#f8fafb; }
    .workbench-bar__identity { min-width:0; display:flex; align-items:center; gap:12px; }
    .workbench-bar__identity strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .workbench-mode { display:inline-flex; align-items:center; gap:7px; color:#46606d; font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
    .workbench-mode::before { content:""; width:7px; height:7px; border-radius:50%; background:#42a69a; box-shadow:0 0 0 4px #dff3ef; }
    .workbench-grid { min-height:0; display:grid; grid-template-columns:280px minmax(420px, 1fr) 360px; }
    .workbench-pane { min-width:0; min-height:0; overflow:auto; }
    .workbench-tree { border-right:1px solid var(--wb-line); background:#f7f9fa; }
    .workbench-main { background:#fff; }
    .workbench-ai { border-left:1px solid var(--wb-line); background:#fafcfc; }
    .pane-head { position:sticky; top:0; z-index:2; padding:13px 15px; border-bottom:1px solid var(--wb-line); background:rgba(250,252,252,.96); backdrop-filter:blur(8px); }
    .pane-head strong { display:block; font-size:13px; }
    .pane-head span { color:var(--muted); font-size:11px; }
    .tree-body { padding:10px 8px 24px; }
    .tree-group { margin:5px 0 10px; }
    .tree-group__label { padding:7px 10px; color:#74838c; font-size:10px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; }
    .tree-item { width:100%; display:flex; align-items:center; gap:8px; min-height:34px; padding:7px 10px; border:0; border-radius:6px; color:#31434d; background:transparent; text-align:left; font-size:13px; font-weight:600; cursor:pointer; }
    .tree-item:hover { background:#e9eff2; }
    .tree-item.is-current { color:#0f4c5c; background:#dcebed; }
    .tree-item--child { padding-left:27px; font-size:12px; font-weight:500; }
    .tree-item--grandchild { padding-left:45px; color:#5d6b73; font-size:12px; font-weight:500; }
    .tree-icon { flex:0 0 16px; color:#78909b; text-align:center; }
    .tree-count { margin-left:auto; color:#82939c; font-size:10px; }
    .workbench-document { display:none; padding:28px clamp(22px,4vw,54px) 60px; }
    .workbench-document.is-current { display:block; }
    .document-kicker { margin-bottom:8px; color:#55828b; font-size:11px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
    .document-title { margin-bottom:8px; font-size:clamp(26px,3vw,42px); letter-spacing:-.03em; }
    .document-summary { max-width:760px; margin:0 0 26px; }
    .document-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-top:22px; }
    .document-card { padding:18px; border:1px solid var(--wb-line); border-radius:9px; background:#fff; }
    .document-card h3 { margin:0 0 8px; font-size:15px; }
    .document-card p { margin:0; font-size:13px; }
    .document-meta { display:flex; flex-wrap:wrap; gap:8px; margin:15px 0; }
    .document-list { display:grid; gap:8px; margin-top:20px; }
    .document-row { display:block; padding:13px 15px; border:1px solid var(--wb-line); border-radius:8px; color:inherit; background:#fff; }
    .document-row:hover { border-color:#8fb3b8; }
    .document-row strong { display:block; margin-bottom:4px; }
    .ai-body { display:grid; gap:14px; padding:14px; }
    .ai-context { padding:12px; border:1px solid #c9ddda; border-radius:8px; background:#edf7f5; }
    .ai-context strong { display:block; margin-bottom:4px; font-size:12px; }
    .ai-context p { margin:0; font-size:12px; line-height:1.6; }
    .ai-card { padding:14px; border:1px solid var(--wb-line); border-radius:9px; background:#fff; }
    .ai-card h2, .ai-card h3 { margin:0 0 6px; font-size:15px; }
    .ai-card p { margin:0 0 10px; font-size:12px; line-height:1.65; }
    .ai-card textarea { min-height:120px; resize:vertical; }
    .ai-card .field { gap:4px; }
    .ai-card label { margin:0; font-size:12px; }
    .ai-history { display:grid; gap:8px; }
    .ai-history article { padding:10px; border:1px solid var(--wb-line); border-radius:7px; }
    .ai-history strong { display:block; font-size:12px; }
    .ai-history .meta { font-size:10px; }
    .ai-chat-summary { display:flex; align-items:center; justify-content:space-between; gap:10px; color:#647680; font-size:10px; }
    .ai-chat-status { display:inline-flex; align-items:center; gap:6px; }
    .ai-chat-status::before { content:""; width:7px; height:7px; border-radius:50%; background:#43a690; }
    .ai-chat-status.is-off::before { background:#b37b64; }
    .ai-chat-messages { display:grid; align-content:start; gap:10px; min-height:180px; max-height:calc(100vh - 560px); overflow:auto; padding:2px; scroll-behavior:smooth; }
    .ai-chat-empty { padding:18px 12px; border:1px dashed #cbd6dc; border-radius:9px; color:#647680; text-align:center; font-size:12px; line-height:1.7; }
    .ai-message { display:grid; gap:5px; max-width:92%; }
    .ai-message--user { justify-self:end; }
    .ai-message--assistant { justify-self:start; }
    .ai-message__bubble { padding:10px 12px; border-radius:11px; white-space:pre-wrap; overflow-wrap:anywhere; font-size:12px; line-height:1.65; }
    .ai-message--user .ai-message__bubble { color:#fff; background:#155566; border-bottom-right-radius:3px; }
    .ai-message--assistant .ai-message__bubble { color:#23363f; border:1px solid #d6e0e4; background:#fff; border-bottom-left-radius:3px; }
    .ai-message__meta { color:#7d8c94; font-size:9px; }
    .ai-message--user .ai-message__meta { text-align:right; }
    .ai-message.is-pending .ai-message__bubble { color:#61737c; background:#edf2f4; }
    .ai-chat-error { padding:9px 10px; border:1px solid #dfb5ad; border-radius:7px; color:#8a4338; background:#fff6f4; font-size:11px; }
    .ai-chat-form { display:grid; gap:8px; position:sticky; bottom:0; padding-top:4px; background:#fafcfc; }
    .ai-chat-form textarea { min-height:88px; max-height:220px; resize:vertical; }
    .ai-chat-form__actions { display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .ai-chat-form__actions button { padding:9px 13px; }
    .mobile-pane-switch { display:none; }
    @media (max-width:1050px) {
        .company-workbench { grid-template-rows:auto auto minmax(620px,calc(100vh - 190px)); }
        .mobile-pane-switch { display:flex; gap:6px; padding:8px; border-bottom:1px solid var(--wb-line); background:#f8fafb; }
        .mobile-pane-switch button { flex:1; padding:8px; border:1px solid var(--wb-line); color:#425863; background:#fff; font-size:12px; }
        .mobile-pane-switch button.is-current { color:#fff; border-color:var(--accent-dark); background:var(--accent-dark); }
        .workbench-grid { display:block; }
        .workbench-pane { display:none; height:100%; border:0; }
        .workbench-pane.is-mobile-current { display:block; }
    }
    @media (max-width:600px) {
        .main:has(.company-workbench) { width:100%; padding:0; }
        .company-workbench { border-width:1px 0 0; border-radius:0; }
        .workbench-bar { align-items:flex-start; }
        .workbench-bar .actions { justify-content:flex-end; }
        .document-grid { grid-template-columns:1fr; }
    }
</style>

<section class="company-workbench" data-workbench>
    <header class="workbench-bar">
        <div class="workbench-bar__identity">
            <span class="workbench-mode">3ペイン表示</span>
            <strong>{{ $project->name }}</strong>
        </div>
        <div class="actions">
            <a class="button secondary" href="{{ route('projects.show', $project) }}">現行表示へ戻る</a>
            @if($canEditProject)<a class="button secondary" href="{{ route('projects.edit', $project) }}">Project設定</a>@endif
        </div>
    </header>

    <div class="mobile-pane-switch" aria-label="表示するペイン">
        <button type="button" data-mobile-pane="tree">構造</button>
        <button type="button" class="is-current" data-mobile-pane="main">作業</button>
        <button type="button" data-mobile-pane="ai">AI</button>
    </div>

    <div class="workbench-grid">
        <nav class="workbench-pane workbench-tree" data-pane="tree" aria-label="プロジェクト構造">
            <div class="pane-head"><strong>PROJECT TREE</strong><span>会社の仕事をたどる</span></div>
            <div class="tree-body">
                <div class="tree-group">
                    <button class="tree-item is-current" type="button" data-document="project"><span class="tree-icon">▣</span>{{ $project->name }}</button>
                </div>
                <div class="tree-group">
                    <div class="tree-group__label">Plan</div>
                    @forelse($roadmaps as $roadmap)
                        <button class="tree-item tree-item--child" type="button" data-document="roadmap-{{ $roadmap->id }}"><span class="tree-icon">◇</span>{{ $roadmap->title }}<span class="tree-count">{{ $roadmap->improvements->count() }}</span></button>
                        @foreach($roadmap->improvements as $improvement)
                            <button class="tree-item tree-item--grandchild" type="button" data-document="improvement-{{ $improvement->id }}"><span class="tree-icon">○</span>{{ $improvement->title }}</button>
                        @endforeach
                    @empty
                        <button class="tree-item tree-item--child" type="button" data-document="roadmaps"><span class="tree-icon">◇</span>ロードマップ<span class="tree-count">0</span></button>
                    @endforelse
                </div>
                <div class="tree-group">
                    <div class="tree-group__label">Work</div>
                    <button class="tree-item" type="button" data-document="improvements"><span class="tree-icon">△</span>すべての改善<span class="tree-count">{{ $allImprovements->count() }}</span></button>
                    <button class="tree-item" type="button" data-document="tasks"><span class="tree-icon">✓</span>すべてのタスク<span class="tree-count">{{ $allTasks->count() }}</span></button>
                    @if($canViewInternalNotes)<button class="tree-item" type="button" data-document="knowledge"><span class="tree-icon">▤</span>社内メモ・資料<span class="tree-count">{{ $internalNotes->count() }}</span></button>@endif
                </div>
                <div class="tree-group">
                    <div class="tree-group__label">AI</div>
                    <button class="tree-item" type="button" data-document="ai-proposals"><span class="tree-icon">✦</span>AI提案<span class="tree-count">{{ $pendingAiProposalCount }}</span></button>
                </div>
            </div>
        </nav>

        <section class="workbench-pane workbench-main is-mobile-current" data-pane="main">
            <article class="workbench-document is-current" data-document-panel="project">
                <div class="document-kicker">Project Overview</div>
                <h1 class="document-title">{{ $project->name }}</h1>
                <p class="document-summary">{{ $project->summary ?: 'プロジェクトの概要はまだ登録されていません。' }}</p>
                <div class="document-meta"><span class="badge">{{ $statuses[$project->status] ?? $project->status }}</span><span class="badge">優先度：{{ $priorities[$project->priority] ?? $project->priority }}</span><span class="badge">{{ $project->client?->name ?? 'クライアント未設定' }}</span></div>
                <div class="document-grid">
                    <section class="document-card"><h3>現在地</h3><p>{{ $project->current_state ?: '現在の状態はまだ整理されていません。' }}</p></section>
                    <section class="document-card"><h3>目指す状態</h3><p>{{ $project->desired_future_state ?: '目指す状態はまだ整理されていません。' }}</p></section>
                    <section class="document-card"><h3>計画</h3><p>ロードマップ {{ $roadmaps->count() }}件 / 改善 {{ $allImprovements->count() }}件</p></section>
                    <section class="document-card"><h3>実行</h3><p>未完了タスク {{ $activeTasks->count() }}件 / 全タスク {{ $allTasks->count() }}件</p></section>
                </div>
            </article>

            <article class="workbench-document" data-document-panel="roadmaps"><div class="document-kicker">Roadmaps</div><h1 class="document-title">ロードマップ</h1><p>プロジェクト実現までの道筋を整理します。</p></article>
            @foreach($roadmaps as $roadmap)
                <article class="workbench-document" data-document-panel="roadmap-{{ $roadmap->id }}">
                    <div class="document-kicker">Roadmap</div><h1 class="document-title">{{ $roadmap->title }}</h1><p class="document-summary">{{ $roadmap->purpose ?: 'このロードマップの目的はまだ登録されていません。' }}</p>
                    <div class="document-meta"><span class="badge">{{ $roadmapStatuses[$roadmap->status] ?? $roadmap->status }}</span><span class="badge">改善 {{ $roadmap->improvements->count() }}件</span></div>
                    <div class="document-list">@foreach($roadmap->improvements as $improvement)<button class="tree-item document-row" type="button" data-document="improvement-{{ $improvement->id }}"><strong>{{ $improvement->title }}</strong><span class="meta">{{ $improvementStatuses[$improvement->status] ?? $improvement->status }} / タスク {{ $improvement->tasks->count() }}件</span></button>@endforeach</div>
                    @if($canCreateImprovement)<div class="actions" style="margin-top:20px"><a class="button" href="{{ route('projects.improvements.create', ['project'=>$project,'roadmap'=>$roadmap->id]) }}">改善を追加</a></div>@endif
                </article>
                @foreach($roadmap->improvements as $improvement)
                    <article class="workbench-document" data-document-panel="improvement-{{ $improvement->id }}">
                        <div class="document-kicker">Improvement</div><h1 class="document-title">{{ $improvement->title }}</h1><p class="document-summary">{{ $improvement->desired_state ?: $improvement->description ?: '改善内容はまだ整理されていません。' }}</p>
                        <div class="document-meta"><span class="badge">{{ $improvementStatuses[$improvement->status] ?? $improvement->status }}</span><span class="badge">{{ $improvementVisibilities[$improvement->visibility] ?? $improvement->visibility }}</span></div>
                        <div class="document-list">@forelse($improvement->tasks as $task)<a class="document-row" href="{{ route('projects.tasks.show', [$project,$task]) }}"><strong>{{ $task->title }}</strong><span class="meta">{{ $taskStatuses[$task->status] ?? $task->status }} / {{ $task->assignee?->name ?? '担当未設定' }}</span></a>@empty<p>タスクはまだありません。</p>@endforelse</div>
                        <div class="actions" style="margin-top:20px"><a class="button secondary" href="{{ route('projects.improvements.show', [$project,$improvement]) }}">詳細を見る</a>@if($canCreateTask)<a class="button" href="{{ route('projects.tasks.create', ['project'=>$project,'improvement'=>$improvement->id]) }}">タスクを追加</a>@endif</div>
                    </article>
                @endforeach
            @endforeach

            <article class="workbench-document" data-document-panel="improvements"><div class="document-kicker">All Improvements</div><h1 class="document-title">すべての改善</h1><div class="document-list">@forelse($allImprovements as $improvement)<button class="tree-item document-row" type="button" data-document="improvement-{{ $improvement->id }}"><strong>{{ $improvement->title }}</strong><span class="meta">{{ $improvementStatuses[$improvement->status] ?? $improvement->status }}</span></button>@empty<p>改善はまだありません。</p>@endforelse</div></article>
            <article class="workbench-document" data-document-panel="tasks"><div class="document-kicker">All Tasks</div><h1 class="document-title">すべてのタスク</h1><div class="document-list">@forelse($allTasks as $task)<a class="document-row" href="{{ route('projects.tasks.show', [$project,$task]) }}"><strong>{{ $task->title }}</strong><span class="meta">{{ $taskStatuses[$task->status] ?? $task->status }} / {{ $task->assignee?->name ?? '担当未設定' }}</span></a>@empty<p>タスクはまだありません。</p>@endforelse</div></article>
            @if($canViewInternalNotes)<article class="workbench-document" data-document-panel="knowledge"><div class="document-kicker">Company Knowledge</div><h1 class="document-title">社内メモ・資料</h1><p class="document-summary">AIと共有するプロジェクト固有の背景や資料です。</p><div class="document-list">@forelse($internalNotes as $note)<div class="document-row"><strong>{{ $note->user?->name ?? 'メンバー' }}のメモ</strong><p>{{ $note->body ?: '添付資料・参照情報' }}</p><span class="meta">{{ $note->created_at->format('Y/m/d H:i') }} / 添付 {{ $note->attachments->count() }}件</span></div>@empty<p>社内メモはまだありません。</p>@endforelse</div></article>@endif
            <article class="workbench-document" data-document-panel="ai-proposals"><div class="document-kicker">AI Proposals</div><h1 class="document-title">AI提案</h1><p class="document-summary">AIが作成した変更案は、人が確認・承認するまでプロジェクトへ反映されません。</p><div class="document-list">@forelse($pendingAiProposals as $proposal)<a class="document-row" href="{{ route('projects.ai-proposals.show', [$project,$proposal]) }}"><strong>{{ $proposal->title }}</strong><span class="meta">承認待ち / {{ $proposal->created_at->format('Y/m/d H:i') }}</span></a>@empty<p>承認待ちのAI提案はありません。</p>@endforelse</div></article>
        </section>

        <aside class="workbench-pane workbench-ai" data-pane="ai" aria-label="AIパートナー">
            <div class="pane-head"><strong>AI PARTNER</strong><span>開いている仕事について相談する</span></div>
            <div class="ai-body">
                <div class="ai-context"><strong>参照中のコンテキスト</strong><p data-ai-context>{{ $project->name }} / Project Overview</p></div>
                <div class="ai-chat-summary">
                    <span class="ai-chat-status {{ (!$aiChatEnabled || !$aiChatConfigured) ? 'is-off' : '' }}">{{ $aiChatEnabled && $aiChatConfigured ? '読み取り専用AI：接続可能' : 'AIチャット：利用準備が必要' }}</span>
                    <span data-chat-cost>推定累計 ${{ number_format($aiChatEstimatedCostMicrousd / 1_000_000, 4) }}</span>
                </div>
                <section class="ai-chat-messages" data-chat-messages aria-live="polite">
                    @forelse($aiChatMessages as $chatMessage)
                        <article class="ai-message ai-message--{{ $chatMessage->role }}">
                            <div class="ai-message__bubble">{{ $chatMessage->content }}</div>
                            <div class="ai-message__meta">
                                {{ $chatMessage->created_at->format('m/d H:i') }}
                                @if($chatMessage->role === \App\Models\AiChatMessage::ROLE_ASSISTANT && $chatMessage->input_tokens !== null)
                                    ・{{ number_format($chatMessage->input_tokens + $chatMessage->output_tokens) }} tokens
                                    ・約${{ number_format($chatMessage->estimated_cost_microusd / 1_000_000, 4) }}
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="ai-chat-empty" data-chat-empty>このProjectについてAIと会話できます。<br>AIは情報を読み取りますが、データを変更することはありません。</div>
                    @endforelse
                </section>
                <div class="ai-chat-error" data-chat-error hidden></div>
                <form class="ai-chat-form" data-chat-form data-chat-url="{{ route('projects.ai-chat.messages.store', $project) }}">
                    <textarea name="content" rows="3" maxlength="4000" placeholder="このProjectについて質問する…" @disabled(!$aiChatEnabled || !$aiChatConfigured) required></textarea>
                    <input type="hidden" name="context_key" value="project" data-chat-context-key>
                    <input type="hidden" name="context_label" value="{{ $project->name }} / Project Overview" data-chat-context-label>
                    <div class="ai-chat-form__actions">
                        <span class="meta">AIは提案のみ・自動変更なし</span>
                        <button type="submit" @disabled(!$aiChatEnabled || !$aiChatConfigured)>送信</button>
                    </div>
                </form>
                @if(session('ai_request_copy_text'))
                    <section class="ai-card stack" style="border-color:#79a991;background:#f5fcf8">
                        <div><h3>AI依頼を登録しました</h3><p>次の文章をコピーしてCodexへ送ってください。</p></div>
                        <textarea id="workspace-ai-copy" rows="5" readonly>{{ session('ai_request_copy_text') }}</textarea>
                        <div class="actions"><button type="button" data-copy-request>文章をコピー</button><span class="meta" data-copy-result aria-live="polite"></span></div>
                    </section>
                @endif
                @if($errors->any())<section class="ai-card" style="border-color:#d9a5a5;background:#fff8f8"><h3>入力内容を確認してください</h3>@foreach($errors->all() as $error)<div class="error">{{ $error }}</div>@endforeach</section>@endif
                @if($pendingAiProposalCount)
                    <section class="ai-card"><h3>承認待ちの提案 {{ $pendingAiProposalCount }}件</h3><div class="ai-history">@foreach($pendingAiProposals as $proposal)<article><strong>{{ $proposal->title }}</strong><div class="meta">{{ $proposal->created_at->format('Y/m/d H:i') }}</div><a href="{{ route('projects.ai-proposals.show', [$project,$proposal]) }}">内容を確認する →</a></article>@endforeach</div></section>
                @endif
                <details class="ai-card">
                    <summary style="cursor:pointer;font-weight:700">Codexへ長い作業を依頼する</summary>
                    <div class="stack" style="margin-top:14px">
                    <div><h2>AIに依頼する</h2><p>依頼は承認待ちの提案として届きます。AIが直接データを変更することはありません。</p></div>
                    <form method="POST" action="{{ route('projects.ai-requests.store', $project) }}" enctype="multipart/form-data" class="stack">
                        @csrf
                        <div class="field"><label for="workspace_ai_title">依頼名</label><input id="workspace_ai_title" name="title" value="{{ old('title', 'このProjectの次の計画を提案して') }}" required></div>
                        <div class="field"><label for="workspace_ai_instructions">依頼内容</label><textarea id="workspace_ai_instructions" name="instructions" required placeholder="現状を読み取り、次に必要な改善とタスクを提案してください。">{{ old('instructions') }}</textarea></div>
                        <input type="hidden" name="return_to" value="workspace">
                        <div class="field"><label for="workspace_ai_files">参考資料</label><input id="workspace_ai_files" type="file" name="attachments[]" multiple></div>
                        <button type="submit">AIへ依頼を登録</button>
                    </form>
                    </div>
                </details>
                <details class="ai-card"><summary style="cursor:pointer;font-weight:700">最近のCodex依頼</summary><div class="ai-history" style="margin-top:12px">@forelse($aiRequests->take(5) as $aiRequest)<article><strong>{{ $aiRequest->title }}</strong><div class="meta">{{ $aiRequest->created_at->format('Y/m/d H:i') }} / {{ $aiRequest->status }}</div></article>@empty<p>AIへの依頼はまだありません。</p>@endforelse</div></details>
            </div>
        </aside>
    </div>
</section>

<script>
(() => {
    const workbench = document.querySelector('[data-workbench]');
    if (!workbench) return;
    const openDocument = key => {
        const panel = workbench.querySelector(`[data-document-panel="${CSS.escape(key)}"]`);
        if (!panel) return;
        workbench.querySelectorAll('[data-document-panel]').forEach(item => item.classList.toggle('is-current', item === panel));
        workbench.querySelectorAll('[data-document]').forEach(item => item.classList.toggle('is-current', item.dataset.document === key));
        const title = panel.querySelector('.document-title')?.textContent.trim() || key;
        const kicker = panel.querySelector('.document-kicker')?.textContent.trim() || '';
        const contextLabel = `${@json($project->name)} / ${kicker} / ${title}`;
        workbench.querySelector('[data-ai-context]').textContent = contextLabel;
        workbench.querySelector('[data-chat-context-key]').value = key;
        workbench.querySelector('[data-chat-context-label]').value = contextLabel;
        workbench.querySelector('[data-pane="main"]').scrollTop = 0;
        if (matchMedia('(max-width:1050px)').matches) showMobilePane('main');
    };
    const showMobilePane = name => {
        workbench.querySelectorAll('[data-pane]').forEach(pane => pane.classList.toggle('is-mobile-current', pane.dataset.pane === name));
        workbench.querySelectorAll('[data-mobile-pane]').forEach(button => button.classList.toggle('is-current', button.dataset.mobilePane === name));
    };
    workbench.addEventListener('click', event => {
        const documentButton = event.target.closest('[data-document]');
        if (documentButton) openDocument(documentButton.dataset.document);
        const paneButton = event.target.closest('[data-mobile-pane]');
        if (paneButton) showMobilePane(paneButton.dataset.mobilePane);
        if (event.target.closest('[data-copy-request]')) {
            const text = document.getElementById('workspace-ai-copy')?.value || '';
            navigator.clipboard.writeText(text).then(() => workbench.querySelector('[data-copy-result]').textContent = 'コピーしました');
        }
    });

    const chatForm = workbench.querySelector('[data-chat-form]');
    const chatMessages = workbench.querySelector('[data-chat-messages]');
    const chatError = workbench.querySelector('[data-chat-error]');
    const appendMessage = (role, content, meta = '', pending = false) => {
        workbench.querySelector('[data-chat-empty]')?.remove();
        const article = document.createElement('article');
        article.className = `ai-message ai-message--${role}${pending ? ' is-pending' : ''}`;
        const bubble = document.createElement('div');
        bubble.className = 'ai-message__bubble';
        bubble.textContent = content;
        const metadata = document.createElement('div');
        metadata.className = 'ai-message__meta';
        metadata.textContent = meta;
        article.append(bubble, metadata);
        chatMessages.append(article);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        return article;
    };
    chatMessages.scrollTop = chatMessages.scrollHeight;
    chatForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const textarea = chatForm.elements.content;
        const content = textarea.value.trim();
        if (!content) return;
        const submit = chatForm.querySelector('button[type="submit"]');
        chatError.hidden = true;
        appendMessage('user', content, '送信中');
        const pending = appendMessage('assistant', '考えています…', '', true);
        textarea.value = '';
        submit.disabled = true;
        try {
            const response = await fetch(chatForm.dataset.chatUrl, {
                method: 'POST',
                headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())},
                body: JSON.stringify({
                    content,
                    context_key: chatForm.elements.context_key.value,
                    context_label: chatForm.elements.context_label.value,
                }),
            });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message || 'AIから回答を取得できませんでした。');
            const message = body.message;
            pending.remove();
            const tokens = Number(message.input_tokens || 0) + Number(message.output_tokens || 0);
            appendMessage('assistant', message.content, `${tokens.toLocaleString()} tokens ・ 約$${Number(message.estimated_cost_usd || 0).toFixed(4)}`);
            const currentCost = Number(workbench.dataset.chatCost || @json($aiChatEstimatedCostMicrousd / 1_000_000));
            const nextCost = currentCost + Number(message.estimated_cost_usd || 0);
            workbench.dataset.chatCost = nextCost;
            workbench.querySelector('[data-chat-cost]').textContent = `推定累計 $${nextCost.toFixed(4)}`;
        } catch (error) {
            pending.remove();
            chatError.textContent = error.message;
            chatError.hidden = false;
        } finally {
            submit.disabled = false;
            textarea.focus();
        }
    });
})();
</script>
@endsection
