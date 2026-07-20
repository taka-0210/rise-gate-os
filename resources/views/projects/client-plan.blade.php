@php
    $businessProfile = $project->owningWorkspace->businessProfile;
    $issuerName = $businessProfile?->trade_name ?: ($businessProfile?->legal_name ?: $project->owningWorkspace->name);
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>プロジェクト実施計画書 - {{ $project->name }}</title>
    <style>
        :root { --ink:#18232c; --muted:#60717e; --line:#d7e0e6; --navy:#173f50; --blue:#4f82c4; --green:#56a27e; --red:#c7735f; --paper:#f1f4f6; }
        * { box-sizing:border-box; }
        body { margin:0; color:var(--ink); background:var(--paper); font-family:"Segoe UI","Noto Sans JP",sans-serif; }
        .controls { position:sticky; z-index:20; top:0; padding:14px 20px; border-bottom:1px solid var(--line); background:rgba(255,255,255,.97); box-shadow:0 3px 14px rgba(22,45,58,.08); }
        .controls-inner { width:min(1180px,100%); margin:auto; display:flex; align-items:end; gap:10px; flex-wrap:wrap; }
        .controls label { display:grid; gap:4px; color:var(--muted); font-size:12px; }
        .controls input { min-height:38px; padding:7px 9px; border:1px solid var(--line); border-radius:6px; background:#fff; font:inherit; }
        .controls .check { display:flex; align-items:center; gap:6px; min-height:38px; color:var(--ink); }
        .controls .check input { min-height:auto; }
        button,.button { display:inline-flex; min-height:38px; align-items:center; justify-content:center; padding:8px 13px; border:0; border-radius:6px; background:var(--navy); color:#fff; font-weight:700; text-decoration:none; cursor:pointer; }
        button:disabled { opacity:.55; cursor:wait; }
        .button.secondary { border:1px solid var(--line); background:#fff; color:var(--navy); }
        .preview-status { display:none; width:100%; color:var(--muted); font-size:12px; }
        body.is-paginating .preview-status { display:block; }
        #print-source { position:absolute; left:-100000px; width:269mm; visibility:hidden; }
        #paged-output { padding:24px 0 60px; }
        .pagedjs_pages { display:flex; flex-direction:column; align-items:center; gap:22px; }
        .pagedjs_page { margin:0!important; background:#fff; box-shadow:0 8px 30px rgba(28,50,64,.13); }
        .page-section { break-after:page; page-break-after:always; }
        .cover { break-after:page; page-break-after:always; }
        .cover { min-height:174mm; display:flex; flex-direction:column; justify-content:space-between; border-top:9px solid var(--navy); padding-top:12mm; }
        .logo { width:205px; height:auto; max-height:44mm; object-fit:contain; object-position:left center; }
        .issuer-name { color:var(--navy); font-size:23px; font-weight:900; }
        .issuer-details { margin-top:8px; color:var(--muted); font-size:12px; line-height:1.6; }
        .eyebrow { margin-top:20mm; color:var(--blue); font-weight:800; letter-spacing:.12em; }
        h1 { margin:8px 0 12px; font-size:38px; line-height:1.25; }
        h2 { margin:0 0 18px; padding-bottom:9px; border-bottom:2px solid var(--navy); font-size:24px; }
        h3 { margin:0 0 8px; font-size:18px; }
        p { color:var(--muted); line-height:1.75; }
        .cover-client { font-size:20px; font-weight:800; }
        .cover-meta { display:grid; grid-template-columns:150px 1fr; gap:7px 18px; padding-top:12px; border-top:1px solid var(--line); font-size:14px; }
        .cover-meta dt { color:var(--muted); }
        .cover-meta dd { margin:0; font-weight:700; }
        .lead { font-size:16px; color:#394a55; }
        .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin:20px 0 28px; }
        .stat { padding:16px; border:1px solid var(--line); border-radius:8px; background:#f8fafb; }
        .stat strong { display:block; color:var(--navy); font-size:30px; }
        .roadmap-overview { display:grid; gap:11px; }
        .roadmap-overview article { padding:14px 16px; border-left:6px solid var(--blue); background:#f5f8fc; break-inside:avoid; }
        .period { color:var(--muted); font-size:12px; }
        .schedule-wrap { overflow:hidden; border:1px solid var(--line); border-radius:8px; }
        .schedule-axis,.schedule-row { display:grid; grid-template-columns:30% 70%; }
        .schedule-axis { border-bottom:1px solid var(--line); background:#f6f8fa; }
        .schedule-label { min-width:0; padding:8px 10px; border-right:1px solid var(--line); }
        .schedule-label strong { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:12px; }
        .schedule-track { position:relative; min-height:34px; background-image:linear-gradient(to right,rgba(69,91,105,.12) 1px,transparent 1px); background-size:12.5% 100%; }
        .schedule-date { position:absolute; top:8px; color:var(--muted); font-size:10px; transform:translateX(-50%); }
        .schedule-date.first { left:4px!important; transform:none; }
        .schedule-date.last { right:4px; left:auto!important; transform:none; }
        .schedule-row { border-bottom:1px solid var(--line); break-inside:avoid; }
        .schedule-row:last-child { border-bottom:0; }
        .schedule-row.improvement .schedule-label { padding-left:22px; }
        .schedule-row.task .schedule-label { padding-left:36px; }
        .schedule-bar { position:absolute; top:10px; height:14px; min-width:5px; border-radius:999px; }
        .schedule-bar.project { background:var(--navy); }
        .schedule-bar.roadmap { background:var(--blue); }
        .schedule-bar.improvement { background:var(--green); }
        .schedule-bar.task { background:var(--red); }
        .legend { display:flex; justify-content:flex-end; gap:12px; margin:0 0 8px; color:var(--muted); font-size:11px; }
        .legend span::before { content:""; display:inline-block; width:18px; height:7px; margin-right:5px; border-radius:999px; background:var(--color); }
        .roadmap-detail { margin-bottom:18px; padding:16px; border:1px solid #c8d9ed; border-radius:8px; break-inside:avoid; }
        .improvement-detail { margin-top:12px; padding:12px 14px; border-left:5px solid var(--green); background:#f5faf7; break-inside:avoid; }
        .task-list { margin:9px 0 0; padding-left:22px; }
        .task-list li { margin:6px 0; }
        .status { display:inline-flex; margin-left:6px; padding:2px 7px; border:1px solid var(--line); border-radius:999px; background:#fff; color:var(--muted); font-size:10px; }
        .continued { color:var(--muted); font-size:12px; font-weight:500; }
        @page {
            size:297mm 210mm;
            margin:13mm 14mm 15mm;
            @bottom-left { content:"{{ $issuerName ?? '' }} / Confidential"; color:#7b8992; font-size:8pt; }
            @bottom-center { content:"Ver. {{ $documentOptions['version'] ?: '1.0' }}　{{ \Carbon\Carbon::parse($documentOptions['prepared_on'])->format('Y/m/d') }}"; color:#7b8992; font-size:8pt; }
            @bottom-right { content:counter(page) " / " counter(pages); color:#173f50; font-size:8pt; font-weight:700; }
        }
        @media print {
            body { background:#fff; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .controls,#print-source { display:none!important; }
            #paged-output { padding:0; }
            .pagedjs_pages { display:block; }
            .pagedjs_page { margin:0!important; box-shadow:none!important; }
        }
        @media (max-width:760px) {
            #paged-output { overflow:auto; }
            .pagedjs_pages { align-items:flex-start; }
        }
    </style>
    <script>window.PagedConfig = { auto: false };</script>
    <script defer src="{{ asset('vendor/pagedjs/paged.polyfill.js') }}"></script>
</head>
<body>
@php
    $showTasks = $documentOptions['show_tasks'];
    $showProgress = $documentOptions['show_progress'];
    $periodText = ($project->start_date?->format('Y年n月j日') ?? '未設定').' 〜 '.($project->due_date?->format('Y年n月j日') ?? '未設定');
    $timelineRows = collect([['type'=>'project','title'=>$project->name,'start'=>$project->start_date,'end'=>$project->due_date]]);
    foreach ($roadmaps as $roadmap) {
        $timelineRows->push(['type'=>'roadmap','title'=>$roadmap->title,'start'=>$roadmap->planned_start_date,'end'=>$roadmap->target_date]);
        foreach ($roadmap->improvements as $improvement) {
            $timelineRows->push(['type'=>'improvement','title'=>$improvement->title,'start'=>$improvement->planned_start_date,'end'=>$improvement->target_date]);
            if ($showTasks) foreach ($improvement->tasks as $task) {
                $timelineRows->push(['type'=>'task','title'=>$task->title,'start'=>$task->planned_start_date,'end'=>$task->due_date]);
            }
        }
    }
    $dates = $timelineRows->flatMap(fn($row)=>[$row['start'],$row['end']])->filter();
    $axisStart = $dates->min() ?: now()->startOfDay();
    $axisEnd = $dates->max() ?: $axisStart->copy()->addMonth();
    if ($axisEnd->lte($axisStart)) $axisEnd = $axisStart->copy()->addDays(14);
    $axisDays = max(1,$axisStart->diffInDays($axisEnd));
    $tickStep = max(1,(int)ceil($axisDays/8));
    $ticks = collect(range(0,$axisDays,$tickStep))->push($axisDays)->unique()->sort()->values();
    $overviewChunks = $roadmaps->chunk(5);
    $scheduleChunks = $timelineRows->chunk(18);
@endphp

<form class="controls" method="GET" action="{{ route('projects.client-plan', $project) }}">
    <div class="controls-inner">
        <a class="button secondary" href="{{ route('projects.show', ['project'=>$project,'view'=>'time']) }}">Projectへ戻る</a>
        <input type="hidden" name="show_tasks" value="0"><label class="check"><input type="checkbox" name="show_tasks" value="1" @checked($showTasks)>タスクを掲載</label>
        <input type="hidden" name="show_progress" value="0"><label class="check"><input type="checkbox" name="show_progress" value="1" @checked($showProgress)>進捗を掲載</label>
        <label>版番号<input name="version" value="{{ $documentOptions['version'] }}"></label>
        <label>作成者<input name="prepared_by" value="{{ $documentOptions['prepared_by'] }}"></label>
        <label>作成日<input type="date" name="prepared_on" value="{{ $documentOptions['prepared_on'] }}"></label>
        <button type="submit">プレビューを更新</button>
        <button type="button" id="print-document" disabled>印刷・PDF保存</button>
        <span class="preview-status">印刷用ページを準備しています…</span>
    </div>
</form>

<main id="print-source">
    <section class="page-section cover">
        <div>
            @if($businessProfile?->logo_path)
                <img class="logo" src="{{ route('projects.business-media', [$project,'logo']) }}" alt="{{ $issuerName }}">
            @else
                <div class="issuer-name">{{ $issuerName }}</div>
            @endif
            @if($businessProfile)
                <div class="issuer-details">
                    @if($businessProfile->legal_name && $businessProfile->legal_name !== $issuerName)<div>{{ $businessProfile->legal_name }}</div>@endif
                    @if($businessProfile->postal_code || $businessProfile->address_line1)<div>〒{{ $businessProfile->postal_code }} {{ $businessProfile->address_line1 }} {{ $businessProfile->address_line2 }}</div>@endif
                    @if($businessProfile->phone)<div>TEL {{ $businessProfile->phone }}</div>@endif
                </div>
            @endif
            <div class="eyebrow">PROJECT IMPLEMENTATION PLAN</div>
            <h1>プロジェクト実施計画書</h1>
            <p class="cover-client">{{ $project->client?->name ?? 'クライアント未設定' }} 御中</p>
            <h2 style="border:0;padding:0;margin-top:28px;">{{ $project->name }}</h2>
        </div>
        <dl class="cover-meta">
            <dt>プロジェクト期間</dt><dd>{{ $periodText }}</dd>
            <dt>作成日</dt><dd>{{ \Carbon\Carbon::parse($documentOptions['prepared_on'])->format('Y年n月j日') }}</dd>
            <dt>作成者</dt><dd>{{ $documentOptions['prepared_by'] ?: '未設定' }}</dd>
            <dt>版番号</dt><dd>Ver. {{ $documentOptions['version'] ?: '1.0' }}</dd>
        </dl>
    </section>

    @forelse($overviewChunks as $overviewChunk)
        <section class="page-section">
            <h2>1. プロジェクト全体概要 @if(!$loop->first)<span class="continued">（続き）</span>@endif</h2>
            @if($loop->first)
                <h3>{{ $project->name }}</h3>
                <p class="lead">{{ $project->summary ?: 'プロジェクト概要は現在整理中です。' }}</p>
                <div class="stats">
                    <div class="stat"><strong>{{ $roadmaps->count() }}</strong>ロードマップ</div>
                    <div class="stat"><strong>{{ $visibleImprovements->count() }}</strong>取り組み</div>
                    <div class="stat"><strong>{{ $visibleTasks->count() }}</strong>タスク</div>
                </div>
            @endif
            <div class="roadmap-overview">
                @foreach($overviewChunk as $roadmap)
                    <article>
                        <h3>{{ $roadmaps->search(fn($item)=>$item->id===$roadmap->id)+1 }}. {{ $roadmap->title }}</h3>
                        @if($roadmap->purpose)<p>{{ $roadmap->purpose }}</p>@endif
                        <div class="period">予定：{{ $roadmap->planned_start_date?->format('Y/n/j') ?? '未設定' }} 〜 {{ $roadmap->target_date?->format('Y/n/j') ?? '未設定' }} / 取り組み {{ $roadmap->improvements->count() }}件</div>
                    </article>
                @endforeach
            </div>
        </section>
    @empty
        <section class="page-section"><h2>1. プロジェクト全体概要</h2><p>掲載対象のロードマップはありません。</p></section>
    @endforelse

    @foreach($scheduleChunks as $scheduleChunk)
        <section class="page-section">
            <h2>2. 全体スケジュール @if(!$loop->first)<span class="continued">（続き）</span>@endif</h2>
            <div class="legend"><span style="--color:var(--navy)">プロジェクト</span><span style="--color:var(--blue)">ロードマップ</span><span style="--color:var(--green)">取り組み</span>@if($showTasks)<span style="--color:var(--red)">タスク</span>@endif</div>
            <div class="schedule-wrap">
                <div class="schedule-axis"><div class="schedule-label"><strong>計画項目</strong></div><div class="schedule-track">@foreach($ticks as $tick)<span class="schedule-date {{ $loop->first?'first':'' }} {{ $loop->last?'last':'' }}" style="left:{{ $tick/$axisDays*100 }}%">{{ $axisStart->copy()->addDays($tick)->format('n/j') }}</span>@endforeach</div></div>
                @foreach($scheduleChunk as $row)
                    @php
                        $left=$row['start']?max(0,min(100,$axisStart->diffInDays($row['start'],false)/$axisDays*100)):0;
                        $width=($row['start']&&$row['end'])?max(.7,$row['start']->diffInDays($row['end'])/$axisDays*100):0;
                    @endphp
                    <div class="schedule-row {{ $row['type'] }}"><div class="schedule-label"><strong>{{ $row['title'] }}</strong></div><div class="schedule-track">@if($row['start']&&$row['end'])<span class="schedule-bar {{ $row['type'] }}" style="left:{{ $left }}%;width:{{ $width }}%"></span>@endif</div></div>
                @endforeach
            </div>
        </section>
    @endforeach

    <section>
        <h2>3. ロードマップ詳細</h2>
        @forelse($roadmaps as $roadmap)
            <article class="roadmap-detail">
                <h3>ロードマップ {{ $loop->iteration }}：{{ $roadmap->title }} @if($showProgress)<span class="status">{{ $roadmapStatuses[$roadmap->status] ?? $roadmap->status }}</span>@endif</h3>
                @if($roadmap->purpose)<p>{{ $roadmap->purpose }}</p>@endif
                <div class="period">{{ $roadmap->planned_start_date?->format('Y年n月j日') ?? '未設定' }} 〜 {{ $roadmap->target_date?->format('Y年n月j日') ?? '未設定' }}</div>
                @foreach($roadmap->improvements as $improvement)
                    <section class="improvement-detail">
                        <h3>取り組み {{ $loop->iteration }}：{{ $improvement->title }} @if($showProgress)<span class="status">{{ $improvementStatuses[$improvement->status] ?? $improvement->status }}</span>@endif</h3>
                        @if($improvement->desired_state || $improvement->action)<p>{{ $improvement->desired_state ?: $improvement->action }}</p>@endif
                        <div class="period">{{ $improvement->planned_start_date?->format('Y年n月j日') ?? '未設定' }} 〜 {{ $improvement->target_date?->format('Y年n月j日') ?? '未設定' }}</div>
                        @if($showTasks && $improvement->tasks->isNotEmpty())
                            <ol class="task-list">@foreach($improvement->tasks as $task)<li>{{ $task->title }} @if($showProgress)<span class="status">{{ $taskStatuses[$task->status] ?? $task->status }}</span>@endif <span class="period">{{ $task->planned_start_date?->format('Y/n/j') ?? '未設定' }}〜{{ $task->due_date?->format('Y/n/j') ?? '未設定' }}</span></li>@endforeach</ol>
                        @endif
                    </section>
                @endforeach
            </article>
        @empty
            <p>掲載対象のロードマップはありません。</p>
        @endforelse
    </section>
</main>
<div id="paged-output" aria-live="polite"></div>

<script>
document.addEventListener('DOMContentLoaded', async () => {
    const source = document.getElementById('print-source');
    const output = document.getElementById('paged-output');
    const printButton = document.getElementById('print-document');
    document.body.classList.add('is-paginating');

    try {
        if (document.fonts?.ready) await document.fonts.ready;
        await window.PagedPolyfill.preview(source, [], output);
        printButton.disabled = false;
    } catch (error) {
        console.error('印刷プレビューの生成に失敗しました。', error);
        output.innerHTML = '<p style="margin:40px;text-align:center">印刷プレビューを生成できませんでした。画面を再読み込みしてください。</p>';
    } finally {
        document.body.classList.remove('is-paginating');
    }

    printButton.addEventListener('click', () => window.print());
});
</script>
</body>
</html>
