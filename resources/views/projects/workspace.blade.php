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
    .workspace-tabs { position:sticky; top:0; z-index:5; display:flex; gap:2px; min-height:42px; padding:5px 7px 0; overflow-x:auto; border-bottom:1px solid var(--wb-line); background:#f5f8f9; scrollbar-width:thin; }
    .workspace-tab { flex:0 0 auto; gap:7px; max-width:230px; padding:8px 10px; border:1px solid transparent; border-bottom:0; border-radius:6px 6px 0 0; color:#61737c; background:transparent; font-size:11px; font-weight:700; }
    .workspace-tab.is-current { color:#183f4a; border-color:var(--wb-line); background:#fff; }
    .workspace-tab__label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .workspace-tab__close { color:#819099; font-size:14px; line-height:1; }
    .workspace-tab--roadmap { box-shadow:inset 0 3px #75a7ca; }
    .workspace-tab--improvement { box-shadow:inset 0 3px #79b493; }
    .workspace-tab--task { box-shadow:inset 0 3px #cb8d89; }
    .workspace-tab--file { box-shadow:inset 0 3px #98a7af; }
    .workspace-tab--browser { box-shadow:inset 0 3px #60abb6; }
    .workspace-tab--pdf { box-shadow:inset 0 3px #c06b66; }
    .workspace-tab--image { box-shadow:inset 0 3px #9b82bb; }
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
    .hierarchy-controls { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:6px; padding:9px 8px; border-bottom:1px solid var(--wb-line); background:#fff; }
    .hierarchy-control { min-width:0; padding:7px 4px; border:1px solid transparent; border-radius:6px; font-size:10px; font-weight:800; cursor:pointer; }
    .hierarchy-control--roadmap { color:#204f6d; border-color:#8ab5d2; background:#cfe3f2; }
    .hierarchy-control--improvement { color:#64806f; border-color:#d0e4d7; background:#eff7f2; }
    .hierarchy-control--improvement.is-active { color:#28583e; border-color:#86bb9c; background:#cfe9da; }
    .hierarchy-control--task { color:#8c6d6b; border-color:#ead7d5; background:#fbf1f0; }
    .hierarchy-control--task.is-active { color:#6b3835; border-color:#d39c98; background:#efd2d0; }
    .reorder-preference { display:grid; gap:5px; padding:10px 10px 8px; border-bottom:1px solid var(--wb-line); background:#fff; }
    .reorder-preference label { color:#63747d; font-size:10px; font-weight:800; }
    .reorder-preference select { width:100%; padding:7px 9px; border:1px solid #cbd6db; border-radius:6px; color:#29434e; background:#f8fafb; font-size:11px; }
    .reorder-preference small { min-height:15px; color:#4c8078; font-size:9px; }
    .tree-group { margin:5px 0 10px; }
    .tree-group__label { padding:7px 10px; color:#74838c; font-size:10px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; }
    .tree-item { position:relative; width:100%; display:flex; align-items:center; gap:8px; min-height:34px; padding:7px 10px; border:0; border-radius:6px; color:#31434d; background:transparent; text-align:left; font-size:13px; font-weight:600; cursor:pointer; }
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
    .tree-item[draggable="true"] { cursor:grab; }
    .tree-item.is-dragging { opacity:.45; }
    .tree-item.is-drop-before::before, .tree-item.is-drop-after::before { content:""; position:absolute; z-index:4; right:5px; left:5px; height:3px; border-radius:999px; background:#328392; box-shadow:0 0 0 2px #e5f3f5; pointer-events:none; }
    .tree-item.is-drop-before::after, .tree-item.is-drop-after::after { content:"ここへ移動"; position:absolute; z-index:5; right:7px; padding:2px 7px; border-radius:999px; color:#fff; background:#256c78; box-shadow:0 1px 4px rgba(23,63,74,.2); font-size:9px; font-weight:800; line-height:1.5; pointer-events:none; }
    .tree-item.is-drop-before::before { top:-2px; }
    .tree-item.is-drop-before::after { top:1px; transform:translateY(-100%); }
    .tree-item.is-drop-after::before { bottom:-2px; }
    .tree-item.is-drop-after::after { bottom:1px; transform:translateY(100%); }
    .schedule-toast { position:fixed; z-index:50; top:82px; left:50%; padding:10px 16px; border:1px solid #a9d5cd; border-radius:999px; color:#174e48; background:#e6f5f1; box-shadow:0 8px 24px rgba(23,63,74,.16); font-size:12px; font-weight:800; transform:translateX(-50%); }
    .schedule-toast[hidden] { display:none; }
    .file-repository { padding:11px 12px; border-bottom:1px solid var(--wb-line); color:#40545e; background:#fff; font-size:12px; font-weight:800; }
    .file-repository span { display:block; margin-top:2px; color:#7b8b93; font-size:10px; font-weight:500; }
    .file-item { justify-content:flex-start; width:100%; font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:11px; font-weight:500; text-align:left; }
    .file-item--directory { color:#294d5a; font-weight:750; }
    .file-item__expander { flex:0 0 12px; color:#71848d; text-align:center; }
    .file-item__kind { flex:0 0 18px; font-family:"Segoe UI Emoji","Noto Color Emoji",sans-serif; font-size:14px; line-height:1; text-align:left; }
    .file-item__label { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:left; }
    .file-item--nested { padding-left:27px; }
    .file-note { margin:12px 10px; padding:10px; border:1px dashed #c7d3d9; border-radius:7px; color:#6a7b84; background:#fff; font-size:10px; line-height:1.55; }
    .viewer-panel { display:none; min-height:100%; }
    .viewer-panel.is-current { display:block; }
    .browser-frame { width:100%; height:100%; min-height:calc(100vh - 126px); border:0; background:#fff; }
    .pdf-frame { width:100%; height:calc(100vh - 112px); min-height:640px; border:0; background:#525659; }
    .image-viewer { min-height:calc(100vh - 112px); display:grid; grid-template-rows:auto minmax(0,1fr); background:#e9eef1; }
    .image-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:8px 14px; border-bottom:1px solid #cbd5da; color:#425660; background:#f8fafb; font-size:12px; }
    .image-toolbar__actions { display:flex; gap:7px; }
    .image-toolbar button, .image-toolbar a { padding:6px 10px; border:1px solid #bccbd2; border-radius:6px; color:#294752; background:#fff; font:inherit; font-weight:700; text-decoration:none; cursor:pointer; }
    .image-stage { min-height:0; display:flex; align-items:center; justify-content:center; overflow:auto; padding:24px; background-image:linear-gradient(45deg,#dde4e8 25%,transparent 25%),linear-gradient(-45deg,#dde4e8 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#dde4e8 75%),linear-gradient(-45deg,transparent 75%,#dde4e8 75%); background-position:0 0,0 8px,8px -8px,-8px 0; background-size:16px 16px; }
    .image-preview { display:block; max-width:100%; max-height:calc(100vh - 190px); object-fit:contain; box-shadow:0 8px 28px rgba(25,45,55,.2); }
    .image-preview.is-original { max-width:none; max-height:none; }
    .code-shell { margin-top:18px; overflow:auto; border:1px solid #30363d; border-radius:8px; background:#0d1117; box-shadow:0 8px 24px rgba(13,17,23,.16); color-scheme:dark; }
    .code-viewer { min-width:max-content; padding:12px 0 18px; counter-reset:code-line; color:#e6edf3; font:13px/1.7 ui-monospace,SFMono-Regular,Consolas,"Liberation Mono",monospace; tab-size:4; }
    .code-line { display:block; min-height:1.7em; padding:0 20px 0 0; white-space:pre; counter-increment:code-line; }
    .code-line::before { content:counter(code-line); display:inline-block; width:52px; margin-right:16px; padding-right:12px; border-right:1px solid #30363d; color:#6e7681; text-align:right; user-select:none; }
    .code-token--tag, .code-token--keyword { color:#ff7b72; font-weight:650; }
    .code-token--variable { color:#79c0ff; }
    .code-token--string { color:#a5d6ff; }
    .code-token--comment { color:#8b949e; font-style:italic; }
    .code-token--number { color:#d2a8ff; }
    .usage-card[hidden] { display:none; }
    .usage-grid { display:grid; margin-top:10px; }
    .usage-grid div { padding:10px; border-radius:7px; background:#f3f7f8; }
    .usage-grid strong, .usage-grid span { display:block; }
    .usage-grid span { color:#71818a; font-size:10px; }
    .workbench-document { display:none; padding:28px clamp(22px,4vw,54px) 60px; }
    .workbench-document.is-current { display:block; }
    .file-preview-document { padding-top:16px; }
    .file-preview-document .document-kicker { margin-bottom:5px; }
    .file-preview-document .document-title { max-width:100%; margin:0; overflow:hidden; color:#314955; font:700 16px/1.45 ui-monospace,SFMono-Regular,Consolas,"Liberation Mono",monospace; letter-spacing:0; text-overflow:ellipsis; white-space:nowrap; }
    .file-preview-actions { display:flex; flex-wrap:wrap; gap:7px; margin-top:9px; }
    .file-preview-actions[hidden] { display:none; }
    .file-preview-actions button, .file-preview-actions a { padding:6px 10px; border:1px solid #bccbd2; border-radius:6px; color:#294752; background:#fff; font-size:11px; font-weight:750; text-decoration:none; cursor:pointer; }
    .file-preview-document .code-shell { margin-top:10px; }
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
    .ai-chat-messages { display:grid; align-content:start; gap:10px; min-height:180px; max-height:calc(100vh - 560px); overflow:auto; padding:2px; }
    .ai-chat-empty { padding:18px 12px; border:1px dashed #cbd6dc; border-radius:9px; color:#647680; text-align:center; font-size:12px; line-height:1.7; }
    .ai-message { display:grid; gap:5px; min-width:0; max-width:92%; }
    .ai-message--user { justify-self:end; }
    .ai-message--assistant { justify-self:start; }
    .ai-message__bubble { padding:10px 12px; border-radius:11px; white-space:pre-wrap; overflow-wrap:anywhere; font-size:12px; line-height:1.65; }
    .ai-message__image { display:block; width:auto; max-width:100%; max-height:220px; margin-bottom:7px; border-radius:7px; object-fit:contain; cursor:zoom-in; }
    .chat-image-modal { width:min(92vw,1200px); max-width:none; padding:42px 18px 18px; border:0; border-radius:10px; background:#111820; box-shadow:0 24px 70px rgba(0,0,0,.45); }
    .chat-image-modal::backdrop { background:rgba(8,18,23,.78); }
    .chat-image-modal img { display:block; width:auto; max-width:100%; max-height:82vh; margin:auto; object-fit:contain; }
    .chat-image-modal__close { position:absolute; top:9px; right:10px; width:28px; height:28px; padding:0; border:1px solid #687781; border-radius:50%; color:#fff; background:#26343d; font-size:18px; line-height:26px; }
    .file-change-proposal { min-width:0; margin-top:7px; padding:9px; border:1px solid #9fc7bd; border-radius:8px; color:#294b45; background:#f1faf7; font-size:11px; }
    .file-change-proposal summary { cursor:pointer; font-weight:800; }
    .file-change-proposal pre { max-width:100%; max-height:260px; overflow-y:auto; overflow-x:hidden; padding:9px; border-radius:6px; color:#e6edf3; background:#0d1117; white-space:pre-wrap; overflow-wrap:anywhere; font:10px/1.55 ui-monospace,SFMono-Regular,Consolas,monospace; }
    .file-change-proposal button { width:100%; margin-top:7px; padding:8px; }
    .file-change-proposal.is-applied { border-color:#b8c7cc; color:#687980; background:#f4f6f7; }
    .file-change-status { display:block; margin-top:6px; font-size:10px; }
    .ai-message--user .ai-message__bubble { color:#fff; background:#155566; border-bottom-right-radius:3px; }
    .ai-message--assistant .ai-message__bubble { color:#23363f; border:1px solid #d6e0e4; background:#fff; border-bottom-left-radius:3px; }
    .ai-message__meta { color:#7d8c94; font-size:9px; }
    .ai-message--user .ai-message__meta { text-align:right; }
    .ai-message.is-pending .ai-message__bubble { color:#61737c; background:#edf2f4; }
    .ai-chat-error { padding:9px 10px; border:1px solid #dfb5ad; border-radius:7px; color:#8a4338; background:#fff6f4; font-size:11px; }
    .ai-chat-form { display:grid; gap:8px; position:sticky; bottom:0; padding-top:4px; background:#fafcfc; }
    .ai-chat-form textarea { min-height:88px; max-height:220px; resize:vertical; font-size:13px; line-height:1.65; }
    .chat-image-preview { position:relative; width:max-content; max-width:100%; padding:5px; border:1px solid #cbd7dc; border-radius:8px; background:#fff; }
    .chat-image-preview[hidden] { display:none; }
    .chat-image-preview img { display:block; max-width:150px; max-height:100px; border-radius:5px; object-fit:contain; }
    .chat-image-preview button { position:absolute; top:-7px; right:-7px; width:22px; height:22px; padding:0; border:1px solid #c6d0d5; border-radius:50%; color:#536771; background:#fff; line-height:20px; }
    .chat-attach-button { display:inline-flex; align-items:center; gap:6px; padding:5px 7px !important; border:0; color:#526974; background:transparent; font-size:11px; font-weight:700; cursor:pointer; }
    .chat-attach-button:hover { color:#0f5968; background:#edf4f5; }
    .chat-attach-button__icon { display:grid; width:22px; height:22px; place-items:center; border:1px solid #b9c9cf; border-radius:50%; background:#fff; font-size:13px; line-height:1; }
    .chat-paste-hint { color:#71838c; font-size:10px; }
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

<section class="company-workbench" data-workbench data-order-url="{{ route('projects.workspace.order', $project) }}" data-preference-url="{{ route('projects.workspace.preference', $project) }}" data-local-handle-key="project-{{ $project->id }}-user-{{ auth()->id() }}">
    <div class="schedule-toast" data-schedule-toast hidden>スケジュールの時間軸も変更しました</div>
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
                <div class="hierarchy-controls" aria-label="WORKの表示階層">
                    <button class="hierarchy-control hierarchy-control--roadmap" type="button" data-hierarchy-level="roadmap" aria-pressed="true">ロードマップ</button>
                    <button class="hierarchy-control hierarchy-control--improvement" type="button" data-hierarchy-level="improvement" aria-pressed="false">取組み</button>
                    <button class="hierarchy-control hierarchy-control--task" type="button" data-hierarchy-level="task" aria-pressed="false">タスク</button>
                </div>
                @if($canEditProject)
                    <div class="reorder-preference">
                        <label for="workspace-reorder-mode">ドラッグ時の時間軸</label>
                        <select id="workspace-reorder-mode" data-reorder-mode>
                            <option value="schedule" @selected($project->workspace_reorder_mode !== 'order_only')>順番と時間軸を変更</option>
                            <option value="order_only" @selected($project->workspace_reorder_mode === 'order_only')>順番だけ変更</option>
                        </select>
                        <small data-reorder-mode-status></small>
                    </div>
                @endif
                <div class="tree-body">
                    <div class="tree-group">
                        <div class="tree-group__label">Roadmaps</div>
                        @forelse($roadmaps as $roadmap)
                            <button class="tree-item tree-item--roadmap" type="button" @can('update',$roadmap) draggable="true" @endcan data-reorder-type="roadmap" data-reorder-id="{{ $roadmap->id }}" data-reorder-parent="project" data-document="roadmap-{{ $roadmap->id }}" data-tree-toggle="roadmap-{{ $roadmap->id }}" aria-expanded="false"><span class="tree-icon tree-expander">▸</span>{{ $roadmap->title }}<span class="tree-count">{{ $roadmap->improvements->count() }}</span></button>
                            <div class="tree-branch" data-tree-branch="roadmap-{{ $roadmap->id }}" hidden>
                                @foreach($roadmap->improvements as $improvement)
                                    <button class="tree-item tree-item--grandchild tree-item--improvement" type="button" @can('update',$improvement) draggable="true" @endcan data-reorder-type="improvement" data-reorder-id="{{ $improvement->id }}" data-reorder-parent="{{ $roadmap->id }}" data-document="improvement-{{ $improvement->id }}" data-tree-toggle="improvement-{{ $improvement->id }}" aria-expanded="false"><span class="tree-icon tree-expander">▸</span>{{ $improvement->title }}<span class="tree-count">{{ $improvement->tasks->count() }}</span></button>
                                    <div class="tree-branch" data-tree-branch="improvement-{{ $improvement->id }}" hidden>
                                        @foreach($improvement->tasks as $task)
                                            <button class="tree-item tree-item--task-child tree-item--task" type="button" @can('update',$task) draggable="true" @endcan data-reorder-type="task" data-reorder-id="{{ $task->id }}" data-reorder-parent="{{ $improvement->id }}" data-document="task-{{ $task->id }}"><span class="tree-icon">✓</span>{{ $task->title }}</button>
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
                <div class="file-repository">▣ {{ $localConnection?->directory_name ?? 'ローカルフォルダ未設定' }}<span>{{ $localConnection ? 'ブラウザ接続・読み取り専用' : 'Project設定から登録してください' }}</span></div>
                <div class="tree-body" data-local-file-tree><p class="file-note">フォルダへのアクセスを確認しています…</p></div>
                <p class="file-note" data-local-file-status>Chrome・EdgeでProject設定からフォルダを選択してください。</p>
            </nav>
        </aside>

        <button class="pane-resizer pane-resizer--explorer" type="button" data-pane-resizer="explorer" aria-label="WORKとFILESの幅を変更"></button>

        <section class="workbench-pane workbench-main is-mobile-current" data-pane="main">
            <div class="workspace-tabs" data-workspace-tabs><button class="workspace-tab is-current" type="button" data-workspace-tab="project" data-tab-kind="document" data-tab-key="project"><span class="workspace-tab__label">Project Overview</span></button></div>
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
                    <div class="actions" style="margin-top:20px">@can('update',$roadmap)<button class="secondary" type="button" data-inline-editor-toggle="roadmap-edit-{{ $roadmap->id }}">編集</button>@endcan @if($canCreateImprovement)<button type="button" data-inline-editor-toggle="roadmap-create-{{ $roadmap->id }}">取組みを追加</button>@endif</div>
                    @can('update',$roadmap)
                        <form class="inline-editor stack" data-inline-editor="roadmap-edit-{{ $roadmap->id }}" data-workspace-form data-update-panel hidden method="POST" action="{{ route('projects.roadmaps.update', [$project,$roadmap]) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="planned_start_date" value="{{ $roadmap->planned_start_date?->format('Y-m-d') }}"><input type="hidden" name="target_date" value="{{ $roadmap->target_date?->format('Y-m-d') }}"><input type="hidden" name="planned_start_day" value="{{ $roadmap->planned_start_day }}"><input type="hidden" name="target_day" value="{{ $roadmap->target_day }}"><input type="hidden" name="reached_at" value="{{ $roadmap->reached_at?->format('Y-m-d') }}">
                            <div class="field"><label for="workspace_roadmap_title_{{ $roadmap->id }}">ロードマップ名</label><input id="workspace_roadmap_title_{{ $roadmap->id }}" name="title" value="{{ $roadmap->title }}" required></div>
                            <div class="field"><label for="workspace_roadmap_purpose_{{ $roadmap->id }}">目的</label><textarea id="workspace_roadmap_purpose_{{ $roadmap->id }}" name="purpose" rows="4">{{ $roadmap->purpose }}</textarea></div>
                            <div class="actions"><button type="submit">保存する</button><button class="secondary" type="button" data-inline-editor-close="roadmap-edit-{{ $roadmap->id }}">閉じる</button></div><div class="inline-editor__status" data-inline-editor-status></div>
                        </form>
                    @endcan
                    @if($canCreateImprovement)
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
                        <div class="actions" style="margin-top:20px">@can('update',$improvement)<button class="secondary" type="button" data-inline-editor-toggle="improvement-edit-{{ $improvement->id }}">編集</button>@endcan @if($canCreateTask)<button type="button" data-inline-editor-toggle="task-create-{{ $improvement->id }}">タスクを追加</button>@endif</div>
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
                        <div class="actions" style="margin-top:20px"><button class="secondary" type="button" data-inline-editor-toggle="task-{{ $task->id }}">編集</button></div>
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
                <article class="workbench-document file-preview-document is-current" style="display:block"><div class="document-kicker">File Preview</div><h1 class="document-title" data-file-title title="ファイルを選択">ファイルを選択</h1><div class="file-preview-actions" data-file-preview-actions hidden><button type="button" data-open-local-browser>ブラウザで表示</button><a data-open-local-external target="_blank" rel="noopener">別タブで開く</a></div><div class="code-shell"><code class="code-viewer" data-file-content><span class="code-line">左のFILESからファイルを開くと、ここに内容を表示します。</span></code></div></article>
            </div>
            <div class="viewer-panel" data-viewer-panel="browser">
                <iframe class="browser-frame" data-browser-frame title="ブラウザプレビュー" sandbox="allow-forms allow-scripts allow-same-origin allow-popups" hidden></iframe>
            </div>
            <div class="viewer-panel" data-viewer-panel="pdf">
                <iframe class="pdf-frame" data-pdf-frame title="PDFビューワー" hidden></iframe>
            </div>
            <div class="viewer-panel" data-viewer-panel="image">
                <div class="image-viewer">
                    <div class="image-toolbar"><span data-image-name>画像プレビュー</span><div class="image-toolbar__actions"><button type="button" data-image-size="fit">画面に合わせる</button><button type="button" data-image-size="original">原寸</button><a data-image-download download>ダウンロード</a></div></div>
                    <div class="image-stage"><img class="image-preview" data-image-preview alt="" hidden></div>
                </div>
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
                    @php($chatTokenTotal = $aiChatMessages->sum(fn ($message) => ($message->input_tokens ?? 0) + ($message->output_tokens ?? 0)))
                    <div class="usage-grid"><div><span>AI利用ポイント</span><strong data-chat-points data-total-tokens="{{ $chatTokenTotal }}">{{ number_format($chatTokenTotal > 0 ? max(1, round($chatTokenTotal / 1000)) : 0) }}ポイント</strong></div></div>
                    <p class="meta" style="margin-top:8px">AIとの会話や資料の読み取りに使用した利用量の目安です。</p>
                </section>
                <section class="ai-chat-messages" data-chat-messages aria-live="polite">
                    @forelse($aiChatMessages as $chatMessage)
                        <article class="ai-message ai-message--{{ $chatMessage->role }}">
                            <div class="ai-message__bubble">@if($chatMessage->image_path)<img class="ai-message__image" src="{{ route('projects.ai-chat.messages.image', [$project, $chatMessage]) }}" alt="{{ $chatMessage->image_name }}">@endif{{ $chatMessage->content }}</div>
                            @if($chatMessage->file_change_path)
                                <details class="file-change-proposal {{ $chatMessage->file_change_status === 'applied' ? 'is-applied' : '' }}" data-file-change>
                                    <summary>{{ $chatMessage->file_change_status === 'applied' ? '反映済み' : '変更案を確認' }}</summary>
                                    <strong>{{ $chatMessage->file_change_path }}</strong>
                                    <p>変更後のファイル全文</p>
                                    <pre>{{ $chatMessage->file_change_content }}</pre>
                                    <textarea hidden data-file-change-content>{{ $chatMessage->file_change_content }}</textarea>
                                    @if($chatMessage->file_change_status !== 'applied')
                                        <button type="button" data-file-change-apply data-file-path="{{ $chatMessage->file_change_path }}" data-original-hash="{{ $chatMessage->file_change_original_hash }}" data-apply-url="{{ route('projects.ai-chat.messages.file-change.applied', [$project, $chatMessage]) }}">承認してローカルへ反映</button>
                                    @endif
                                    <span class="file-change-status" data-file-change-status></span>
                                </details>
                            @endif
                            <div class="ai-message__meta">
                                {{ $chatMessage->created_at->timezone(config('app.display_timezone'))->format('m/d H:i') }}
                            </div>
                        </article>
                    @empty
                        <div class="ai-chat-empty" data-chat-empty>このProjectについてAIと会話できます。<br>AIは情報を読み取りますが、データを変更することはありません。</div>
                    @endforelse
                </section>
                <div class="ai-chat-error" data-chat-error hidden></div>
                <form class="ai-chat-form" data-chat-form data-chat-url="{{ route('projects.ai-chat.messages.store', $project) }}" enctype="multipart/form-data">
                    <textarea name="content" rows="3" maxlength="4000" placeholder="このProjectについて質問する…" @disabled(!$aiChatEnabled || !$aiChatConfigured) required></textarea>
                    <input type="file" name="image" accept="image/png,image/jpeg,image/webp" hidden data-chat-image-input>
                    <div class="chat-image-preview" data-chat-image-preview hidden><img alt="添付するスクリーンショット"><button type="button" data-chat-image-remove aria-label="画像を削除">×</button></div>
                    <input type="hidden" name="context_key" value="project" data-chat-context-key>
                    <input type="hidden" name="context_label" value="{{ $project->name }} / Project Overview" data-chat-context-label>
                    <input type="hidden" name="file_path" value="" data-chat-file-path>
                    <textarea name="file_content" hidden data-chat-file-content></textarea>
                    <div class="ai-chat-form__actions">
                        <button class="chat-attach-button" type="button" data-chat-image-select><span class="chat-attach-button__icon" aria-hidden="true">📎</span>画像を選択</button>
                        <span class="chat-paste-hint">スクショは貼り付けもできます</span>
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
<dialog class="chat-image-modal" data-chat-image-modal aria-label="添付画像の拡大表示">
    <button class="chat-image-modal__close" type="button" data-chat-image-modal-close aria-label="閉じる">×</button>
    <img src="" alt="添付画像の拡大表示" data-chat-image-modal-image>
</dialog>

<script>
(() => {
    const workbench = document.querySelector('[data-workbench]');
    if (!workbench) return;
    const workbenchGrid = workbench.querySelector('.workbench-grid');
    const localTree = workbench.querySelector('[data-local-file-tree]');
    const localStatus = workbench.querySelector('[data-local-file-status]');
    const localSiteUrl = @json($localConnection?->local_site_url);
    let localDirectoryHandle = null;
    const loadLocalHandle = () => new Promise((resolve, reject) => {
        const request = indexedDB.open('rise-gate-local-folders', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('handles');
        request.onerror = () => reject(request.error);
        request.onsuccess = () => {
            const transaction = request.result.transaction('handles', 'readonly');
            const get = transaction.objectStore('handles').get(workbench.dataset.localHandleKey);
            get.onsuccess = () => resolve(get.result || null);
            get.onerror = () => reject(get.error);
        };
    });
    const renderLocalDirectory = async (handle, container, prefix = '', depth = 0) => {
        container.replaceChildren();
        const entries = [];
        for await (const entry of handle.values()) entries.push(entry);
        entries.sort((a, b) => a.kind === b.kind ? a.name.localeCompare(b.name, 'ja') : a.kind === 'directory' ? -1 : 1);
        entries.forEach(entry => {
            const path = prefix ? `${prefix}/${entry.name}` : entry.name;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `tree-item file-item${entry.kind === 'directory' ? ' file-item--directory' : ' file-item--file'}`;
            button.style.paddingLeft = `${10 + depth * 17}px`;
            button.innerHTML = `<span class="file-item__expander">${entry.kind === 'directory' ? '▸' : ''}</span><span class="file-item__kind" aria-hidden="true">${entry.kind === 'directory' ? '📁' : '📄'}</span><span class="file-item__label"></span>`;
            button.lastElementChild.textContent = entry.name;
            if (entry.kind === 'directory') {
                button.dataset.localDirectory = path;
                button.localHandle = entry;
                const branch = document.createElement('div');
                branch.className = 'tree-branch';
                branch.hidden = true;
                button.localBranch = branch;
                container.append(button, branch);
            } else {
                button.dataset.fileName = path;
                button.localFileHandle = entry;
                container.append(button);
            }
        });
        localStatus.textContent = `${entries.length}項目を表示中（読み取り専用）`;
    };
    const ensureLocalFolderAccess = async () => {
        localDirectoryHandle ||= await loadLocalHandle();
        if (!localDirectoryHandle) { localTree.innerHTML = '<p class="file-note">Project設定からBROWSEでフォルダを選択してください。</p>'; return; }
        let permission = await localDirectoryHandle.queryPermission({mode:'read'});
        if (permission !== 'granted') permission = await localDirectoryHandle.requestPermission({mode:'read'});
        if (permission !== 'granted') { localStatus.textContent = 'フォルダへのアクセス許可が必要です。'; return; }
        await renderLocalDirectory(localDirectoryHandle, localTree);
    };
    loadLocalHandle().then(async handle => {
        localDirectoryHandle = handle;
        if (!handle) { localTree.innerHTML = '<p class="file-note">Project設定からBROWSEでフォルダを選択してください。</p>'; return; }
        if (await handle.queryPermission({mode:'read'}) === 'granted') await renderLocalDirectory(handle, localTree);
        else localTree.innerHTML = '<p class="file-note">FILESをクリックしてフォルダへのアクセスを再開してください。</p>';
    }).catch(() => { localStatus.textContent = 'ローカルフォルダ設定を読み込めませんでした。'; });
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
    const setChatFileContext = (path = '', content = '') => {
        workbench.querySelector('[data-chat-file-path]').value = path;
        workbench.querySelector('[data-chat-file-content]').value = content;
    };
    const setBranches = (prefix, open) => {
        workbench.querySelectorAll(`[data-tree-branch^="${prefix}-"]`).forEach(branch => { branch.hidden = !open; });
        workbench.querySelectorAll(`[data-tree-toggle^="${prefix}-"]`).forEach(button => button.setAttribute('aria-expanded', String(open)));
    };
    const syncHierarchyControls = () => {
        const roadmapBranches = [...workbench.querySelectorAll('[data-tree-branch^="roadmap-"]')];
        const improvementBranches = [...workbench.querySelectorAll('[data-tree-branch^="improvement-"]')];
        const improvementsOpen = roadmapBranches.length > 0 && roadmapBranches.every(branch => !branch.hidden);
        const tasksOpen = improvementBranches.length > 0 && improvementsOpen && improvementBranches.every(branch => !branch.hidden);
        const improvementButton = workbench.querySelector('[data-hierarchy-level="improvement"]');
        const taskButton = workbench.querySelector('[data-hierarchy-level="task"]');
        improvementButton.classList.toggle('is-active', improvementsOpen);
        improvementButton.setAttribute('aria-pressed', String(improvementsOpen));
        taskButton.classList.toggle('is-active', tasksOpen);
        taskButton.setAttribute('aria-pressed', String(tasksOpen));
    };
    const setHierarchyLevel = level => {
        if (level === 'roadmap') {
            setBranches('roadmap', false);
            setBranches('improvement', false);
        } else if (level === 'improvement') {
            const allOpen = [...workbench.querySelectorAll('[data-tree-branch^="roadmap-"]')].every(branch => !branch.hidden);
            setBranches('roadmap', !allOpen);
            if (allOpen) setBranches('improvement', false);
        } else if (level === 'task') {
            const branches = [...workbench.querySelectorAll('[data-tree-branch^="improvement-"]')];
            const allOpen = branches.length > 0 && branches.every(branch => !branch.hidden);
            setBranches('roadmap', true);
            setBranches('improvement', !allOpen);
        }
        syncHierarchyControls();
    };
    const localBrowserUrl = path => {
        if (!localSiteUrl) return '';
        let relative = String(path || '').replaceAll('\\', '/').replace(/^\/+/, '');
        const base = localSiteUrl.endsWith('/') ? localSiteUrl : `${localSiteUrl}/`;
        try {
            const basePath = new URL(base).pathname.replace(/\/+$/, '');
            if (basePath.endsWith('/public_html') && relative.startsWith('public_html/')) relative = relative.slice(12);
            return new URL(relative, base).href;
        } catch (error) { return ''; }
    };
    const setLocalBrowserActions = path => {
        const actions = workbench.querySelector('[data-file-preview-actions]');
        const url = /\.(?:php|html?)$/i.test(path) ? localBrowserUrl(path) : '';
        actions.hidden = !url;
        actions.dataset.browserUrl = url;
        const external = actions.querySelector('[data-open-local-external]');
        external.href = url || '#';
    };
    const renderCode = content => {
        const viewer = workbench.querySelector('[data-file-content]');
        viewer.replaceChildren();
        const tokenPattern = /\/\/.*|#[^\r\n]*|'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|\$[A-Za-z_]\w*|\b(?:function|return|if|else|foreach|class|public|private|new|echo|true|false|null)\b|\b\d+(?:\.\d+)?\b/g;
        String(content ?? '').split(/\r?\n/).forEach(lineText => {
            const line = document.createElement('span');
            line.className = 'code-line';
            let offset = 0;
            for (const match of lineText.matchAll(tokenPattern)) {
                line.append(document.createTextNode(lineText.slice(offset, match.index)));
                const token = document.createElement('span');
                const value = match[0];
                let tokenType = 'keyword';
                if (value.startsWith('//') || value.startsWith('#')) tokenType = 'comment';
                else if (value.startsWith("'") || value.startsWith('"')) tokenType = 'string';
                else if (value.startsWith('$')) tokenType = 'variable';
                else if (/^\d/.test(value)) tokenType = 'number';
                token.className = `code-token--${tokenType}`;
                token.textContent = value;
                line.append(token);
                offset = match.index + value.length;
            }
            line.append(document.createTextNode(lineText.slice(offset)));
            viewer.append(line);
        });
    };
    const tabs = workbench.querySelector('[data-workspace-tabs]');
    const tabType = key => key.startsWith('roadmap-') ? 'roadmap' : key.startsWith('improvement-') ? 'improvement' : key.startsWith('task-') ? 'task' : 'document';
    const ensureTab = ({id, kind, key, label, content = '', url = ''}) => {
        let tab = tabs.querySelector(`[data-workspace-tab="${CSS.escape(id)}"]`);
        if (!tab) {
            tab = document.createElement('button');
            tab.type = 'button';
            tab.className = `workspace-tab workspace-tab--${kind === 'document' ? tabType(key) : kind}`;
            tab.dataset.workspaceTab = id;
            tab.dataset.tabKind = kind;
            tab.dataset.tabKey = key;
            tab.dataset.tabContent = content;
            tab.dataset.tabUrl = url;
            const labelNode = document.createElement('span');
            labelNode.className = 'workspace-tab__label';
            labelNode.textContent = label;
            const close = document.createElement('span');
            close.className = 'workspace-tab__close';
            close.dataset.closeWorkspaceTab = id;
            close.textContent = '×';
            tab.append(labelNode, close);
            tabs.append(tab);
        }
        tabs.querySelectorAll('[data-workspace-tab]').forEach(item => item.classList.toggle('is-current', item === tab));
        tab.scrollIntoView({inline:'nearest', block:'nearest'});
        return tab;
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
        setChatFileContext();
        ensureTab({id:key, kind:'document', key, label:title});
        showViewer('document');
        workbench.querySelector('[data-pane="main"]').scrollTop = 0;
        if (matchMedia('(max-width:900px)').matches) showMobilePane('main');
    };
    const showMobilePane = name => {
        workbench.querySelectorAll('[data-pane]').forEach(pane => pane.classList.toggle('is-mobile-current', pane.dataset.pane === name));
        workbench.querySelectorAll('[data-mobile-pane]').forEach(button => button.classList.toggle('is-current', button.dataset.mobilePane === name));
    };
    workbench.addEventListener('click', event => {
        const explorerTab = event.target.closest('[data-explorer-tab]');
        if (explorerTab) {
            const name = explorerTab.dataset.explorerTab;
            workbench.querySelectorAll('[data-explorer-tab]').forEach(button => button.classList.toggle('is-current', button.dataset.explorerTab === name));
            workbench.querySelectorAll('[data-explorer-panel]').forEach(panel => panel.classList.toggle('is-current', panel.dataset.explorerPanel === name));
            if (name === 'files') ensureLocalFolderAccess().catch(error => {
                localStatus.textContent = error.name === 'SecurityError' ? 'BROWSEからフォルダを再選択してください。' : 'フォルダを開けませんでした。';
            });
        }
        const treeToggle = event.target.closest('[data-tree-toggle]');
        if (treeToggle) {
            const branch = workbench.querySelector(`[data-tree-branch="${CSS.escape(treeToggle.dataset.treeToggle)}"]`);
            if (branch) {
                const open = branch.hidden;
                branch.hidden = !open;
                treeToggle.setAttribute('aria-expanded', String(open));
                syncHierarchyControls();
            }
        }
        const hierarchyControl = event.target.closest('[data-hierarchy-level]');
        if (hierarchyControl) setHierarchyLevel(hierarchyControl.dataset.hierarchyLevel);
    });
    workbench.addEventListener('click', async event => {
        const documentButton = event.target.closest('[data-document]');
        if (documentButton) openDocument(documentButton.dataset.document);
        const closeTab = event.target.closest('[data-close-workspace-tab]');
        if (closeTab) {
            event.stopPropagation();
            const tab = closeTab.closest('[data-workspace-tab]');
            const wasCurrent = tab.classList.contains('is-current');
            const next = tab.previousElementSibling || tab.nextElementSibling;
            if (tab.dataset.tabUrl?.startsWith('blob:')) URL.revokeObjectURL(tab.dataset.tabUrl);
            tab.remove();
            if (wasCurrent) next?.click();
            return;
        }
        const workspaceTab = event.target.closest('[data-workspace-tab]');
        if (workspaceTab && !event.target.closest('[data-close-workspace-tab]')) {
            const kind = workspaceTab.dataset.tabKind;
            if (kind === 'document') openDocument(workspaceTab.dataset.tabKey);
            else {
                ensureTab({id:workspaceTab.dataset.workspaceTab, kind, key:workspaceTab.dataset.tabKey, label:workspaceTab.querySelector('.workspace-tab__label').textContent, content:workspaceTab.dataset.tabContent, url:workspaceTab.dataset.tabUrl});
                if (kind === 'browser') {
                    setChatFileContext();
                    const frame = workbench.querySelector('[data-browser-frame]'); frame.src = workspaceTab.dataset.tabUrl; frame.hidden = false; showViewer('browser');
                } else if (kind === 'pdf') {
                    setChatFileContext();
                    const frame = workbench.querySelector('[data-pdf-frame]'); frame.src = workspaceTab.dataset.tabUrl; frame.hidden = false; showViewer('pdf');
                } else if (kind === 'image') {
                    setChatFileContext();
                    const image = workbench.querySelector('[data-image-preview]');
                    image.src = workspaceTab.dataset.tabUrl;
                    image.alt = workspaceTab.dataset.tabKey;
                    image.hidden = false;
                    image.classList.remove('is-original');
                    workbench.querySelector('[data-image-name]').textContent = workspaceTab.dataset.tabKey;
                    const download = workbench.querySelector('[data-image-download]');
                    download.href = workspaceTab.dataset.tabUrl;
                    download.download = workspaceTab.dataset.tabKey.split('/').pop();
                    showViewer('image');
                } else {
                    setChatFileContext(workspaceTab.dataset.tabKey, workspaceTab.dataset.tabContent);
                    const fileTitle = workbench.querySelector('[data-file-title]');
                    fileTitle.textContent = workspaceTab.dataset.tabKey;
                    fileTitle.title = workspaceTab.dataset.tabKey;
                    setLocalBrowserActions(workspaceTab.dataset.tabKey);
                    renderCode(workspaceTab.dataset.tabContent);
                    showViewer('file');
                }
                const contextLabel = `${@json($project->name)} / File / ${workspaceTab.dataset.tabKey}`;
                workbench.querySelector('[data-ai-context]').textContent = contextLabel;
                workbench.querySelector('[data-chat-context-key]').value = `file:${workspaceTab.dataset.tabKey}`;
                workbench.querySelector('[data-chat-context-label]').value = contextLabel;
            }
        }
        const paneButton = event.target.closest('[data-mobile-pane]');
        if (paneButton) showMobilePane(paneButton.dataset.mobilePane);
        const localDirectory = event.target.closest('[data-local-directory]');
        if (localDirectory) {
            const open = localDirectory.localBranch.hidden;
            localDirectory.localBranch.hidden = !open;
            localDirectory.setAttribute('aria-expanded', String(open));
            localDirectory.querySelector('.file-item__expander').textContent = open ? '▾' : '▸';
            if (open && !localDirectory.localBranch.hasChildNodes()) await renderLocalDirectory(localDirectory.localHandle, localDirectory.localBranch, localDirectory.dataset.localDirectory, localDirectory.dataset.localDirectory.split('/').length);
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
            let localFile = null;
            if (fileButton.localFileHandle) {
                localFile = await fileButton.localFileHandle.getFile();
                const pdf = localFile.type === 'application/pdf' || /\.pdf$/i.test(fileButton.dataset.fileName);
                const image = localFile.type.startsWith('image/') || /\.(?:jpe?g|png|gif|webp|svg|bmp|ico|avif)$/i.test(fileButton.dataset.fileName);
                fileButton.dataset.fileCopy = pdf || image ? '' : (localFile.size > 1024 * 1024 ? '1MBを超えるためプレビューできません。' : await localFile.text());
            }
            workbench.querySelectorAll('[data-file-name]').forEach(button => button.classList.toggle('is-current', button === fileButton));
            const fileTitle = workbench.querySelector('[data-file-title]');
            fileTitle.textContent = fileButton.dataset.fileName;
            fileTitle.title = fileButton.dataset.fileName;
            setLocalBrowserActions(fileButton.dataset.fileName);
            const opensAsPdf = localFile?.type === 'application/pdf' || /\.pdf$/i.test(fileButton.dataset.fileName);
            const opensAsImage = localFile?.type.startsWith('image/') || /\.(?:jpe?g|png|gif|webp|svg|bmp|ico|avif)$/i.test(fileButton.dataset.fileName);
            if (!opensAsPdf && !opensAsImage) renderCode(fileButton.dataset.fileCopy);
            const opensInBrowser = fileButton.dataset.fileView === 'browser' || /(^|\/)index\.html?$/i.test(fileButton.dataset.fileName);
            const tabId = `file:${fileButton.dataset.fileName}`;
            const existingUrl = tabs.querySelector(`[data-workspace-tab="${CSS.escape(tabId)}"]`)?.dataset.tabUrl;
            const previewUrl = existingUrl || ((opensAsPdf || opensAsImage) && localFile ? URL.createObjectURL(localFile) : opensInBrowser && fileButton.localFileHandle ? URL.createObjectURL(new Blob([fileButton.dataset.fileCopy], {type:'text/html'})) : (fileButton.dataset.previewUrl || ''));
            ensureTab({id:tabId, kind:opensAsPdf ? 'pdf' : opensAsImage ? 'image' : opensInBrowser ? 'browser' : 'file', key:fileButton.dataset.fileName, label:fileButton.dataset.fileName.split('/').pop(), content:fileButton.dataset.fileCopy, url:previewUrl});
            if (opensAsPdf) {
                setChatFileContext();
                const frame = workbench.querySelector('[data-pdf-frame]');
                frame.src = previewUrl;
                frame.hidden = false;
                showViewer('pdf');
            } else if (opensAsImage) {
                setChatFileContext();
                const image = workbench.querySelector('[data-image-preview]');
                image.src = previewUrl;
                image.alt = fileButton.dataset.fileName;
                image.hidden = false;
                image.classList.remove('is-original');
                workbench.querySelector('[data-image-name]').textContent = fileButton.dataset.fileName;
                const download = workbench.querySelector('[data-image-download]');
                download.href = previewUrl;
                download.download = fileButton.dataset.fileName.split('/').pop();
                showViewer('image');
            } else if (opensInBrowser) {
                setChatFileContext();
                const frame = workbench.querySelector('[data-browser-frame]');
                frame.src = previewUrl;
                frame.hidden = false;
                showViewer('browser');
            } else {
                setChatFileContext(fileButton.dataset.fileName, fileButton.dataset.fileCopy);
                showViewer('file');
            }
            const contextLabel = `${@json($project->name)} / File / ${fileButton.dataset.fileName}`;
            workbench.querySelector('[data-ai-context]').textContent = contextLabel;
            workbench.querySelector('[data-chat-context-key]').value = `file:${fileButton.dataset.fileName}`;
            workbench.querySelector('[data-chat-context-label]').value = contextLabel;
            if (matchMedia('(max-width:900px)').matches) showMobilePane('main');
        }
        const imageSize = event.target.closest('[data-image-size]');
        if (imageSize) workbench.querySelector('[data-image-preview]').classList.toggle('is-original', imageSize.dataset.imageSize === 'original');
        if (event.target.closest('[data-open-local-browser]')) {
            const actions = workbench.querySelector('[data-file-preview-actions]');
            const url = actions.dataset.browserUrl;
            if (url) {
                const path = workbench.querySelector('[data-file-title]').textContent;
                ensureTab({id:`browser:${path}`, kind:'browser', key:path, label:`↗ ${path.split('/').pop()}`, url});
                const frame = workbench.querySelector('[data-browser-frame]');
                frame.src = url;
                frame.hidden = false;
                showViewer('browser');
            }
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

    let draggedTreeItem = null;
    workbench.querySelector('[data-reorder-mode]')?.addEventListener('change', async event => {
        const status = workbench.querySelector('[data-reorder-mode-status]');
        status.textContent = '保存中…';
        try {
            const response = await fetch(workbench.dataset.preferenceUrl, {
                method:'PATCH',
                headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())},
                body:JSON.stringify({workspace_reorder_mode:event.target.value}),
            });
            if (!response.ok) throw new Error('設定を保存できませんでした。');
            status.textContent = 'このProjectに保存しました';
            setTimeout(() => { status.textContent = ''; }, 2000);
        } catch (error) {
            status.textContent = error.message;
        }
    });

    const clearDropIndicators = () => {
        workbench.querySelectorAll('.is-drop-before, .is-drop-after').forEach(item => {
            item.classList.remove('is-drop-before', 'is-drop-after');
            delete item.dataset.dropPosition;
        });
    };
    workbench.addEventListener('dragstart', event => {
        const item = event.target.closest('[data-reorder-type]');
        if (!item) return;
        draggedTreeItem = item;
        item.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    });
    workbench.addEventListener('dragover', event => {
        const target = event.target.closest('[data-reorder-type]');
        clearDropIndicators();
        if (!draggedTreeItem || !target || target === draggedTreeItem) return;
        if (target.dataset.reorderType !== draggedTreeItem.dataset.reorderType || target.dataset.reorderParent !== draggedTreeItem.dataset.reorderParent) return;
        event.preventDefault();
        const position = event.clientY < target.getBoundingClientRect().top + (target.offsetHeight / 2) ? 'before' : 'after';
        target.dataset.dropPosition = position;
        target.classList.add(`is-drop-${position}`);
    });
    workbench.addEventListener('drop', async event => {
        const target = event.target.closest('[data-reorder-type]');
        const position = target?.dataset.dropPosition;
        clearDropIndicators();
        if (!draggedTreeItem || !target || target === draggedTreeItem) return;
        if (target.dataset.reorderType !== draggedTreeItem.dataset.reorderType || target.dataset.reorderParent !== draggedTreeItem.dataset.reorderParent) return;
        event.preventDefault();
        const sourceBranch = draggedTreeItem.nextElementSibling?.classList.contains('tree-branch') ? draggedTreeItem.nextElementSibling : null;
        const targetBranch = target.nextElementSibling?.classList.contains('tree-branch') ? target.nextElementSibling : null;
        if (position === 'after') {
            (targetBranch || target).after(draggedTreeItem);
        } else {
            target.before(draggedTreeItem);
        }
        if (sourceBranch) draggedTreeItem.after(sourceBranch);
        const type = draggedTreeItem.dataset.reorderType;
        const parent = draggedTreeItem.dataset.reorderParent;
        const ids = [...workbench.querySelectorAll(`[data-reorder-type="${type}"][data-reorder-parent="${CSS.escape(parent)}"]`)].map(item => Number(item.dataset.reorderId));
        try {
            const response = await fetch(workbench.dataset.orderUrl, {
                method:'PATCH',
                headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())},
                body:JSON.stringify({type, parent_id:parent === 'project' ? null : Number(parent), ids}),
            });
            const body = await response.json();
            if (!response.ok) throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || '表示順を保存できませんでした。');
            if (body.schedule_updated) {
                const toast = workbench.querySelector('[data-schedule-toast]');
                toast.hidden = false;
                clearTimeout(toast.hideTimer);
                toast.hideTimer = setTimeout(() => { toast.hidden = true; }, 2000);
            }
        } catch (error) {
            alert(error.message);
            window.location.reload();
        }
    });
    workbench.addEventListener('dragend', () => {
        draggedTreeItem?.classList.remove('is-dragging');
        clearDropIndicators();
        draggedTreeItem = null;
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
                const summary = form.elements.description?.value || form.elements.purpose?.value || form.elements.desired_state?.value || form.elements.current_state?.value || '';
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
    const chatImageModal = document.querySelector('[data-chat-image-modal]');
    const closeChatImageModal = () => chatImageModal?.close();
    chatMessages?.addEventListener('click', event => {
        const image = event.target.closest('.ai-message__image');
        if (!image || !chatImageModal) return;
        chatImageModal.querySelector('[data-chat-image-modal-image]').src = image.src;
        chatImageModal.showModal();
    });
    chatImageModal?.querySelector('[data-chat-image-modal-close]')?.addEventListener('click', closeChatImageModal);
    chatImageModal?.addEventListener('click', event => {
        if (event.target === chatImageModal) closeChatImageModal();
    });
    const appendFileChange = (article, proposal) => {
        if (!proposal) return;
        const details = document.createElement('details');
        details.className = `file-change-proposal${proposal.status === 'applied' ? ' is-applied' : ''}`;
        details.dataset.fileChange = '';
        const summary = document.createElement('summary');
        summary.textContent = proposal.status === 'applied' ? '反映済み' : '変更案を確認';
        const path = document.createElement('strong');
        path.textContent = proposal.path;
        const description = document.createElement('p');
        description.textContent = '変更後のファイル全文';
        const preview = document.createElement('pre');
        preview.textContent = proposal.content;
        const source = document.createElement('textarea');
        source.hidden = true;
        source.dataset.fileChangeContent = '';
        source.value = proposal.content;
        details.append(summary, path, description, preview, source);
        if (proposal.status !== 'applied') {
            const apply = document.createElement('button');
            apply.type = 'button';
            apply.dataset.fileChangeApply = '';
            apply.dataset.filePath = proposal.path;
            apply.dataset.originalHash = proposal.original_hash;
            apply.dataset.applyUrl = proposal.apply_url;
            apply.textContent = '承認してローカルへ反映';
            details.append(apply);
        }
        const status = document.createElement('span');
        status.className = 'file-change-status';
        status.dataset.fileChangeStatus = '';
        details.append(status);
        article.append(details);
    };
    const scrollChatTo = (article, align = 'end') => requestAnimationFrame(() => {
        const top = article.offsetTop - chatMessages.offsetTop;
        chatMessages.scrollTop = align === 'start'
            ? top
            : Math.max(0, top + article.offsetHeight - chatMessages.clientHeight);
    });
    const appendMessage = (role, content, meta = '', pending = false, imageUrl = '', fileChange = null, scroll = true) => {
        workbench.querySelector('[data-chat-empty]')?.remove();
        const article = document.createElement('article');
        article.className = `ai-message ai-message--${role}${pending ? ' is-pending' : ''}`;
        const bubble = document.createElement('div');
        bubble.className = 'ai-message__bubble';
        if (imageUrl) {
            const image = document.createElement('img');
            image.className = 'ai-message__image';
            image.src = imageUrl;
            image.alt = '添付画像';
            bubble.append(image);
        }
        bubble.append(document.createTextNode(content));
        const metadata = document.createElement('div');
        metadata.className = 'ai-message__meta';
        metadata.textContent = meta;
        article.append(bubble, metadata);
        appendFileChange(article, fileChange);
        chatMessages.append(article);
        if (scroll) scrollChatTo(article);
        return article;
    };
    chatMessages.scrollTop = chatMessages.scrollHeight;
    const chatImageInput = chatForm?.querySelector('[data-chat-image-input]');
    const chatImagePreview = chatForm?.querySelector('[data-chat-image-preview]');
    let chatImageObjectUrl = '';
    const setChatImage = file => {
        if (!file || !['image/png','image/jpeg','image/webp'].includes(file.type)) {
            chatError.textContent = 'PNG・JPG・WebP画像を選択してください。';
            chatError.hidden = false;
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            chatError.textContent = '画像は5MB以内にしてください。';
            chatError.hidden = false;
            return;
        }
        const transfer = new DataTransfer();
        transfer.items.add(file);
        chatImageInput.files = transfer.files;
        if (chatImageObjectUrl) URL.revokeObjectURL(chatImageObjectUrl);
        chatImageObjectUrl = URL.createObjectURL(file);
        chatImagePreview.querySelector('img').src = chatImageObjectUrl;
        chatImagePreview.hidden = false;
        chatError.hidden = true;
    };
    chatForm?.querySelector('[data-chat-image-select]')?.addEventListener('click', () => chatImageInput.click());
    chatImageInput?.addEventListener('change', () => setChatImage(chatImageInput.files[0]));
    chatForm?.querySelector('[data-chat-image-remove]')?.addEventListener('click', () => {
        chatImageInput.value = '';
        chatImagePreview.hidden = true;
        if (chatImageObjectUrl) URL.revokeObjectURL(chatImageObjectUrl);
        chatImageObjectUrl = '';
    });
    chatForm?.elements.content.addEventListener('paste', event => {
        const image = [...event.clipboardData.files].find(file => file.type.startsWith('image/'));
        if (image) setChatImage(image);
    });
    const hashText = async text => {
        const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
        return [...new Uint8Array(bytes)].map(value => value.toString(16).padStart(2, '0')).join('');
    };
    const requestLocalWritePermission = () => {
        if (!localDirectoryHandle) {
            throw new Error('ローカルフォルダの準備ができていません。FILESを開いてから、もう一度お試しください。');
        }
        return localDirectoryHandle.requestPermission({mode:'readwrite'});
    };
    const resolveLocalFileHandle = async path => {
        if (!localDirectoryHandle) throw new Error('Project設定からローカルフォルダを選択してください。');
        const parts = path.replaceAll('\\', '/').split('/').filter(Boolean);
        const fileName = parts.pop();
        let directory = localDirectoryHandle;
        for (const part of parts) directory = await directory.getDirectoryHandle(part);
        return directory.getFileHandle(fileName);
    };
    const saveLocalBackup = (path, content) => new Promise((resolve, reject) => {
        const request = indexedDB.open('rise-gate-file-backups', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('backups', {keyPath:'id'});
        request.onerror = () => reject(request.error);
        request.onsuccess = () => {
            const transaction = request.result.transaction('backups', 'readwrite');
            const id = `${workbench.dataset.localHandleKey}:${path}:${Date.now()}`;
            transaction.objectStore('backups').put({id, path, content, createdAt:new Date().toISOString()});
            transaction.oncomplete = () => resolve(id);
            transaction.onerror = () => reject(transaction.error);
        };
    });
    workbench.addEventListener('click', async event => {
        const apply = event.target.closest('[data-file-change-apply]');
        if (!apply) return;
        const path = apply.dataset.filePath;
        const status = apply.closest('[data-file-change]').querySelector('[data-file-change-status]');
        if (/(^|\/)\.env($|[./])|^(vendor|storage|\.git)(\/|$)/i.test(path)) {
            status.textContent = 'このファイルは保護対象のため変更できません。';
            return;
        }
        apply.disabled = true;
        status.textContent = '書き込み許可を確認しています…';
        try {
            const permission = await requestLocalWritePermission();
            if (permission !== 'granted') throw new Error('ローカルファイルへの書き込み許可が必要です。');
            if (!confirm(`「${path}」へ、この変更案を反映しますか？`)) {
                status.textContent = '';
                apply.disabled = false;
                return;
            }
            status.textContent = '現在のファイルを確認しています…';
            const handle = await resolveLocalFileHandle(path);
            const current = await (await handle.getFile()).text();
            if (await hashText(current) !== apply.dataset.originalHash) {
                throw new Error('提案後にファイルが変更されています。最新内容でAIへ再度依頼してください。');
            }
            await saveLocalBackup(path, current);
            const proposed = apply.closest('[data-file-change]').querySelector('[data-file-change-content]').value;
            const writable = await handle.createWritable();
            await writable.write(proposed);
            await writable.close();
            const fileButton = [...workbench.querySelectorAll('[data-file-name]')].find(item => item.dataset.fileName === path);
            if (fileButton) fileButton.dataset.fileCopy = proposed;
            const tab = tabs.querySelector(`[data-workspace-tab="${CSS.escape(`file:${path}`)}"]`);
            if (tab) tab.dataset.tabContent = proposed;
            if (workbench.querySelector('[data-file-title]').textContent === path) renderCode(proposed);
            await fetch(apply.dataset.applyUrl, {
                method:'POST',
                headers:{'Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())},
            });
            const proposal = apply.closest('[data-file-change]');
            proposal.classList.add('is-applied');
            proposal.querySelector('summary').textContent = '反映済み';
            apply.remove();
            status.textContent = 'ローカルファイルへ反映しました。変更前の内容はこのブラウザにバックアップ済みです。';
        } catch (error) {
            status.textContent = error.message;
            apply.disabled = false;
        }
    });
    chatForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const textarea = chatForm.elements.content;
        const content = textarea.value.trim();
        if (!content) return;
        const submit = chatForm.querySelector('button[type="submit"]');
        const payload = new FormData(chatForm);
        chatError.hidden = true;
        const attachedImageUrl = chatImageObjectUrl;
        const userMessage = appendMessage('user', content, '送信中', false, attachedImageUrl);
        const pending = appendMessage('assistant', '考えています…', '', true);
        textarea.value = '';
        submit.disabled = true;
        try {
            const response = await fetch(chatForm.dataset.chatUrl, {
                method: 'POST',
                headers: {'Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())},
                body: payload,
            });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message || 'AIから回答を取得できませんでした。');
            const message = body.message;
            pending.remove();
            userMessage.querySelector('.ai-message__meta').textContent = 'ただ今';
            const tokens = Number(message.input_tokens || 0) + Number(message.output_tokens || 0);
            appendMessage('assistant', message.content, 'ただ今', false, '', message.file_change, false);
            scrollChatTo(userMessage, 'start');
            chatImageInput.value = '';
            chatImagePreview.hidden = true;
            chatImageObjectUrl = '';
            const points = workbench.querySelector('[data-chat-points]');
            const totalTokens = Number(points.dataset.totalTokens || 0) + tokens;
            points.dataset.totalTokens = totalTokens;
            points.textContent = `${(totalTokens > 0 ? Math.max(1, Math.round(totalTokens / 1000)) : 0).toLocaleString()}ポイント`;
        } catch (error) {
            pending.remove();
            userMessage.querySelector('.ai-message__meta').textContent = '送信できませんでした';
            textarea.value = content;
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
