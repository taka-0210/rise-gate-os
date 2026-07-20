<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <style>
        @font-face {
            font-family: "Noto Sans JP";
            font-style: normal;
            font-weight: 400;
            src: url("file:///{{ str_replace('\\', '/', resource_path('fonts/NotoSansCJKjp-Regular.otf')) }}") format("opentype");
        }
        @page { margin: 20mm 14mm 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #18232c; font-family: "Noto Sans JP", sans-serif; font-size: 10px; line-height: 1.55; }
        .header { position: fixed; top: -14mm; left: 0; right: 0; height: 9mm; border-bottom: 1px solid #d7e0e6; color: #687985; font-size: 8px; }
        .header .title { float: left; }
        .header .project { float: right; color: #173f50; font-weight: bold; }
        .footer { position: fixed; bottom: -11mm; left: 0; right: 0; height: 7mm; border-top: 1px solid #d7e0e6; color: #75858f; font-size: 8px; padding-top: 2mm; }
        .footer .issuer { float: left; }
        .footer .meta { float: right; padding-right: 20mm; }
        .page-number { position: fixed; right: 0; bottom: -9mm; width: 18mm; color: #173f50; font-size: 8px; font-weight: bold; text-align: right; }
        .page-break { page-break-before: always; }
        h1 { margin: 0 0 6mm; font-size: 28px; line-height: 1.3; }
        h2 { margin: 0 0 6mm; padding-bottom: 2.5mm; border-bottom: 2px solid #173f50; font-size: 20px; }
        h3 { margin: 0 0 2mm; font-size: 13px; }
        p { margin: 1.5mm 0; color: #60717e; }
        .cover { height: 160mm; border-top: 7px solid #173f50; padding: 16mm 14mm 8mm; }
        .issuer-name { color: #173f50; font-size: 17px; font-weight: bold; }
        .issuer-details { margin-top: 2mm; color: #60717e; }
        .eyebrow { margin-top: 25mm; color: #4f82c4; font-size: 11px; font-weight: bold; letter-spacing: 2px; }
        .client { margin: 0 0 8mm; color: #60717e; font-size: 14px; font-weight: bold; }
        .project-name { font-size: 19px; font-weight: bold; }
        .cover-meta { width: 100%; margin-top: 25mm; border-collapse: collapse; }
        .cover-meta th, .cover-meta td { padding: 2mm 3mm; border-bottom: 1px solid #d7e0e6; text-align: left; }
        .cover-meta th { width: 30mm; color: #60717e; font-weight: normal; }
        .stats { width: 100%; margin: 5mm 0 7mm; border-collapse: separate; border-spacing: 3mm 0; }
        .stat { padding: 4mm; border: 1px solid #d7e0e6; background: #f8fafb; text-align: center; }
        .stat strong { display: block; color: #173f50; font-size: 22px; }
        .roadmap-card { margin: 0 0 3mm; padding: 3.5mm 4mm; border-left: 5px solid #4f82c4; background: #f5f8fc; page-break-inside: avoid; }
        .period { color: #60717e; font-size: 8px; }
        .schedule { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .schedule th, .schedule td { padding: 2mm; border: 1px solid #d7e0e6; vertical-align: middle; }
        .schedule th { background: #f2f5f7; color: #173f50; }
        .schedule .item { width: 39%; }
        .schedule .date { width: 16%; white-space: nowrap; }
        .schedule .kind { width: 12%; }
        .kind-roadmap { color: #386cab; }
        .kind-improvement { color: #3e8665; }
        .kind-task { color: #ad5947; }
        .roadmap-detail { margin-bottom: 5mm; padding: 4mm; border: 1px solid #bfd1e8; page-break-inside: avoid; }
        .improvement { margin-top: 3mm; padding: 3mm 4mm; border-left: 4px solid #56a27e; background: #f5faf7; page-break-inside: avoid; }
        .tasks { margin: 2mm 0 0; padding-left: 6mm; }
        .tasks li { margin: 1.5mm 0; }
        .status { display: inline-block; margin-left: 2mm; padding: .3mm 2mm; border: 1px solid #cbd5dc; border-radius: 8px; color: #60717e; font-size: 7px; }
        .empty { padding: 8mm; background: #f5f8fa; text-align: center; }
    </style>
</head>
<body>
@php
    $businessProfile = $project->owningWorkspace->businessProfile;
    $issuerName = $businessProfile?->trade_name ?: ($businessProfile?->legal_name ?: $project->owningWorkspace->name);
    $showTasks = $documentOptions['show_tasks'];
    $showProgress = $documentOptions['show_progress'];
    $formatDate = fn ($date) => $date?->format('Y年n月j日') ?? '未設定';
@endphp

<div class="header"><span class="title">プロジェクト実施計画書</span><span class="project">{{ $project->name }}</span></div>
<div class="footer"><span class="issuer">{{ $issuerName }} / Confidential</span><span class="meta">Ver. {{ $documentOptions['version'] ?: '1.0' }}　{{ \Carbon\Carbon::parse($documentOptions['prepared_on'])->format('Y/m/d') }}</span></div>
<div class="page-number"></div>

<section class="cover">
    <div class="issuer-name">{{ $issuerName }}</div>
    @if($businessProfile)
        <div class="issuer-details">
            @if($businessProfile->legal_name && $businessProfile->legal_name !== $issuerName)<div>{{ $businessProfile->legal_name }}</div>@endif
            @if($businessProfile->postal_code || $businessProfile->address_line1)<div>〒{{ $businessProfile->postal_code }} {{ $businessProfile->address_line1 }} {{ $businessProfile->address_line2 }}</div>@endif
            @if($businessProfile->phone)<div>TEL {{ $businessProfile->phone }}</div>@endif
        </div>
    @endif
    <div class="eyebrow">PROJECT IMPLEMENTATION PLAN</div>
    <h1>プロジェクト実施計画書</h1>
    <div class="client">{{ $project->client?->name ?? 'クライアント未設定' }} 御中</div>
    <div class="project-name">{{ $project->name }}</div>
    <table class="cover-meta">
        <tr><th>プロジェクト期間</th><td>{{ $formatDate($project->start_date) }} ～ {{ $formatDate($project->due_date) }}</td></tr>
        <tr><th>作成日</th><td>{{ \Carbon\Carbon::parse($documentOptions['prepared_on'])->format('Y年n月j日') }}</td></tr>
        <tr><th>作成者</th><td>{{ $documentOptions['prepared_by'] ?: '未設定' }}</td></tr>
        <tr><th>版番号</th><td>Ver. {{ $documentOptions['version'] ?: '1.0' }}</td></tr>
    </table>
</section>

<section class="page-break">
    <h2>1. プロジェクト全体概要</h2>
    <h3>{{ $project->name }}</h3>
    <p>{{ $project->summary ?: 'プロジェクト概要は現在整理中です。' }}</p>
    <table class="stats"><tr>
        <td class="stat"><strong>{{ $roadmaps->count() }}</strong>ロードマップ</td>
        <td class="stat"><strong>{{ $visibleImprovements->count() }}</strong>取り組み</td>
        <td class="stat"><strong>{{ $visibleTasks->count() }}</strong>タスク</td>
    </tr></table>
    @forelse($roadmaps as $roadmap)
        <article class="roadmap-card">
            <h3>{{ $loop->iteration }}. {{ $roadmap->title }}</h3>
            @if($roadmap->purpose)<p>{{ $roadmap->purpose }}</p>@endif
            <div class="period">予定：{{ $formatDate($roadmap->planned_start_date) }} ～ {{ $formatDate($roadmap->target_date) }} ／ 取り組み {{ $roadmap->improvements->count() }}件</div>
        </article>
    @empty
        <div class="empty">掲載対象のロードマップはありません。</div>
    @endforelse
</section>

<section class="page-break">
    <h2>2. 全体スケジュール</h2>
    <table class="schedule">
        <thead><tr><th class="kind">区分</th><th class="item">計画項目</th><th class="date">開始</th><th class="date">完了</th><th>進捗</th></tr></thead>
        <tbody>
        @foreach($roadmaps as $roadmap)
            <tr><td class="kind-roadmap">ロードマップ</td><td><strong>{{ $roadmap->title }}</strong></td><td>{{ $formatDate($roadmap->planned_start_date) }}</td><td>{{ $formatDate($roadmap->target_date) }}</td><td>{{ $showProgress ? ($roadmapStatuses[$roadmap->status] ?? $roadmap->status) : '―' }}</td></tr>
            @foreach($roadmap->improvements as $improvement)
                <tr><td class="kind-improvement">取り組み</td><td>　{{ $improvement->title }}</td><td>{{ $formatDate($improvement->planned_start_date) }}</td><td>{{ $formatDate($improvement->target_date) }}</td><td>{{ $showProgress ? ($improvementStatuses[$improvement->status] ?? $improvement->status) : '―' }}</td></tr>
                @if($showTasks) @foreach($improvement->tasks as $task)
                    <tr><td class="kind-task">タスク</td><td>　　{{ $task->title }}</td><td>{{ $formatDate($task->planned_start_date) }}</td><td>{{ $formatDate($task->due_date) }}</td><td>{{ $showProgress ? ($taskStatuses[$task->status] ?? $task->status) : '―' }}</td></tr>
                @endforeach @endif
            @endforeach
        @endforeach
        </tbody>
    </table>
</section>

<section class="page-break">
    <h2>3. ロードマップ詳細</h2>
    @forelse($roadmaps as $roadmap)
        <article class="roadmap-detail">
            <h3>ロードマップ {{ $loop->iteration }}：{{ $roadmap->title }} @if($showProgress)<span class="status">{{ $roadmapStatuses[$roadmap->status] ?? $roadmap->status }}</span>@endif</h3>
            @if($roadmap->purpose)<p>{{ $roadmap->purpose }}</p>@endif
            <div class="period">{{ $formatDate($roadmap->planned_start_date) }} ～ {{ $formatDate($roadmap->target_date) }}</div>
            @foreach($roadmap->improvements as $improvement)
                <div class="improvement">
                    <h3>取り組み {{ $loop->iteration }}：{{ $improvement->title }} @if($showProgress)<span class="status">{{ $improvementStatuses[$improvement->status] ?? $improvement->status }}</span>@endif</h3>
                    @if($improvement->desired_state || $improvement->action)<p>{{ $improvement->desired_state ?: $improvement->action }}</p>@endif
                    <div class="period">{{ $formatDate($improvement->planned_start_date) }} ～ {{ $formatDate($improvement->target_date) }}</div>
                    @if($showTasks && $improvement->tasks->isNotEmpty())
                        <ol class="tasks">@foreach($improvement->tasks as $task)<li>{{ $task->title }} @if($showProgress)<span class="status">{{ $taskStatuses[$task->status] ?? $task->status }}</span>@endif <span class="period">{{ $formatDate($task->planned_start_date) }}～{{ $formatDate($task->due_date) }}</span></li>@endforeach</ol>
                    @endif
                </div>
            @endforeach
        </article>
    @empty
        <div class="empty">掲載対象のロードマップはありません。</div>
    @endforelse
</section>

<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->get_font('Noto Sans JP', 'normal');
        $pdf->page_text(744, 568, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.09, 0.25, 0.31]);
    }
</script>
</body>
</html>
