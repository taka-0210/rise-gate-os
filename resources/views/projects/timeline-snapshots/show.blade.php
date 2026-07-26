@extends('layouts.app', ['title' => ($version->title ?: '保存タイムライン').' - '.$project->name])

@section('content')
<style>
    .saved-timeline-page{position:relative;left:50%;width:min(1560px,calc(100vw - 40px));transform:translateX(-50%)}
    .saved-timeline-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}
    .saved-timeline-meta{display:flex;flex-wrap:wrap;gap:8px}
    .saved-timeline-meta span{padding:5px 9px;border:1px solid var(--line);border-radius:999px;background:#fff;font-size:12px}
    .saved-timeline-scroll{overflow-x:auto}
    .saved-timeline-chart{min-width:850px;border:1px solid var(--line);border-radius:10px;overflow:hidden}
    .saved-timeline-axis,.saved-timeline-row{display:grid;grid-template-columns:260px minmax(560px,1fr)}
    .saved-timeline-axis{border-bottom:1px solid var(--line);background:#f8fafb}
    .saved-timeline-label{display:flex;align-items:center;gap:8px;min-width:0;padding:11px 13px;border-right:1px solid var(--line)}
    .saved-timeline-label strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .saved-timeline-track{position:relative;min-height:46px;background:repeating-linear-gradient(90deg,#f7f9fa 0,#f7f9fa calc(10% - 1px),#e1e7ea calc(10% - 1px),#e1e7ea 10%)}
    .saved-timeline-axis .saved-timeline-track{display:flex;align-items:center;justify-content:space-between;padding:0 10px;color:var(--muted);font-size:11px}
    .saved-timeline-row{border-bottom:1px solid var(--line)}
    .saved-timeline-row:last-child{border-bottom:0}
    .saved-timeline-dot{flex:0 0 8px;height:8px;border-radius:50%;background:#263f4d}
    .saved-timeline-row.is-roadmap .saved-timeline-dot,.saved-timeline-bar.is-roadmap{background:#4f82c4}
    .saved-timeline-row.is-improvement .saved-timeline-dot,.saved-timeline-bar.is-improvement{background:#56a27e}
    .saved-timeline-row.is-task .saved-timeline-dot,.saved-timeline-bar.is-task{background:#b5523d}
    .saved-timeline-row.is-improvement .saved-timeline-label{padding-left:30px}
    .saved-timeline-row.is-task .saved-timeline-label{padding-left:48px}
    .saved-timeline-bar{position:absolute;top:14px;height:18px;min-width:7px;border-radius:999px;background:#263f4d}
    @media(max-width:760px){
        .saved-timeline-page{width:calc(100vw - 28px)}
        .saved-timeline-head{display:grid}
    }
</style>

<section class="stack saved-timeline-page">
    <div class="saved-timeline-head">
        <div>
            <div class="eyebrow">SAVED TIMELINE / VERSION {{ $version->version_number }}</div>
            <h1>{{ $version->title ?: $version->change_summary ?: '保存タイムライン' }}</h1>
            @if($version->note)<p>{{ $version->note }}</p>@endif
            <div class="saved-timeline-meta">
                <span>{{ $version->created_at?->format('Y年n月j日 H:i') }}</span>
                <span>保存者 {{ $version->creator?->name ?? 'SYSTEM' }}</span>
                <span>{{ $version->version_type === \App\Models\ProjectPlanVersion::TYPE_PROPOSAL ? 'AI提案反映時' : '手動保存' }}</span>
            </div>
        </div>
        <div class="actions">
            <a class="button secondary" href="{{ route('projects.timeline-snapshots.index', $project) }}">保存履歴へ</a>
            <a class="button" href="{{ route('projects.show', ['project' => $project, 'view' => 'time']) }}">現在のタイムライン</a>
        </div>
    </div>

    @if(session('status'))<div class="panel" style="border-color:#b7d8c2;background:#f3fbf6;">{{ session('status') }}</div>@endif

    <div class="panel">
        <p class="meta">この画面は保存時点の固定版です。現在のタイムラインを変更しても、この内容は書き換わりません。</p>
        @if($rows->isEmpty())
            <p>保存時点では、日程が設定された項目がありません。</p>
        @else
            <div class="saved-timeline-scroll">
                <div class="saved-timeline-chart">
                    <div class="saved-timeline-axis">
                        <div class="saved-timeline-label"><strong>{{ $project->name }}</strong></div>
                        <div class="saved-timeline-track">
                            <span>{{ $axis['relative'] ? $axis['start'].'日目' : $axis['start']->format('Y/m/d') }}</span>
                            <span>{{ $axis['relative'] ? $axis['end'].'日目' : $axis['end']->format('Y/m/d') }}</span>
                        </div>
                    </div>
                    @foreach($rows as $row)
                        <div class="saved-timeline-row is-{{ $row['type'] }}">
                            <div class="saved-timeline-label"><span class="saved-timeline-dot"></span><strong>{{ $row['title'] }}</strong></div>
                            <div class="saved-timeline-track">
                                <span class="saved-timeline-bar is-{{ $row['type'] }}" style="left:{{ $row['left'] }}%;width:{{ $row['width'] }}%"></span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>
@endsection
