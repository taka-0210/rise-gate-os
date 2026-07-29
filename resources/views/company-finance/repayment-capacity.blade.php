@extends('layouts.app', ['title' => '減価償却・返済余力 - 経営数値'])

@section('content')
<div class="capacity-page">
    <div class="page-header">
        <div>
            <div class="meta"><a href="{{ route('company-finance.index') }}">経営数値</a> / REPAYMENT CAPACITY</div>
            <h1>減価償却・返済余力</h1>
            <p>利益と減価償却費から返済原資を求め、返済余力と簡易DSCR（元本ベース）を確認します。</p>
        </div>
        <div class="actions">
            <a class="button secondary" href="{{ route('company-finance.index') }}">← 経営数値へ戻る</a>
            <a class="button secondary" href="{{ route('company-loans.schedule') }}">借入の月別残高を見る</a>
        </div>
    </div>

    @if(session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif

    <div class="capacity-formula">
        <div><span>返済原資</span><strong>当期純利益 ＋ 減価償却費 ＋ 支払利息</strong></div>
        <b>→</b>
        <div><span>返済後余力・DSCR</span><strong>返済原資 － 年間元利返済額</strong></div>
    </div>

    <form method="POST" action="{{ route('company-finance.repayment-capacity.update') }}" class="card capacity-table-wrap">
        @csrf
        @method('PUT')
        <div class="section-heading">
            <div>
                <h2>年度別返済余力</h2>
                <p class="meta">{{ $closingMonth }}月決算に合わせて、元本返済額を12か月単位で自動集計します。実績年度の利益は確定済みP/Lから取得します。</p>
            </div>
            @if($canManage)<button type="submit">減価償却費を保存</button>@endif
        </div>

        <table class="capacity-table">
            <thead><tr><th>年度</th><th>区分</th><th>当期純利益</th><th>減価償却費</th><th>支払利息</th><th>返済原資</th><th>年間元本返済額</th><th>年間元利返済額</th><th>返済後余力</th><th>返済原資倍率<br><small>DSCR</small></th></tr></thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td><strong>{{ $row['year'] }}年度</strong>@if($row['period_number'])<br><small>{{ $row['period_number'] }}期</small>@endif</td>
                        <td><span class="capacity-type {{ $row['type'] === '実績' ? 'actual' : ($row['type'] === '未登録' ? 'missing' : ($row['type'] === '今期' ? 'current' : 'plan')) }}">{{ $row['type'] }}</span></td>
                        <td class="{{ ($row['net_income'] ?? 0) < 0 ? 'negative' : '' }}">{{ $row['net_income'] === null ? '—' : number_format($row['net_income']).'円' }}</td>
                        <td>
                            @if($canManage)
                                <input type="number" name="depreciation[{{ $row['year'] }}]" value="{{ old('depreciation.'.$row['year'], $row['depreciation_expense']) }}" min="0" max="999999999999" step="1" placeholder="未入力" aria-label="{{ $row['year'] }}年度の減価償却費">
                            @else
                                {{ $row['depreciation_expense'] === null ? '—' : number_format($row['depreciation_expense']).'円' }}
                            @endif
                        </td>
                        <td>{{ $row['interest_expense'] === null ? '未入力' : number_format($row['interest_expense']).'円' }}</td>
                        <td>{{ $row['repayment_source'] === null ? '—' : number_format($row['repayment_source']).'円' }}</td>
                        <td>
                            @if($row['principal_repayment_loans']->isEmpty())
                                0円
                            @else
                                <details class="repayment-details">
                                    <summary>{{ number_format($row['principal_repayment']) }}円</summary>
                                    <div>
                                        @foreach($row['principal_repayment_loans'] as $loan)
                                            <p><span>No.{{ $loan['management_number'] }} {{ $loan['financial_institution'] }}</span><strong>{{ number_format($loan['amount']) }}円</strong>@if($loan['includes_extra_repayment'])<small>一括・完済差額を含む</small>@endif</p>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        </td>
                        <td>{{ $row['interest_expense'] === null ? '—' : number_format($row['annual_debt_service']).'円' }}</td>
                        <td class="{{ ($row['remaining_capacity'] ?? 0) < 0 ? 'negative' : (($row['remaining_capacity'] ?? 0) > 0 ? 'positive' : '') }}">{{ $row['remaining_capacity'] === null ? '—' : number_format($row['remaining_capacity']).'円' }}</td>
                        <td>@if($row['coverage_ratio'] === null)—@else<span class="capacity-type {{ $row['dscr_type'] === 'DSCR' ? 'actual' : 'plan' }}">{{ $row['dscr_type'] }}</span><br>{{ number_format($row['coverage_ratio'], 2) }}倍@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </form>

    <div class="card capacity-note">
        <h3>数字の見方</h3>
        <p>支払利息がP/Lに入力済みの年度は「（当期純利益＋減価償却費＋支払利息）÷（年間元本返済額＋支払利息）」でDSCRを計算します。未入力年度は元本だけを使う簡易DSCRです。1.00倍以上が返済を賄える目安ですが、設備投資、運転資金の増減、税金などによる実際の資金移動は別途確認してください。</p>
    </div>
</div>

<style>
.capacity-page{width:min(1580px,calc(100vw - 32px));position:relative;left:50%;transform:translateX(-50%)}.capacity-formula{display:flex;align-items:stretch;gap:14px;margin-bottom:18px}.capacity-formula>div{flex:1;display:flex;flex-direction:column;gap:5px;padding:18px;border:1px solid #b7d5ce;border-radius:12px;background:#f2faf7}.capacity-formula span{color:var(--muted);font-size:13px}.capacity-formula strong{color:var(--accent-dark);font-size:18px}.capacity-formula>b{display:flex;align-items:center;color:#4d8a94;font-size:24px}.capacity-table-wrap{overflow:auto}.capacity-table{width:100%;min-width:1420px;border-collapse:collapse}.capacity-table th,.capacity-table td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap}.capacity-table th:first-child,.capacity-table td:first-child,.capacity-table th:nth-child(2),.capacity-table td:nth-child(2){text-align:left}.capacity-table input{width:150px;text-align:right}.capacity-type{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:12px}.capacity-type.actual{color:#126452;background:#e5f5ef}.capacity-type.current{color:#fff;background:var(--accent-dark)}.capacity-type.plan{color:#66551c;background:#fbf4d8}.capacity-type.missing{color:#7a3d28;background:#fae9df}.repayment-details summary{color:var(--accent-dark);font-weight:700;cursor:pointer}.repayment-details>div{min-width:290px;margin-top:8px;padding:10px;border:1px solid var(--line);border-radius:8px;background:#f8fafb}.repayment-details p{display:grid;grid-template-columns:1fr auto;gap:3px 12px;margin:0;padding:7px 0;border-bottom:1px solid var(--line);color:var(--ink);font-size:12px;text-align:left}.repayment-details p:last-child{border-bottom:0}.repayment-details small{grid-column:1/-1;color:#9a5d2e}.capacity-note{margin-top:18px}.capacity-note h3{margin-top:0}.capacity-note p{margin-bottom:0;color:var(--muted)}@media(max-width:700px){.capacity-formula{flex-direction:column}.capacity-formula>b{justify-content:center;transform:rotate(90deg)}}
</style>
@endsection
