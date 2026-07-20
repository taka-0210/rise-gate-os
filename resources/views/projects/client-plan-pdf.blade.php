<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 12mm 14mm 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #18232c; font-family: "IPAexGothic", sans-serif; font-size: 10px; line-height: 1.55; }
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
        .logo { display: block; width: auto; max-width: 58mm; height: auto; max-height: 20mm; margin-bottom: 3mm; }
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
        .schedule tr { page-break-inside: avoid; }
        .schedule th { background: #f2f5f7; color: #173f50; }
        .schedule .item { width: 39%; }
        .schedule .date { width: 16%; white-space: nowrap; }
        .schedule .kind { width: 12%; }
        .kind-roadmap { color: #386cab; }
        .kind-improvement { color: #3e8665; }
        .kind-task { color: #ad5947; }
        .gantt { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 7px; }
        .gantt thead { display: table-header-group; }
        .gantt tr { page-break-inside: avoid; }
        .gantt th, .gantt td { border: 1px solid #d7e0e6; }
        .gantt .gantt-label { width: 39%; padding: 1.4mm 2mm; text-align: left; }
        .gantt .gantt-date { height: 9mm; padding: .5mm 0; background: #f2f5f7; color: #60717e; font-size: 6px; text-align: center; vertical-align: middle; }
        .gantt .gantt-slot { height: 5.5mm; padding: 0; background: #fff; }
        .gantt .gantt-slot.active-roadmap { background: #4f82c4; }
        .gantt .gantt-slot.active-improvement { background: #56a27e; }
        .gantt .gantt-slot.active-task { background: #c7735f; }
        .gantt .label-roadmap { color: #386cab; font-weight: bold; }
        .gantt .label-improvement { padding-left: 4mm; color: #3e8665; }
        .gantt .label-task { padding-left: 8mm; color: #ad5947; }
        .gantt-legend { margin-bottom: 2mm; color: #60717e; font-size: 8px; text-align: right; }
        .gantt-legend span { display: inline-block; margin-left: 4mm; }
        .gantt-legend i { display: inline-block; width: 5mm; height: 2mm; margin-right: 1mm; vertical-align: middle; }
        .detail-kind { width: 18%; font-weight: bold; }
        .detail-item { width: 30%; }
        .detail-description { width: 32%; color: #60717e; }
        .detail-period { width: 20%; white-space: nowrap; }
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
    $ganttRows = collect();
    foreach ($roadmaps as $roadmap) {
        $ganttRows->push(['type' => 'roadmap', 'title' => $roadmap->title, 'start' => $roadmap->planned_start_date, 'end' => $roadmap->target_date]);
        foreach ($roadmap->improvements as $improvement) {
            $ganttRows->push(['type' => 'improvement', 'title' => $improvement->title, 'start' => $improvement->planned_start_date, 'end' => $improvement->target_date]);
            if ($showTasks) {
                foreach ($improvement->tasks as $task) {
                    $ganttRows->push(['type' => 'task', 'title' => $task->title, 'start' => $task->planned_start_date, 'end' => $task->due_date]);
                }
            }
        }
    }
    $ganttDates = $ganttRows->flatMap(fn ($row) => [$row['start'], $row['end']])->filter();
    $ganttStart = ($ganttDates->min() ?: $project->start_date ?: now())->copy()->startOfDay();
    $ganttEnd = ($ganttDates->max() ?: $project->due_date ?: $ganttStart->copy()->addDays(27))->copy()->startOfDay();
    if ($ganttEnd->lt($ganttStart)) $ganttEnd = $ganttStart->copy();
    $ganttTotalDays = $ganttStart->diffInDays($ganttEnd) + 1;
    $ganttSlotDays = max(1, (int) ceil($ganttTotalDays / 28));
    $ganttSlots = collect();
    for ($offset = 0; $offset < $ganttTotalDays; $offset += $ganttSlotDays) {
        $slotStart = $ganttStart->copy()->addDays($offset);
        $slotEnd = $slotStart->copy()->addDays($ganttSlotDays - 1)->min($ganttEnd);
        $ganttSlots->push(['start' => $slotStart, 'end' => $slotEnd]);
    }
@endphp

<div class="footer"><span class="issuer">{{ $issuerName }} / Confidential</span><span class="meta">Ver. {{ $documentOptions['version'] ?: '1.0' }}　{{ \Carbon\Carbon::parse($documentOptions['prepared_on'])->format('Y/m/d') }}</span></div>
<div class="page-number"></div>

<section class="cover">
    @if($logoDataUri)<img class="logo" src="{{ $logoDataUri }}" alt="{{ $issuerName }}">@endif
    @unless($logoDataUri)<div class="issuer-name">{{ $issuerName }}</div>@endunless
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
    <div class="gantt-legend"><span><i style="background:#4f82c4"></i>ロードマップ</span><span><i style="background:#56a27e"></i>取り組み</span>@if($showTasks)<span><i style="background:#c7735f"></i>タスク</span>@endif</div>
    @if($ganttRows->isEmpty())
        <div class="empty">掲載対象のスケジュールはありません。</div>
    @else
        <table class="gantt">
            <thead><tr><th class="gantt-label">計画項目</th>@foreach($ganttSlots as $slot)<th class="gantt-date">{{ $slot['start']->format('n/j') }}@if($ganttSlotDays > 1)<br>～{{ $slot['end']->format('n/j') }}@endif</th>@endforeach</tr></thead>
            <tbody>
            @foreach($ganttRows as $row)
                <tr>
                    <td class="gantt-label label-{{ $row['type'] }}">{{ $row['title'] }}</td>
                    @foreach($ganttSlots as $slot)
                        @php $active = $row['start'] && $row['end'] && $row['start']->lte($slot['end']) && $row['end']->gte($slot['start']); @endphp
                        <td class="gantt-slot {{ $active ? 'active-'.$row['type'] : '' }}"></td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</section>

<section class="page-break">
    <h2>3. ロードマップ詳細</h2>
    @if($roadmaps->isEmpty())
        <div class="empty">掲載対象のロードマップはありません。</div>
    @else
        <table class="schedule">
            <thead><tr><th class="detail-kind">区分</th><th class="detail-item">項目</th><th class="detail-description">目的・内容</th><th class="detail-period">期間・進捗</th></tr></thead>
            <tbody>
            @foreach($roadmaps as $roadmap)
                <tr>
                    <td class="detail-kind kind-roadmap">ロードマップ {{ $loop->iteration }}</td>
                    <td><strong>{{ $roadmap->title }}</strong></td>
                    <td class="detail-description">{{ $roadmap->purpose ?: '―' }}</td>
                    <td class="detail-period">{{ $formatDate($roadmap->planned_start_date) }} ～ {{ $formatDate($roadmap->target_date) }}@if($showProgress)<br>{{ $roadmapStatuses[$roadmap->status] ?? $roadmap->status }}@endif</td>
                </tr>
                @foreach($roadmap->improvements as $improvement)
                    <tr>
                        <td class="detail-kind kind-improvement">取り組み {{ $loop->iteration }}</td>
                        <td>{{ $improvement->title }}</td>
                        <td class="detail-description">{{ $improvement->desired_state ?: ($improvement->action ?: '―') }}</td>
                        <td class="detail-period">{{ $formatDate($improvement->planned_start_date) }} ～ {{ $formatDate($improvement->target_date) }}@if($showProgress)<br>{{ $improvementStatuses[$improvement->status] ?? $improvement->status }}@endif</td>
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    @endif
</section>

<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->get_font('IPAexGothic', 'normal');
        $pdf->page_text(744, 568, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.09, 0.25, 0.31]);
    }
</script>
</body>
</html>
