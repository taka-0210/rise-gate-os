@extends('layouts.app', ['title' => '経営数値 - COMPANY OS'])

@section('content')
    <div class="page-header">
        <div>
            <div class="meta">COMPANY OS / FINANCE</div>
            <h1>経営数値</h1>
            <p>{{ $organization->name }}の年度別P/Lです。財務情報はWorkspaceではなく会社アカウントに属します。</p>
        </div>
    </div>

    @if ($periods->isEmpty())
        <div class="card">
            <h2>年度別P/Lはまだありません</h2>
            <p>年度別損益計算書を取り込むと、ここに会社の財務履歴が表示されます。</p>
        </div>
    @else
        @php($latest = $periods->first())
        <div class="stats">
            <div class="stat"><span>最新実績</span><strong>{{ $latest->period_number }}期</strong><small>{{ $latest->fiscal_year }}年度</small></div>
            <div class="stat"><span>売上高</span><strong>{{ number_format($latest->net_sales) }}</strong><small>円</small></div>
            <div class="stat"><span>粗利率</span><strong>{{ number_format((float) $latest->gross_profit_ratio * 100, 1) }}%</strong><small>売上総利益率</small></div>
            <div class="stat"><span>営業利益率</span><strong>{{ number_format((float) $latest->operating_profit_ratio * 100, 1) }}%</strong><small>{{ number_format($latest->operating_profit) }}円</small></div>
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
                            <td>{{ $period->period_number }}期</td>
                            <td>{{ $period->fiscal_year }}</td>
                            <td>{{ number_format($period->net_sales) }}</td>
                            <td>{{ number_format($period->gross_profit) }}</td>
                            <td>{{ number_format((float) $period->gross_profit_ratio * 100, 1) }}%</td>
                            <td>{{ number_format($period->selling_general_admin_expenses) }}</td>
                            <td class="{{ $period->operating_profit < 0 ? 'negative' : '' }}">{{ number_format($period->operating_profit) }}</td>
                            <td class="{{ $period->operating_profit_ratio < 0 ? 'negative' : '' }}">{{ number_format((float) $period->operating_profit_ratio * 100, 1) }}%</td>
                            <td class="{{ $period->ordinary_profit < 0 ? 'negative' : '' }}">{{ number_format($period->ordinary_profit) }}</td>
                            <td class="{{ $period->net_income < 0 ? 'negative' : '' }}">{{ number_format($period->net_income) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <style>
        .stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:18px; }
        .stat { display:flex; flex-direction:column; gap:5px; padding:18px; border:1px solid var(--line); border-radius:12px; background:#fff; }
        .stat span,.stat small { color:var(--muted); }
        .stat strong { color:var(--accent-dark); font-size:24px; }
        .finance-table-wrap { overflow-x:auto; }
        .section-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; margin-bottom:14px; }
        .finance-table { width:100%; min-width:1080px; border-collapse:collapse; font-variant-numeric:tabular-nums; }
        .finance-table th,.finance-table td { padding:10px 12px; border-bottom:1px solid var(--line); text-align:right; white-space:nowrap; }
        .finance-table th:first-child,.finance-table td:first-child,.finance-table th:nth-child(2),.finance-table td:nth-child(2) { text-align:left; }
        .finance-table tbody tr:hover { background:#f4f8f8; }
        .negative { color:#a33b3b; }
        @media (max-width:900px) { .stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    </style>
@endsection
