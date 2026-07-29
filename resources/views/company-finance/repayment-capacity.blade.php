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
                                <button type="button" class="repayment-breakdown-button" onclick="document.getElementById('repayment-modal-{{ $row['year'] }}').showModal()">{{ number_format($row['principal_repayment']) }}円</button>
                                <dialog id="repayment-modal-{{ $row['year'] }}" class="repayment-modal">
                                    <div class="repayment-modal__header">
                                        <div><span class="meta">{{ $row['year'] }}年度</span><h3>年間元本返済額の内訳</h3></div>
                                        <button type="button" class="secondary repayment-modal__close" onclick="this.closest('dialog').close()" aria-label="閉じる">×</button>
                                    </div>
                                    <div class="repayment-modal__summary">
                                        <div><span>通常返済</span><strong>{{ number_format($row['scheduled_principal_repayment']) }}円</strong></div>
                                        <div><span>自己資金による一括・完済返済</span><strong>{{ number_format($row['extra_principal_repayment']) }}円</strong></div>
                                        <div><span>借換えによる一括・完済返済</span><strong>{{ number_format($row['refinanced_principal_repayment']) }}円</strong></div>
                                        <div><span>DSCR算入元本合計</span><strong>{{ number_format($row['principal_repayment']) }}円</strong></div>
                                    </div>
                                    <div class="repayment-modal__loans">
                                        @foreach($row['principal_repayment_loans'] as $loan)
                                            <p>
                                                <span>No.{{ $loan['management_number'] }} {{ $loan['financial_institution'] }}</span>
                                                <strong>{{ number_format($loan['amount']) }}円</strong>
                                                @if($loan['includes_extra_repayment'])
                                                    <small>
                                                        通常 {{ number_format($loan['scheduled_repayment']) }}円／一括・完済 {{ number_format($loan['extra_repayment']) }}円
                                                        ／返済方法：{{ $loan['is_refinanced'] ? '借換えで返済' : '自己資金で返済' }}
                                                    </small>
                                                    @if($canManageDebt)
                                                        <span class="repayment-funding-control">
                                                            <label for="extra-repayment-funding-{{ $row['year'] }}-{{ $loan['id'] }}">一括返済の返済方法</label>
                                                            <select id="extra-repayment-funding-{{ $row['year'] }}-{{ $loan['id'] }}" name="extra_repayment_funding" form="extra-repayment-form-{{ $row['year'] }}-{{ $loan['id'] }}">
                                                                <option value="self_funded" @selected($loan['extra_repayment_funding'] !== 'refinance')>自己資金で返済</option>
                                                                <option value="refinance" @selected($loan['extra_repayment_funding'] === 'refinance')>借換えで返済</option>
                                                            </select>
                                                            <button type="submit" form="extra-repayment-form-{{ $row['year'] }}-{{ $loan['id'] }}">反映</button>
                                                            <small class="repayment-funding-result">DSCR：{{ $loan['is_refinanced'] ? '算入しない' : '算入する' }}</small>
                                                        </span>
                                                    @endif
                                                @endif
                                            </p>
                                        @endforeach
                                    </div>
                                    <div class="actions repayment-modal__footer"><button type="button" onclick="this.closest('dialog').close()">閉じる</button></div>
                                </dialog>
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
    @if($canManageDebt)
        @foreach($rows as $row)
            @foreach($row['principal_repayment_loans']->where('includes_extra_repayment', true) as $loan)
                <form id="extra-repayment-form-{{ $row['year'] }}-{{ $loan['id'] }}" method="POST" action="{{ route('company-finance.repayment-capacity.extra-repayment-funding', $loan['id']) }}">
                    @csrf
                    @method('PUT')
                </form>
            @endforeach
        @endforeach
    @endif

    <div class="card capacity-note">
        <h3>数字の見方</h3>
        <p>年間元本返済額と返済後余力には、通常返済と自己資金による一括返済・完済時の返済を含みます。借換えによる一括返済は計算対象外です。金額を押すと内訳と資金区分を確認できます。支払利息がP/Lに入力済みの年度は「（当期純利益＋減価償却費＋支払利息）÷（年間元本返済額＋支払利息）」でDSCRを計算します。</p>
    </div>
