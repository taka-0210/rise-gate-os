@extends('layouts.app', ['title' => '経営数値 - COMPANY OS'])

@section('content')
<div class="page-header"><div><div class="meta">COMPANY OS / FINANCE</div><h1>経営数値</h1><p>決算、計画、月次実績をつなぎ、会社の現在地を判断する場所です。</p></div></div>
@if(session('status'))<div class="alert success">{{ session('status') }}</div>@endif

<div class="finance-entries">
    <a class="card" href="{{ route('company-finance.section', 'bs') }}"><b>01</b><div><h2>B/S</h2><p>資産・負債・純資産と財務体質</p></div></a>
    <a class="card" href="{{ route('company-finance.pl.index') }}"><b>02</b><div><h2>P/L</h2><p>年度別損益と利益率の推移</p></div></a>
    <a class="card" href="{{ route('company-finance.section', 'plan') }}"><b>03</b><div><h2>今年度計画と進捗</h2><p>売上・粗利・販管費・営業利益の目標</p></div></a>
    <a class="card" href="{{ route('company-finance.section', 'monthly') }}"><b>04</b><div><h2>月次試算表</h2><p>毎月の実績と累計</p></div></a>
    <a class="card" href="{{ route('company-finance.section', 'reconciliation') }}"><b>05</b><div><h2>整合性・差異</h2><p>年度計画と月次実績のズレを確認</p></div></a>
</div>

<div class="card closing-card">
    <div><div class="meta">FISCAL YEAR</div><h2>決算月</h2><p>年度表示と計画期間の基準になります。</p></div>
    @if($canManage)
    <form method="POST" action="{{ route('company-finance.settings.update') }}" class="actions">@csrf @method('PUT')
        <select name="fiscal_year_end_month">@foreach(range(1,12) as $month)<option value="{{ $month }}" @selected($organization->fiscal_year_end_month===$month)>{{ $month }}月</option>@endforeach</select>
        <button type="submit">保存</button>
    </form>
    @else <strong>{{ $organization->fiscal_year_end_month ? $organization->fiscal_year_end_month.'月' : '未設定' }}</strong>@endif
</div>
<style>
.finance-entries{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:18px}.finance-entries .card{display:flex;gap:18px;text-decoration:none;color:inherit}.finance-entries b{color:#4d8a94;font-size:24px}.finance-entries h2{margin:0 0 6px}.finance-entries p{margin:0;color:var(--muted)}.finance-entries .card:last-child{grid-column:1/-1}.closing-card{display:flex;align-items:center;justify-content:space-between;gap:20px}.closing-card select{min-width:120px}@media(max-width:700px){.finance-entries{grid-template-columns:1fr}.finance-entries .card:last-child{grid-column:auto}.closing-card{align-items:flex-start;flex-direction:column}}
</style>
@endsection
