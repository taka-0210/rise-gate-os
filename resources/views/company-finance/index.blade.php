@extends('layouts.app', ['title' => '経営数値 - COMPANY OS'])

@section('content')
<div class="finance-page">
    <div class="page-header">
        <div>
            <div class="meta"><a href="{{ route('company-finance.index') }}">経営数値</a> / P/L</div>
            <h1>P/L</h1>
            <p>{{ $organization->name }}の年度別P/Lです。{{ $organization->fiscal_year_end_month ? $organization->fiscal_year_end_month.'月決算' : '決算月未設定' }}。</p>
        </div>
        <div class="actions">
            <a class="button secondary" href="{{ route('company-finance.index') }}">← 経営数値へ戻る</a>
            @if($canManage)<a class="button" href="{{ route('company-finance.pl.create') }}">1期分を入力</a><a class="button secondary" href="{{ route('company-finance.pl.bulk') }}">表を貼り付けて一括入力</a>@endif
        </div>
    </div>

    @if ($periods->isEmpty())
        <div class="card">
            <h2>年度別P/Lはまだありません</h2>
            <p>「1期分を入力」または「表を貼り付けて一括入力」から登録できます。</p>
        </div>
    @else
        <div class="stats">
            <div class="stat"><span>最新実績</span><strong>{{ $latest->period_number }}期</strong><small>{{ $latest->fiscal_year }}年{{ $organization->fiscal_year_end_month ? $organization->fiscal_year_end_month.'月期' : '度' }}</small></div>
            <div class="stat"><span>売上高</span><strong>{{ number_format($latest->net_sales) }}</strong><small>円</small></div>
            <div class="stat"><span>粗利率</span><strong>{{ number_format((float) $latest->gross_profit_ratio * 100, 1) }}%</strong><small>売上総利益率</small></div>
            <div class="stat"><span>営業利益率</span><strong>{{ number_format((float) $latest->operating_profit_ratio * 100, 1) }}%</strong><small>{{ number_format($latest->operating_profit) }}円</small></div>
        </div>

        <div class="insight-grid">
            <div class="card"><span>前期比売上</span><strong class="{{ ($salesGrowthRate ?? 0) < 0 ? 'negative' : '' }}">{{ $salesGrowthRate === null ? '—' : (($salesGrowthRate >= 0 ? '+' : '').number_format($salesGrowthRate, 1).'%') }}</strong><small>{{ $previous ? $previous->period_number.'期との比較' : '比較対象なし' }}</small></div>
            <div class="card"><span>過去最高売上</span><strong>{{ $highestSales?->period_number }}期</strong><small>{{ $highestSales ? number_format($highestSales->net_sales).'円' : '—' }}</small></div>
            <div class="card"><span>営業黒字</span><strong>{{ $profitablePeriodCount }}期</strong><small>全{{ $periods->count() }}期中</small></div>
            <div class="card"><span>最新の最終利益率</span><strong class="{{ ($latestNetIncomeRatio ?? 0) < 0 ? 'negative' : '' }}">{{ $latestNetIncomeRatio === null ? '—' : number_format($latestNetIncomeRatio, 1).'%' }}</strong><small>当期純利益 ÷ 売上高</small></div>
        </div>

        <div class="chart-grid">
            <section class="card chart-card">
                <div><div class="meta">21-YEAR TREND</div><h2>売上・粗利益・販管費</h2></div>
                @include('company-finance.partials.line-chart', ['title' => '売上・粗利益・販管費の推移', 'periods' => $chartPeriods, 'series' => $amountSeries, 'unit' => '円'])
            </section>
            <section class="card chart-card">
                <div><div class="meta">PROFIT</div><h2>利益の推移</h2></div>
                @include('company-finance.partials.line-chart', ['title' => '営業利益・経常利益・当期純利益の推移', 'periods' => $chartPeriods, 'series' => $profitSeries, 'unit' => '円'])
            </section>
            <section class="card chart-card chart-card--wide">
                <div><div class="meta">MARGIN</div><h2>利益率の推移</h2></div>
                @include('company-finance.partials.line-chart', ['title' => '粗利率・営業利益率・最終利益率の推移', 'periods' => $chartPeriods, 'series' => $marginSeries, 'unit' => '%'])
            </section>
        </div>

        <div class="card finance-table-wrap">
            <div class="section-heading">
                <div>
                    <h2>年度別損益推移</h2>
                    <p class="meta">確定実績のみ表示。計画・見込・未確定とは区別して保存します。</p>
                </div>
                <span class="badge">{{ $periods->count() }}期分</span>
            </div>
            <table class="finance-table">
                <thead>
                    <tr>
                        <th>期</th>
                        <th>年度</th>
                        <th>売上高</th>
                        <th>売上総利益</th>
                        <th>粗利率</th>
                        <th>販管費</th>
                        <th>営業利益</th>
                        <th>営業利益率</th>
                        <th>経常利益</th>
                        <th>当期純利益</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($periods as $period)
                        <tr>
                            <td>@if($canManage)<a href="{{ route('company-finance.pl.edit',$period) }}">{{ $period->period_number }}期</a>@else{{ $period->period_number }}期@endif<br><small>{{ $period->record_status === 'confirmed' ? '確定' : '下書き' }}</small></td>
                            <td>{{ $period->fiscal_year }}</td>
                            <td>{{ number_format($period->net_sales) }}</td>
                            <td>{{ number_format($period->gross_profit) }}</td>
                            <td>{{ number_format((float) $period->gross_profit_ratio * 100, 1) }}%</td>
                            <td>{{ number_format($period->selling_general_admin_expenses) }}</td>
                            <td class="{{ $period->operating_profit < 0 ? 'negative' : 'positive' }}">{{ number_format($period->operating_profit) }}</td>
                            <td class="{{ $period->operating_profit_ratio < 0 ? 'negative' : 'positive' }}">{{ number_format((float) $period->operating_profit_ratio * 100, 1) }}%</td>
                            <td class="{{ $period->ordinary_profit < 0 ? 'negative' : 'positive' }}">{{ number_format($period->ordinary_profit) }}</td>
                            <td class="{{ $period->net_income < 0 ? 'negative' : 'positive' }}">{{ number_format($period->net_income) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

    <style>
        .finance-page { width:min(1580px,calc(100vw - 32px)); position:relative; left:50%; transform:translateX(-50%); }
        .stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:18px; }
        .stat { display:flex; flex-direction:column; gap:5px; padding:18px; border:1px solid var(--line); border-radius:12px; background:#fff; }
        .stat span,.stat small { color:var(--muted); }
        .stat strong { color:var(--accent-dark); font-size:24px; }
        .insight-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:18px; }
        .insight-grid .card { display:flex; flex-direction:column; gap:5px; }
        .insight-grid span,.insight-grid small { color:var(--muted); }
        .insight-grid strong { color:var(--accent-dark); font-size:22px; }
        .chart-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin-bottom:18px; }
        .chart-card { overflow:hidden; }
        .chart-card--wide { grid-column:1 / -1; }
        .finance-chart { position:relative; min-width:0; margin-top:12px; }
        .finance-chart svg { display:block; width:100%; height:auto; overflow:visible; }
        .finance-chart text { fill:#667983; font-size:12px; font-family:inherit; }
        .finance-chart__legend { display:flex; flex-wrap:wrap; gap:14px; color:var(--muted); font-size:12px; }
        .finance-chart__legend span { display:inline-flex; align-items:center; gap:6px; }
        .finance-chart__legend i { width:18px; height:3px; border-radius:99px; }
        .finance-chart__point { pointer-events:none; transition:r .12s ease; }
        .finance-chart__hit { cursor:crosshair; outline:none; }
        .finance-chart__tooltip { --point-color:#165d6c; position:absolute; z-index:5; display:grid; gap:2px; min-width:126px; padding:9px 11px; border-left:4px solid var(--point-color); border-radius:7px; color:#fff; background:rgba(18,31,39,.94); box-shadow:0 8px 24px rgba(0,0,0,.2); opacity:0; pointer-events:none; transform:translate(-50%,calc(-100% - 10px)); transition:opacity .1s ease; font-size:12px; }
        .finance-chart__tooltip.is-visible { opacity:1; }
        .finance-chart__tooltip span { color:#cbd7dc; }
        .finance-chart__tooltip b { font-size:14px; }
        .finance-table-wrap { overflow-x:auto; }
        .section-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; margin-bottom:14px; }
        .finance-table { width:100%; min-width:980px; table-layout:auto; border-collapse:separate; border-spacing:0; font-variant-numeric:tabular-nums; }
        .finance-table th,.finance-table td { padding:10px 8px; border-bottom:1px solid var(--line); text-align:right; white-space:nowrap; font-size:13px; }
        .finance-table th:first-child,.finance-table td:first-child,.finance-table th:nth-child(2),.finance-table td:nth-child(2) { text-align:left; }
        .finance-table th:first-child,.finance-table td:first-child { position:sticky; left:0; z-index:2; width:72px; min-width:72px; background:#fff; box-shadow:1px 0 0 var(--line); }
        .finance-table thead th:first-child { z-index:3; }
        .finance-table tbody tr:hover { background:#f4f8f8; }
        .finance-table tbody tr:hover td:first-child { background:#f4f8f8; }
        .positive { color:#176653; font-weight:700; }
        .negative { color:#c33838; font-weight:700; }
        @media (max-width:900px) {
            .stats,.insight-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .chart-grid { grid-template-columns:1fr; }
            .chart-card--wide { grid-column:auto; }
            .finance-page { width:calc(100vw - 20px); }
        }
    </style>
@endsection