</div>

<style>
.capacity-page{width:min(1580px,calc(100vw - 32px));position:relative;left:50%;transform:translateX(-50%)}.capacity-formula{display:flex;align-items:stretch;gap:14px;margin-bottom:18px}.capacity-formula>div{flex:1;display:flex;flex-direction:column;gap:5px;padding:18px;border:1px solid #b7d5ce;border-radius:12px;background:#f2faf7}.capacity-formula span{color:var(--muted);font-size:13px}.capacity-formula strong{color:var(--accent-dark);font-size:18px}.capacity-formula>b{display:flex;align-items:center;color:#4d8a94;font-size:24px}.capacity-table-wrap{overflow:auto}.capacity-table{width:100%;min-width:1420px;border-collapse:collapse}.capacity-table th,.capacity-table td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap}.capacity-table th:first-child,.capacity-table td:first-child,.capacity-table th:nth-child(2),.capacity-table td:nth-child(2){text-align:left}.capacity-table input{width:150px;text-align:right}.capacity-type{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:12px}.capacity-type.actual{color:#126452;background:#e5f5ef}.capacity-type.current{color:#fff;background:var(--accent-dark)}.capacity-type.plan{color:#66551c;background:#fbf4d8}.capacity-type.missing{color:#7a3d28;background:#fae9df}.repayment-breakdown-button{padding:0;border:0;color:var(--accent-dark);background:transparent;font-weight:800;text-decoration:underline;text-underline-offset:3px}.repayment-modal{width:min(800px,calc(100vw - 32px));max-height:calc(100vh - 48px);padding:0;border:0;border-radius:14px;box-shadow:0 24px 80px rgba(13,35,45,.28);white-space:normal}.repayment-modal::backdrop{background:rgba(13,30,39,.48)}.repayment-modal__header{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:20px 22px;border-bottom:1px solid var(--line)}.repayment-modal__header h3{margin:3px 0 0;font-size:22px}.repayment-modal__close{width:40px;height:40px;padding:0;font-size:24px}.repayment-modal__summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:18px 22px;background:#f4f8f8}.repayment-modal__summary div{display:flex;flex-direction:column;gap:5px}.repayment-modal__summary span{color:var(--muted);font-size:12px}.repayment-modal__summary strong{font-size:18px}.repayment-modal__loans{padding:8px 22px}.repayment-modal__loans p{display:grid;grid-template-columns:1fr auto;gap:3px 12px;margin:0;padding:12px 0;border-bottom:1px solid var(--line);color:var(--ink);font-size:14px;text-align:left}.repayment-modal__loans p:last-child{border-bottom:0}.repayment-modal__loans small{grid-column:1/-1;color:#9a5d2e}.repayment-funding-control{grid-column:1/-1;display:flex;align-items:center;gap:10px;margin-top:7px;padding:10px;border-radius:8px;background:#f4f8f8}.repayment-funding-control label{font-size:12px;color:var(--muted)}.repayment-funding-control select{flex:1;min-width:220px}.repayment-funding-control button{padding:9px 14px}.repayment-funding-control .repayment-funding-result{grid-column:auto;color:var(--accent-dark);font-weight:700}.repayment-modal__footer{justify-content:flex-end;padding:14px 22px 20px}.capacity-note{margin-top:18px}.capacity-note h3{margin-top:0}.capacity-note p{margin-bottom:0;color:var(--muted)}@media(max-width:700px){.capacity-formula{flex-direction:column}.capacity-formula>b{justify-content:center;transform:rotate(90deg)}.repayment-modal__summary{grid-template-columns:1fr}.repayment-funding-control{align-items:stretch;flex-direction:column}.repayment-funding-control select{min-width:0}}
</style>
@endsection
