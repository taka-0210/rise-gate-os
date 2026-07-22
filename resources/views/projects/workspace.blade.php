@extends('layouts.app', ['title' => $project->name.' | 3ペイン表示'])

@section('content')
@php
    $activeTasks = $allTasks->whereNotIn('status', [\App\Models\Task::STATUS_DONE, \App\Models\Task::STATUS_ARCHIVED]);
@endphp
<style>
    .main:has(.company-workbench) { width:100%; padding:0; }
    .company-workbench { --wb-line:#d5dde3; --wb-soft:#f3f6f8; height:calc(100vh - 69px); display:grid; grid-template-rows:auto minmax(0,1fr); border-block:1px solid var(--wb-line); overflow:hidden; background:#fff; }
    .workbench-bar { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:10px 14px; border-bottom:1px solid var(--wb-line); background:#f8fafb; }
    .workbench-bar__identity { min-width:0; display:flex; align-items:center; gap:12px; }
    .workbench-bar__identity strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .workbench-mode { display:inline-flex; align-items:center; gap:7px; color:#46606d; font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
    .workbench-mode::before { content:""; width:7px; height:7px; border-radius:50%; background:#42a69a; box-shadow:0 0 0 4px #dff3ef; }
    .workbench-grid { --explorer-width:300px; --ai-width:360px; position:relative; min-height:0; display:grid; grid-template-columns:var(--explorer-width) minmax(360px,1fr) var(--ai-width); }
    .workbench-pane { min-width:0; min-height:0; overflow:auto; }
    .workbench-explorer { border-right:1px solid var(--wb-line); background:#f7f9fa; overflow:hidden; }
    .workbench-tree, .workbench-files { display:none; height:100%; overflow:auto; }
    .workbench-tree.is-current, .workbench-files.is-current { display:block; }
    .explorer-tabs { display:grid; grid-template-columns:1fr 1fr; border-bottom:1px solid var(--wb-line); background:#f4f7f8; }
    .explorer-tab { padding:14px 10px; border:0; border-radius:0; color:#526771; background:transparent; font-size:14px; font-weight:800; }
    .explorer-tab.is-current { color:#173f4a; background:#fff; box-shadow:inset 0 -2px #4f8994; }
    .workbench-main { background:#fff; }
    .workbench-ai { border-left:1px solid var(--wb-line); background:#fafcfc; }
    .pane-resizer { position:absolute; z-index:8; top:0; bottom:0; width:9px; padding:0; border:0; border-radius:0; background:transparent; cursor:col-resize; transform:translateX(-50%); touch-action:none; }
    .pane-resizer::after { content:""; position:absolute; top:0; bottom:0; left:4px; width:1px; background:transparent; }
    .pane-resizer:hover::after, .pane-resizer.is-dragging::after { width:3px; background:#71a6af; }
    .pane-resizer--explorer { left:var(--explorer-width); }
    .pane-resizer--ai { left:calc(100% - var(--ai-width)); }
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
    .tree-item--grandchild { padding-left:31px; color:#5d6b73; font-size:12px; font-weight:500; }
    .tree-item--task-child { padding-left:49px; font-size:11px; font-weight:500; }
    .tree-branch[hidden] { display:none; }
    .tree-expander { transition:transform .16s ease; }
    [aria-expanded="true"] > .tree-expander { transform:rotate(90deg); }
    .tree-icon { flex:0 0 16px; color:#78909b; text-align:center; }
    .tree-count { margin-left:auto; color:#82939c; font-size:10px; }
    .tree-item--roadmap { border-left:3px solid #75a7ca; color:#294f68; background:#dfedf7; }
    .tree-item--roadmap .tree-icon { color:#4f8bb5; }
    .tree-item--roadmap:hover, .tree-item--roadmap.is-current { color:#1f465f; background:#cfe3f2; }
    .tree-item--improvement { border-left:3px solid #79b493; color:#315d45; background:#e0f1e7; }
    .tree-item--improvement .tree-icon { color:#559b73; }
    .tree-item--improvement:hover, .tree-item--improvement.is-current { color:#28533b; background:#cfe9da; }
    .tree-item--task { border-left:3px solid #cb8d89; color:#704340; background:#f5e3e2; }
    .tree-item--task .tree-icon { color:#aa6662; }
    .tree-item--task:hover, .tree-item--task.is-current { color:#613733; background:#efd2d0; }
    .file-repository { padding:11px 12px; border-bottom:1px solid var(--wb-line); color:#40545e; background:#fff; font-size:12px; font-weight:800; }
    .file-repository span { display:block; margin-top:2px; color:#7b8b93; font-size:10px; font-weight:500; }
    .file-item { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:11px; font-weight:500; }
    .file-item--nested { padding-left:27px; }
    .file-note { margin:12px 10px; padding:10px; border:1px dashed #c7d3d9; border-radius:7px; color:#6a7b84; background:#fff; font-size:10px; line-height:1.55; }
    .viewer-panel { display:none; min-height:100%; }
    .viewer-panel.is-current { display:block; }
    .browser-frame { width:100%; height:100%; min-height:calc(100vh - 126px); border:0; background:#fff; }
    .usage-card[hidden] { display:none; }
    .usage-grid { display:grid; margin-top:10px; }
    .usage-grid div { padding:10px; border-radius:7px; background:#f3f7f8; }
    .usage-grid strong, .usage-grid span { display:block; }
    .usage-grid span { color:#71818a; font-size:10px; }
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
    .inline-editor { margin-top:20px; padding:18px; border:1px solid #cbd8dd; border-radius:9px; background:#f8fafb; }
    .inline-editor[hidden] { display:none; }
    .inline-editor__grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
    .inline-editor__status { min-height:20px; margin-top:8px; color:#39705c; font-size:12px; }
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
    @media (max-width:900px) {
        .company-workbench { min-height:720px; height:calc(100vh - 69px); grid-template-rows:auto auto minmax(0,1fr); }
        .mobile-pane-switch { display:flex; gap:6px; padding:8px; border-bottom:1px solid var(--wb-line); background:#f8fafb; }
        .mobile-pane-switch button { flex:1; padding:8px; border:1px solid var(--wb-line); color:#425863; background:#fff; font-size:12px; }
        .mobile-pane-switch button.is-current { color:#fff; border-color:var(--accent-dark); background:var(--accent-dark); }
        .workbench-grid { display:block; }
        .pane-resizer { display:none; }
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
        <button type="button" data-mobile-pane="explorer">WORK / FILES</button>
        <button type="button" class="is-current" data-mobile-pane="main">表示</button>
        <button type="button" data-mobile-pane="ai">AI</button>
    </div>

    <div class="workbench-grid">
        <aside class="workbench-pane workbench-explorer" data-pane="explorer" aria-label="WORKとFILES">
            <div class="explorer-tabs">
                <button class="explorer-tab is-current" type="button" data-explorer-tab="work">WORK</button>
                <button class="explorer-tab" type="button" data-explorer-tab="files">FILES</button>
            </div>
            <nav class="workbench-tree is-current" data-explorer-panel="work" aria-label="プロジェクト構造">
                <div class="tree-body">
                    <div class="tree-group">
                        <div class="tree-group__label">Roadmaps</div>
                        @forelse($roadmaps as $roadmap)
                            <button class="tree-item tree-item--roadmap" type="button" data-document="roadmap-{{ $roadmap->id }}" data-tree-toggle="roadmap-{{ $roadmap->id }}" aria-expanded="false"><span class="tree-icon tree-expander">▸</span>{{ $roadmap->title }}<span class="tree-count">{{ $roadmap->improvements->count() }}</span></button>
                            <div class="tree-branch" data-tree-branch="roadmap-{{ $roadmap->id }}" hidden>
                                @foreach($roadmap->improvements as $improvement)
                                    <button class="tree-item tree-item--grandchild tree-item--improvement" type="button" data-document="improvement-{{ $improvement->id }}" data-tree-toggle="improvement-{{ $improvement->id }}" aria-expanded="false"><span class="tree-icon tree-expander">▸</span>{{ $improvement->title }}<span class="tree-count">{{ $improvement->tasks->count() }}</span></button>
                                    <div class="tree-branch" data-tree-branch="improvement-{{ $improvement->id }}" hidden>
                                        @foreach($improvement->tasks as $task)
                                            <button class="tree-item tree-item--task-child tree-item--task" type="button" data-document="task-{{ $task->id }}"><span class="tree-icon">✓</span>{{ $task->title }}</button>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        @empty
                            <button class="tree-item tree-item--roadmap" type="button" data-document="roadmaps"><span class="tree-icon">◇</span>ロードマップ<span class="tree-count">0</span></button>
                        @endforelse
                    </div>
                    @if($canViewInternalNotes)<div class="tree-group"><button class="tree-item" type="button" data-document="knowledge"><span class="tree-icon">▤</span>社内メモ・資料<span class="tree-count">{{ $internalNotes->count() }}</span></button></div>@endif
                    <div class="tree-group"><button class="tree-item" type="button" data-document="ai-proposals"><span class="tree-icon">✦</span>AI提案<span class="tree-count">{{ $pendingAiProposalCount }}</span></button></div>
                </div>
            </nav>

            <nav class="workbench-files" data-explorer-panel="files" aria-label="ファイル構成">
                <div class="file-repository">▣ prohit-okinawa<span>読み取り専用プレビュー</span></div>
                <div class="tree-body">
                    <button class="tree-item file-item" type="button" data-file-toggle="docs" aria-expanded="false"><span class="tree-icon tree-expander">▸</span>docs</button>
                    <div class="tree-branch" data-file-branch="docs" hidden>
                        <button class="tree-item file-item file-item--nested" type="button" data-file-name="docs/01-requirements.md" data-file-copy="Webサイトの要件定義です。"><span class="tree-icon">◇</span>01-requirements.md</button>
                        <button class="tree-item file-item file-item--nested" type="button" data-file-name="docs/04-implementation-plan.md" data-file-copy="実装計画と進め方をまとめたファイルです。"><span class="tree-icon">◇</span>04-implementation-plan.md</button>
                    </div>
                    <button class="tree-item file-item" type="button" data-file-toggle="public_html" aria-expanded="false"><span class="tree-icon tree-expander">▸</span>public_html</button>
                    <div class="tree-branch" data-file-branch="public_html" hidden>
                        <button class="tree-item file-item file-item--nested" type="button" data-file-name="public_html/index.php" data-file-copy="公開サイトのトップページです。" data-file-view="browser" data-preview-url="https://prohit-okinawa.com/"><span class="tree-icon">◇</span>index.php</button>
                        <button class="tree-item file-item file-item--nested" type="button" data-file-name="public_html/admin.php" data-file-copy="サイト運用の管理画面です。"><span class="tree-icon">◇</span>admin.php</button>
                    </div>
                    <button class="tree-item file-item" type="button" data-file-toggle="storage" aria-expanded="false"><span class="tree-icon tree-expander">▸</span>storage/content</button>
                    <div class="tree-branch" data-file-branch="storage" hidden><button class="tree-item file-item file-item--nested" type="button" data-file-name="storage/content/company.json" data-file-copy="会社情報のデータです。"><span class="tree-icon">◇</span>company.json</button></div>
                </div>
                <p class="file-note">現在は画面構成を確かめるプレビューです。次の段階でGitHubの実際のファイル一覧に切り替えます。</p>
            </nav>
        </aside>

        <button class="pane-resizer pane-resizer--explorer" type="button" data-pane-resizer="explorer" aria-label="WORKとFILESの幅を変更"></button>

        <section class="workbench-pane workbench-main is-mobile-current" data-pane="main">
            <div class="viewer-panel is-current" data-viewer-panel="document">
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
                    @if($canCreateImprovement)
                        <div class="actions" style="margin-top:20px"><button type="button" data-inline-editor-toggle="roadmap-create-{{ $roadmap->id }}">取組みを追加</button></div>
                        <form class="inline-editor stack" data-inline-editor="roadmap-create-{{ $roadmap->id }}" data-workspace-form data-reload-after-save hidden method="POST" action="{{ route('projects.improvements.store', $project) }}">
                            @csrf
                            <input type="hidden" name="roadmap_id" value="{{ $roadmap->id }}"><input type="hidden" name="visibility" value="{{ \App\Models\Improvement::VISIBILITY_INTERNAL }}">
                            <div class="field"><label for="workspace_improvement_new_title_{{ $roadmap->id }}">取組み名</label><input id="workspace_improvement_new_title_{{ $roadmap->id }}" name="title" required></div>
                            <div class="field"><label for="workspace_improvement_new_current_{{ $roadmap->id }}">現在地</label><textarea id="workspace_improvement_new_current_{{ $roadmap->id }}" name="current_state" rows="3"></textarea></div>
                            <div class="field"><label for="workspace_improvement_new_desired_{{ $roadmap->id }}">目指す状態</label><textarea id="workspace_improvement_new_desired_{{ $roadmap->id }}" name="desired_state" rows="3"></textarea></div>
                            <div class="actions"><button type="submit">追加する</button><button class="secondary" type="button" data-inline-editor-close="roadmap-create-{{ $roadmap->id }}">閉じる</button></div><div class="inline-editor__status" data-inline-editor-status></div>
                        </form>
                    @endif
                </article>
                @foreach($roadmap->improvements as $improvement)
                    <article class="workbench-document" data-document-panel="improvement-{{ $improvement->id }}">
                        <div class="document-kicker">Improvement</div><h1 class="document-title">{{ $improvement->title }}</h1><p class="document-summary">{{ $improvement->desired_state ?: $improvement->description ?: '改善内容はまだ整理されていません。' }}</p>
                        <div class="document-meta"><span class="badge">{{ $improvementStatuses[$improvement->status] ?? $improvement->status }}</span><span class="badge">{{ $improvementVisibilities[$improvement->visibility] ?? $improvement->visibility }}</span></div>
                        <div class="document-list">@forelse($improvement->tasks as $task)<button class="tree-item document-row" type="button" data-document="task-{{ $task->id }}"><strong>{{ $task->title }}</strong><span class="meta">{{ $taskStatuses[$task->status] ?? $task->status }} / {{ $task->assignee?->name ?? '担当未設定' }}</span></button>@empty<p>タスクはまだありません。</p>@endforelse</div>
                        <div class="actions" style="margin-top:20px">@can('update',$improvement)<button class="secondary" type="button" data-inline-editor-toggle="improvement-edit-{{ $improvement->id }}">ここで編集</button>@endcan @if($canCreateTask)<button type="button" data-inline-editor-toggle="task-create-{{ $improvement->id }}">タスクを追加</button>@endif</div>
                        @can('update',$improvement)
                            <form class="inline-editor stack" data-inline-editor="improvement-edit-{{ $improvement->id }}" data-workspace-form data-update-panel hidden method="POST" action="{{ route('projects.improvements.update', [$project,$improvement]) }}">
                                @csrf @method('PUT')
                                <input type="hidden" name="roadmap_id" value="{{ $improvement->roadmap_id }}"><input type="hidden" name="visibility" value="{{ $improvement->visibility }}"><input type="hidden" name="assigned_to" value="{{ $improvement->assigned_to }}"><input type="hidden" name="implemented_by" value="{{ $improvement->implemented_by }}"><input type="hidden" name="planned_effort_days" value="{{ $improvement->planned_effort_days }}"><input type="hidden" name="planned_start_date" value="{{ $improvement->planned_start_date?->format('Y-m-d') }}"><input type="hidden" name="target_date" value="{{ $improvement->target_date?->format('Y-m-d') }}"><input type="hidden" name="planned_start_day" value="{{ $improvement->planned_start_day }}"><input type="hidden" name="target_day" value="{{ $improvement->target_day }}"><input type="hidden" name="completed_at" value="{{ $improvement->completed_at?->format('Y-m-d') }}"><input type="hidden" name="implemented_at" value="{{ $improvement->implemented_at?->format('Y-m-d') }}">
                                <textarea name="problem" hidden>{{ $improvement->problem }}</textarea><textarea name="hypothesis" hidden>{{ $improvement->hypothesis }}</textarea><textarea name="action" hidden>{{ $improvement->action }}</textarea><textarea name="result" hidden>{{ $improvement->result }}</textarea><textarea name="impact" hidden>{{ $improvement->impact }}</textarea><textarea name="next_action" hidden>{{ $improvement->next_action }}</textarea>
                                <div class="field"><label for="workspace_improvement_title_{{ $improvement->id }}">取組み名</label><input id="workspace_improvement_title_{{ $improvement->id }}" name="title" value="{{ $improvement->title }}" required></div>
                                <div class="field"><label for="workspace_improvement_current_{{ $improvement->id }}">現在地</label><textarea id="workspace_improvement_current_{{ $improvement->id }}" name="current_state" rows="3">{{ $improvement->current_state }}</textarea></div>
                                <div class="field"><label for="workspace_improvement_desired_{{ $improvement->id }}">目指す状態</label><textarea id="workspace_improvement_desired_{{ $improvement->id }}" name="desired_state" rows="3">{{ $improvement->desired_state }}</textarea></div>
                                <div class="actions"><button type="submit">保存する</button><button class="secondary" type="button" data-inline-editor-close="improvement-edit-{{ $improvement->id }}">閉じる</button></div><div class="inline-editor__status" data-inline-editor-status></div>
                            </form>
                        @endcan
                        @if($canCreateTask)
                            <form class="inline-editor stack" data-inline-editor="task-create-{{ $improvement->id }}" data-workspace-form data-reload-after-save hidden method="POST" action="{{ route('projects.tasks.store', $project) }}">
                                @csrf <input type="hidden" name="improvement_id" value="{{ $improvement->id }}"><input type="hidden" name="status" value="{{ \App\Models\Task::STATUS_TODO }}"><input type="hidden" name="priority" value="{{ \App\Models\Task::PRIORITY_NORMAL }}">
                                <div class="field"><label for="workspace_task_new_title_{{ $improvement->id }}">Task名</label><input id="workspace_task_new_title_{{ $improvement->id }}" name="title" required></div>
                                <div class="field"><label for="workspace_task_new_description_{{ $improvement->id }}">説明</label><textarea id="workspace_task_new_description_{{ $improvement->id }}" name="description" rows="4"></textarea></div>
                                <div class="actions"><button type="submit">追加する</button><button class="secondary" type="button" data-inline-editor-close="task-create-{{ $improvement->id }}">閉じる</button></div><div class="inline-editor__status" data-inline-editor-status></div>
                            </form>
                        @endif
                    </article>
                @endforeach
            @endforeach

            <article class="workbench-document" data-document-panel="improvements"><div class="document-kicker">All Improvements</div><h1 class="document-title">すべての改善</h1><div class="document-list">@forelse($allImprovements as $improvement)<button class="tree-item document-row" type="button" data-document="improvement-{{ $improvement->id }}"><strong>{{ $improvement->title }}</strong><span class="meta">{{ $improvementStatuses[$improvement->status] ?? $improvement->status }}</span></button>@empty<p>改善はまだありません。</p>@endforelse</div></article>
            <article class="workbench-document" data-document-panel="tasks"><div class="document-kicker">All Tasks</div><h1 class="document-title">すべてのタスク</h1><div class="document-list">@forelse($allTasks as $task)<button class="tree-item document-row" type="button" data-document="task-{{ $task->id }}"><strong>{{ $task->title }}</strong><span class="meta">{{ $taskStatuses[$task->status] ?? $task->status }} / {{ $task->assignee?->name ?? '担当未設定' }}</span></button>@empty<p>タスクはまだありません。</p>@endforelse</div></article>
            @foreach($allTasks as $task)
                <article class="workbench-document" data-document-panel="task-{{ $task->id }}">
                    <div class="document-kicker">Task</div><h1 class="document-title">{{ $task->title }}</h1>
                    <p class="document-summary">{{ $task->description ?: 'タスクの詳細はまだ登録されていません。' }}</p>
                    <div class="document-meta"><span class="badge">{{ $taskStatuses[$task->status] ?? $task->status }}</span><span class="badge">{{ $task->assignee?->name ?? '担当未設定' }}</span>@if($task->due_date)<span class="badge">期限 {{ $task->due_date->format('Y/m/d') }}</span>@endif</div>
                    @can('update', $task)
                        <div class="actions" style="margin-top:20px"><button class="secondary" type="button" data-inline-editor-toggle="task-{{ $task->id }}">ここで編集する</button></div>
                        <form class="inline-editor stack" data-inline-editor="task-{{ $task->id }}" data-workspace-form data-update-panel hidden method="POST" action="{{ route('projects.tasks.update', [$project,$task]) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="improvement_id" value="{{ $task->improvement_id }}">
                            <input type="hidden" name="planned_start_date" value="{{ $task->planned_start_date?->format('Y-m-d') }}">
                            <input type="hidden" name="due_date" value="{{ $task->due_date?->format('Y-m-d') }}">
                            <input type="hidden" name="planned_start_day" value="{{ $task->planned_start_day }}">
                            <input type="hidden" name="due_day" value="{{ $task->due_day }}">
                            <div class="field"><label for="workspace_task_title_{{ $task->id }}">Task名</label><input id="workspace_task_title_{{ $task->id }}" name="title" value="{{ $task->title }}" required></div>
                            <div class="field"><label for="workspace_task_description_{{ $task->id }}">説明</label><textarea id="workspace_task_description_{{ $task->id }}" name="description" rows="4">{{ $task->description }}</textarea></div>
                            <div class="inline-editor__grid">
                                <div class="field"><label for="workspace_task_status_{{ $task->id }}">進行状況</label><select id="workspace_task_status_{{ $task->id }}" name="status">@foreach($taskStatuses as $value=>$label)<option value="{{ $value }}" @selected($task->status===$value)>{{ $label }}</option>@endforeach</select></div>
                                <div class="field"><label for="workspace_task_priority_{{ $task->id }}">優先度</label><select id="workspace_task_priority_{{ $task->id }}" name="priority">@foreach($taskPriorities as $value=>$label)<option value="{{ $value }}" @selected($task->priority===$value)>{{ $label }}</option>@endforeach</select></div>
                                <div class="field"><label for="workspace_task_assignee_{{ $task->id }}">担当者</label><select id="workspace_task_assignee_{{ $task->id }}" name="assigned_to"><option value="">未設定</option>@foreach($assignableUsers as $user)<option value="{{ $user->id }}" @selected($task->assigned_to===$user->id)>{{ $user->name }}</option>@endforeach</select></div>
                            </div>
                            <div class="actions"><button type="submit">保存する</button><button class="secondary" type="button" data-inline-editor-close="task-{{ $task->id }}">閉じる</button></div>
                            <div class="inline-editor__status" data-inline-editor-status aria-live="polite"></div>
                        </form>
                    @endcan
                </article>
            @endforeach
            @if($canViewInternalNotes)<article class="workbench-document" data-document-panel="knowledge"><div class="document-kicker">Company Knowledge</div><h1 class="document-title">社内メモ・資料</h1><p class="document-summary">AIと共有するプロジェクト固有の背景や資料です。</p><div class="document-list">@forelse($internalNotes as $note)<div class="document-row"><strong>{{ $note->user?->name ?? 'メンバー' }}のメモ</strong><p>{{ $note->body ?: '添付資料・参照情報' }}</p><span class="meta">{{ $note->created_at->format('Y/m/d H:i') }} / 添付 {{ $note->attachments->count() }}件</span></div>@empty<p>社内メモはまだありません。</p>@endforelse</div></article>@endif
            <article class="workbench-document" data-document-panel="ai-proposals"><div class="document-kicker">AI Proposals</div><h1 class="document-title">AI提案</h1><p class="document-summary">AIが作成した変更案は、人が確認・承認するまでプロジェクトへ反映されません。</p><div class="document-list">@forelse($pendingAiProposals as $proposal)<a class="document-row" href="{{ route('projects.ai-proposals.show', [$project,$proposal]) }}"><strong>{{ $proposal->title }}</strong><span class="meta">承認待ち / {{ $proposal->created_at->format('Y/m/d H:i') }}</span></a>@empty<p>承認待ちのAI提案はありません。</p>@endforelse</div></article>
            </div>
            <div class="viewer-panel" data-viewer-panel="file">
                <article class="workbench-document is-current" style="display:block"><div class="document-kicker">File Preview</div><h1 class="document-title" data-file-title>ファイルを選択</h1><p class="document-summary" data-file-content>左のFILESからファイルを開くと、ここに内容を表示します。</p></article>
            </div>
            <div class="viewer-panel" data-viewer-panel="browser">
                <iframe class="browser-frame" data-browser-frame title="ブラウザプレビュー" hidden></iframe>
            </div>
        </section>

        <button class="pane-resizer pane-resizer--ai" type="button" data-pane-resizer="ai" aria-label="AIパートナーの幅を変更"></button>

        <aside class="workbench-pane workbench-ai" data-pane="ai" aria-label="AIパートナー">
            <div class="pane-head"><strong>AI パートナー</strong></div>
            <div class="ai-body">
                <div class="ai-context"><strong>参照中のコンテキスト</strong><p data-ai-context>{{ $project->name }} / Project Overview</p></div>
                <div class="ai-chat-summary">
                    <span class="ai-chat-status {{ (!$aiChatEnabled || !$aiChatConfigured) ? 'is-off' : '' }}">{{ $aiChatEnabled && $aiChatConfigured ? '読み取り専用AI：接続可能' : 'AIチャット：利用準備が必要' }}</span>
                    <button class="button secondary" type="button" data-usage-toggle>利用料をチェックする</button>
                </div>
                <section class="ai-card usage-card" data-usage-card hidden>
                    <h3>この会話の利用状況</h3>
                    <div class="usage-grid"><div><span>利用トークン</span><strong data-chat-tokens>{{ number_format($aiChatMessages->sum(fn ($message) => ($message->input_tokens ?? 0) + ($message->output_tokens ?? 0))) }} tokens</strong></div></div>
                    <p class="meta" style="margin-top:8px">COMPANY OS内のこの会話で使用したトークン数です。</p>
                </section>
                <section class="ai-chat-messages" data-chat-messages aria-live="polite">
                    @forelse($aiChatMessages as $chatMessage)
                        <article class="ai-message ai-message--{{ $chatMessage->role }}">
                            <div class="ai-message__bubble">{{ $chatMessage->content }}</div>
                            <div class="ai-message__meta">
                                {{ $chatMessage->created_at->format('m/d H:i') }}
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
                        <div><h3>AI依頼を登録しました</h3><p>次の文章をコピーしてAIへ送ってください。</p></div>
                        <textarea id="workspace-ai-copy" rows="5" readonly>{{ session('ai_request_copy_text') }}</textarea>
                        <div class="actions"><button type="button" data-copy-request>文章をコピー</button><span class="meta" data-copy-result aria-live="polite"></span></div>
                    </section>
                @endif
                @if($errors->any())<section class="ai-card" style="border-color:#d9a5a5;background:#fff8f8"><h3>入力内容を確認してください</h3>@foreach($errors->all() as $error)<div class="error">{{ $error }}</div>@endforeach</section>@endif
                @if($pendingAiProposalCount)
                    <section class="ai-card"><h3>承認待ちの提案 {{ $pendingAiProposalCount }}件</h3><div class="ai-history">@foreach($pendingAiProposals as $proposal)<article><strong>{{ $proposal->title }}</strong><div class="meta">{{ $proposal->created_at->format('Y/m/d H:i') }}</div><a href="{{ route('projects.ai-proposals.show', [$project,$proposal]) }}">内容を確認する →</a></article>@endforeach</div></section>
                @endif
                <details class="ai-card">
                    <summary style="cursor:pointer;font-weight:700">AIへ長い作業を依頼する</summary>
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
                <details class="ai-card"><summary style="cursor:pointer;font-weight:700">最近のAI依頼</summary><div class="ai-history" style="margin-top:12px">@forelse($aiRequests->take(5) as $aiRequest)<article><strong>{{ $aiRequest->title }}</strong><div class="meta">{{ $aiRequest->created_at->format('Y/m/d H:i') }} / {{ $aiRequest->status }}</div></article>@empty<p>AIへの依頼はまだありません。</p>@endforelse</div></details>
            </div>
        </aside>
    </div>
</section>

<script>
(() => {
    const workbench = document.querySelector('[data-workbench]');
    if (!workbench) return;
    const workbenchGrid = workbench.querySelector('.workbench-grid');
    const paneWidthKey = `rise-gate-os-pane-widths-${@json($project->public_id)}`;
    try {
        const savedWidths = JSON.parse(localStorage.getItem(paneWidthKey) || '{}');
        if (savedWidths.explorer) workbenchGrid.style.setProperty('--explorer-width', `${savedWidths.explorer}px`);
        if (savedWidths.ai) workbenchGrid.style.setProperty('--ai-width', `${savedWidths.ai}px`);
    } catch (error) {}
    workbench.querySelectorAll('[data-pane-resizer]').forEach(handle => {
        handle.addEventListener('pointerdown', event => {
            if (matchMedia('(max-width:900px)').matches) return;
            event.preventDefault();
            handle.classList.add('is-dragging');
            handle.setPointerCapture(event.pointerId);
            const move = moveEvent => {
                const bounds = workbenchGrid.getBoundingClientRect();
                const styles = getComputedStyle(workbenchGrid);
                const explorerNow = parseFloat(styles.getPropertyValue('--explorer-width')) || 300;
                const aiNow = parseFloat(styles.getPropertyValue('--ai-width')) || 360;
                if (handle.dataset.paneResizer === 'explorer') {
                    const width = Math.max(220, Math.min(480, moveEvent.clientX - bounds.left, bounds.width - aiNow - 360));
                    workbenchGrid.style.setProperty('--explorer-width', `${width}px`);
                } else {
                    const width = Math.max(300, Math.min(520, bounds.right - moveEvent.clientX, bounds.width - explorerNow - 360));
                    workbenchGrid.style.setProperty('--ai-width', `${width}px`);
                }
            };
            const finish = () => {
                handle.classList.remove('is-dragging');
                handle.removeEventListener('pointermove', move);
                handle.removeEventListener('pointerup', finish);
                const styles = getComputedStyle(workbenchGrid);
                localStorage.setItem(paneWidthKey, JSON.stringify({
                    explorer: Math.round(parseFloat(styles.getPropertyValue('--explorer-width'))),
                    ai: Math.round(parseFloat(styles.getPropertyValue('--ai-width'))),
                }));
            };
            handle.addEventListener('pointermove', move);
            handle.addEventListener('pointerup', finish);
        });
    });
    const showViewer = name => {
        workbench.querySelectorAll('[data-viewer-panel]').forEach(panel => panel.classList.toggle('is-current', panel.dataset.viewerPanel === name));
    };
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
        showViewer('document');
        workbench.querySelector('[data-pane="main"]').scrollTop = 0;
        if (matchMedia('(max-width:900px)').matches) showMobilePane('main');
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
        const explorerTab = event.target.closest('[data-explorer-tab]');
        if (explorerTab) {
            const name = explorerTab.dataset.explorerTab;
            workbench.querySelectorAll('[data-explorer-tab]').forEach(button => button.classList.toggle('is-current', button.dataset.explorerTab === name));
            workbench.querySelectorAll('[data-explorer-panel]').forEach(panel => panel.classList.toggle('is-current', panel.dataset.explorerPanel === name));
        }
        const treeToggle = event.target.closest('[data-tree-toggle]');
        if (treeToggle) {
            const branch = workbench.querySelector(`[data-tree-branch="${CSS.escape(treeToggle.dataset.treeToggle)}"]`);
            if (branch) {
                const open = branch.hidden;
                branch.hidden = !open;
                treeToggle.setAttribute('aria-expanded', String(open));
            }
        }
        const fileToggle = event.target.closest('[data-file-toggle]');
        if (fileToggle) {
            const branch = workbench.querySelector(`[data-file-branch="${CSS.escape(fileToggle.dataset.fileToggle)}"]`);
            if (branch) {
                const open = branch.hidden;
                branch.hidden = !open;
                fileToggle.setAttribute('aria-expanded', String(open));
            }
        }
        const editorToggle = event.target.closest('[data-inline-editor-toggle]');
        if (editorToggle) workbench.querySelector(`[data-inline-editor="${CSS.escape(editorToggle.dataset.inlineEditorToggle)}"]`).hidden = false;
        const editorClose = event.target.closest('[data-inline-editor-close]');
        if (editorClose) workbench.querySelector(`[data-inline-editor="${CSS.escape(editorClose.dataset.inlineEditorClose)}"]`).hidden = true;
        const fileButton = event.target.closest('[data-file-name]');
        if (fileButton) {
            workbench.querySelectorAll('[data-file-name]').forEach(button => button.classList.toggle('is-current', button === fileButton));
            workbench.querySelector('[data-file-title]').textContent = fileButton.dataset.fileName;
            workbench.querySelector('[data-file-content]').textContent = fileButton.dataset.fileCopy;
            const opensInBrowser = fileButton.dataset.fileView === 'browser' || /(^|\/)index\.html?$/i.test(fileButton.dataset.fileName);
            if (opensInBrowser) {
                const frame = workbench.querySelector('[data-browser-frame]');
                frame.src = fileButton.dataset.previewUrl;
                frame.hidden = false;
                showViewer('browser');
            } else {
                showViewer('file');
            }
            const contextLabel = `${@json($project->name)} / File / ${fileButton.dataset.fileName}`;
            workbench.querySelector('[data-ai-context]').textContent = contextLabel;
            workbench.querySelector('[data-chat-context-key]').value = `file:${fileButton.dataset.fileName}`;
            workbench.querySelector('[data-chat-context-label]').value = contextLabel;
            if (matchMedia('(max-width:900px)').matches) showMobilePane('main');
        }
        if (event.target.closest('[data-usage-toggle]')) {
            const card = workbench.querySelector('[data-usage-card]');
            card.hidden = !card.hidden;
        }
        if (event.target.closest('[data-copy-request]')) {
            const text = document.getElementById('workspace-ai-copy')?.value || '';
            navigator.clipboard.writeText(text).then(() => workbench.querySelector('[data-copy-result]').textContent = 'コピーしました');
        }
    });

    workbench.querySelectorAll('[data-workspace-form]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = form.querySelector('button[type="submit"]');
            const status = form.querySelector('[data-inline-editor-status]');
            submit.disabled = true;
            status.textContent = '保存中…';
            try {
                const response = await fetch(form.action, {method:'POST', headers:{'Accept':'application/json'}, body:new FormData(form)});
                if (!response.ok) {
                    const body = await response.json();
                    throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || '保存できませんでした。');
                }
                if (form.hasAttribute('data-reload-after-save')) {
                    status.textContent = '保存しました。表示を更新します…';
                    window.location.reload();
                    return;
                }
                const panel = form.closest('[data-document-panel]');
                panel.querySelector('.document-title').textContent = form.elements.title.value;
                const summary = form.elements.description?.value || form.elements.desired_state?.value || form.elements.current_state?.value || '';
                panel.querySelector('.document-summary').textContent = summary || '詳細はまだ登録されていません。';
                status.textContent = '保存しました。';
            } catch (error) {
                status.textContent = error.message;
            } finally {
                submit.disabled = false;
            }
        });
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
            appendMessage('assistant', message.content, 'ただ今');
            const currentTokens = Number((workbench.querySelector('[data-chat-tokens]').textContent || '0').replace(/[^0-9]/g, ''));
            workbench.querySelector('[data-chat-tokens]').textContent = `${(currentTokens + tokens).toLocaleString()} tokens`;
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
