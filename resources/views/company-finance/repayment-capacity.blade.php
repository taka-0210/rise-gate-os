@extends('layouts.app', ['title' => '減価償却・返済余力 - 経営数値'])

@section('content')
<div class="capacity-page">
    <div class="page-header">
        <div>
            <div class="meta"><a href="{{ route('company-finance.index') }}">経営数値</a> / REPAYMENT CAPACITY</div>
            <h1>減価償却・返済余力</h1>
            <p>返済状態を見つけ、原因を理解し、次の経営判断を検討する場所です。</p>
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
        <div><span>返済状態</span><strong>返済原資 ÷ 年間元利返済額 ＝ DSCR</strong></div>
    </div>

    <section class="card capacity-table-wrap">
        <div class="section-heading">
            <div>
                <h2>年度別の返済状態</h2>
                <p class="meta">一覧は問題のある年度を見つける場所です。DSCRを押すと、原因・返済内訳・改善の方向性を確認できます。</p>
            </div>
            @if($canManage)<button type="submit" form="depreciation-form">減価償却費を保存</button>@endif
        </div>

        <table class="capacity-table">
            <thead>
                <tr>
                    <th>年度</th><th>区分</th><th>返済原資</th><th>年間元本返済額</th>
                    <th>年間元利返済額</th><th>返済後余力</th><th>DSCR・状態</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr class="capacity-row capacity-row--{{ $row['assessment']['key'] }}">
                        <td><strong>{{ $row['year'] }}年度</strong>@if($row['period_number'])<br><small>{{ $row['period_number'] }}期</small>@endif</td>
                        <td><span class="capacity-type {{ $row['type'] === '実績' ? 'actual' : ($row['type'] === '未登録' ? 'missing' : ($row['type'] === '今期' ? 'current' : 'plan')) }}">{{ $row['type'] }}</span></td>
                        <td>{{ $row['repayment_source'] === null ? '—' : number_format($row['repayment_source']).'円' }}</td>
                        <td>{{ number_format($row['principal_repayment']) }}円</td>
                        <td>{{ number_format($row['annual_debt_service']) }}円@if($row['interest_expense'] === null)<br><small>元本のみ</small>@endif</td>
                        <td class="{{ ($row['remaining_capacity'] ?? 0) < 0 ? 'negative' : (($row['remaining_capacity'] ?? 0) > 0 ? 'positive' : '') }}">
                            {{ $row['remaining_capacity'] === null ? '—' : number_format($row['remaining_capacity']).'円' }}
                        </td>
                        <td>
                            <button type="button" class="dscr-button dscr-button--{{ $row['assessment']['key'] }}" onclick="document.getElementById('year-detail-{{ $row['year'] }}').showModal()">
                                <strong>{{ $row['coverage_ratio'] === null ? '—' : number_format($row['coverage_ratio'], 2).'倍' }}</strong>
                                <span>{{ $row['assessment']['label'] }}</span>
                                <small>詳細を見る</small>
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <form id="depreciation-form" method="POST" action="{{ route('company-finance.repayment-capacity.update') }}">
        @csrf
        @method('PUT')
    </form>

    @foreach($rows as $row)
        <dialog id="year-detail-{{ $row['year'] }}" class="year-detail-modal">
            <div class="modal-header">
                <div>
                    <span class="meta">{{ $row['year'] }}年度・{{ $row['type'] }}</span>
                    <h2>返済状態の詳細</h2>
                </div>
                <button type="button" class="secondary modal-close" onclick="this.closest('dialog').close()" aria-label="閉じる">×</button>
            </div>

            <div class="detail-hero detail-hero--{{ $row['assessment']['key'] }}">
                <div><span>{{ $row['dscr_type'] }}</span><strong>{{ $row['coverage_ratio'] === null ? '—' : number_format($row['coverage_ratio'], 2).'倍' }}</strong></div>
                <div><span>判定</span><strong>{{ $row['assessment']['label'] }}</strong></div>
                <p>
                    @if($row['remaining_capacity'] === null)
                        当期純利益・減価償却費など、判定に必要な数値が未入力です。
                    @elseif($row['shortfall'] > 0)
                        返済原資が<strong>{{ number_format($row['shortfall']) }}円不足</strong>しています。
                    @else
                        返済後も<strong>{{ number_format($row['surplus']) }}円の余裕</strong>があります。
                    @endif
                </p>
            </div>

            <div class="detail-metrics">
                <div><span>当期純利益</span><strong>{{ $row['net_income'] === null ? '—' : number_format($row['net_income']).'円' }}</strong></div>
                <div>
                    <span>減価償却費</span>
                    @if($canManage)
                        <input type="number" name="depreciation[{{ $row['year'] }}]" form="depreciation-form" value="{{ old('depreciation.'.$row['year'], $row['depreciation_expense']) }}" min="0" max="999999999999" step="1" placeholder="未入力">
                    @else
                        <strong>{{ $row['depreciation_expense'] === null ? '—' : number_format($row['depreciation_expense']).'円' }}</strong>
                    @endif
                </div>
                <div><span>支払利息</span><strong>{{ $row['interest_expense'] === null ? '未入力' : number_format($row['interest_expense']).'円' }}</strong></div>
                <div><span>返済原資</span><strong>{{ $row['repayment_source'] === null ? '—' : number_format($row['repayment_source']).'円' }}</strong></div>
                <div><span>年間元本返済額</span><strong>{{ number_format($row['principal_repayment']) }}円</strong></div>
                <div><span>{{ $row['interest_expense'] === null ? '年間返済額（元本のみ）' : '年間元利返済額' }}</span><strong>{{ number_format($row['annual_debt_service']) }}円</strong></div>
            </div>

            @if($row['coverage_ratio'] !== null && $row['coverage_ratio'] < 1)
                <div class="detail-message">返済原資だけでは、その年度の元利返済額を賄えていません。</div>
            @endif

            <div class="detail-columns">
                <section>
                    <h3>主な要因</h3>
                    @if($row['causes'])
                        <ul>@foreach($row['causes'] as $cause)<li>{{ $cause }}</li>@endforeach</ul>
                    @else
                        <p class="meta">現時点で強い注意要因は見つかっていません。</p>
                    @endif
                    @if($row['refinanced_principal_repayment'] > 0)
                        <p class="refinance-note">借換えによる一括返済 {{ number_format($row['refinanced_principal_repayment']) }}円は、DSCR計算から除外しています。</p>
                    @endif
                </section>
                <section>
                    <h3>検討できる改善</h3>
                    @if($row['improvements'])
                        <ul>@foreach($row['improvements'] as $item)<li>{{ $item }}</li>@endforeach</ul>
                    @else
                        <p class="meta">現在の返済状態を維持できるか、継続して確認します。</p>
                    @endif
                </section>
            </div>

            <section class="loan-breakdown">
                <h3>元本返済の内訳</h3>
                <div class="breakdown-summary">
                    <div><span>通常返済</span><strong>{{ number_format($row['scheduled_principal_repayment']) }}円</strong></div>
                    <div><span>自己資金による一括返済</span><strong>{{ number_format($row['extra_principal_repayment']) }}円</strong></div>
                    <div><span>借換えによる一括返済</span><strong>{{ number_format($row['refinanced_principal_repayment']) }}円</strong></div>
                </div>
                @foreach($row['principal_repayment_loans'] as $loan)
                    <div class="loan-line">
                        <span>No.{{ $loan['management_number'] }} {{ $loan['financial_institution'] }}</span>
                        <strong>{{ number_format($loan['amount']) }}円</strong>
                        @if($loan['includes_extra_repayment'])
                            <small>通常 {{ number_format($loan['scheduled_repayment']) }}円／一括・完済 {{ number_format($loan['extra_repayment']) }}円／返済方法：{{ $loan['is_refinanced'] ? '借換えで返済' : '自己資金で返済' }}</small>
                            @if($canManageDebt)
                                <span class="repayment-funding-control">
                                    <label for="funding-{{ $row['year'] }}-{{ $loan['id'] }}">一括返済の返済方法</label>
                                    <select id="funding-{{ $row['year'] }}-{{ $loan['id'] }}" name="extra_repayment_funding" form="funding-form-{{ $row['year'] }}-{{ $loan['id'] }}">
                                        <option value="self_funded" @selected(!$loan['is_refinanced'])>自己資金で返済</option>
                                        <option value="refinance" @selected($loan['is_refinanced'])>借換えで返済</option>
                                    </select>
                                    <button type="submit" form="funding-form-{{ $row['year'] }}-{{ $loan['id'] }}">反映</button>
                                    <small>DSCR：{{ $loan['is_refinanced'] ? '算入しない' : '算入する' }}</small>
                                </span>
                            @endif
                        @endif
                    </div>
                @endforeach
            </section>

            @if($row['year'] === $currentFiscalYear && $canManage && $canManageDebt)
                <section id="current-simulation" class="simulation-panel">
                    <div class="simulation-heading">
                        <div><span class="meta">CURRENT YEAR DECISION</span><h2>今期の改善シミュレーション</h2></div>
                        <p>初期値：{{ $simulationSourceType }}。P/Lは変更せず、経営判断のシナリオとして保存します。</p>
                    </div>

                    <div class="simulation-inputs">
                        <label><span>売上計画（計画前提）</span><input type="number" data-sim="net_sales" step="1"></label>
                        <label><span>新規借入前の当期純利益計画</span><input type="number" data-sim="net_income" step="1"></label>
                        <label><span>減価償却費</span><input type="number" data-sim="depreciation_expense" min="0" step="1"></label>
                        <label><span>既存借入の支払利息計画</span><input type="number" data-sim="interest_expense" min="0" step="1"></label>
                        <label><span>一括返済</span><select data-sim="execute_extra_repayments"><option value="1">実施する</option><option value="0">実施しない</option></select></label>
                    </div>

                    @if($row['principal_repayment_loans']->where('includes_extra_repayment', true)->isNotEmpty())
                        <div class="simulation-overrides">
                            <h3>一括返済の判断</h3>
                            @foreach($row['principal_repayment_loans']->where('includes_extra_repayment', true) as $loan)
                                <label>
                                    <span>No.{{ $loan['management_number'] }} {{ $loan['financial_institution'] }}（{{ number_format($loan['extra_repayment']) }}円）</span>
                                    <select data-sim-loan="{{ $loan['id'] }}">
                                        <option value="self_funded">自己資金で返済</option>
                                        <option value="refinance">借換えで返済</option>
                                    </select>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    <div class="new-loan-panel">
                        <h3>新規借入の判断</h3>
                        <p class="meta">新規借入の利息と今期中の元本返済を自動計算し、当期純利益計画・返済額へ反映します。</p>
                        <div class="simulation-inputs">
                            <label><span>新規借入額</span><input type="number" data-new-loan="amount" min="0" step="1"></label>
                            <label><span>実行日</span><input type="date" data-new-loan="executed_on"></label>
                            <label><span>返済期間（月）</span><input type="number" data-new-loan="term_months" min="1" max="600"></label>
                            <label><span>年利（%）</span><input type="number" data-new-loan="annual_interest_rate" min="0" max="100" step="0.0001"></label>
                            <label><span>返済方法</span><select data-new-loan="repayment_mode"><option value="amortizing">元金均等</option><option value="bullet">期日一括</option></select></label>
                        </div>
                    </div>

                    <div class="simulation-result" aria-live="polite">
                        <div class="simulation-status"><span>シミュレーションDSCR</span><strong data-result="coverage_ratio">—</strong><b data-result="assessment">計算中</b></div>
                        <div class="simulation-metrics">
                            <div><span>返済原資</span><strong data-result="repayment_source">—</strong></div>
                            <div><span>年間元本返済額</span><strong data-result="principal_repayment">—</strong></div>
                            <div><span>年間元利返済額</span><strong data-result="annual_debt_service">—</strong></div>
                            <div><span data-result="capacity_label">返済後余力</span><strong data-result="remaining_capacity">—</strong></div>
                        </div>
                        <div class="simulation-analysis">
                            <div><h3>主な要因</h3><ul data-result-list="causes"></ul></div>
                            <div><h3>検討できる改善</h3><ul data-result-list="improvements"></ul></div>
                        </div>
                    </div>

                    <div class="simulation-actions">
                        <span class="meta" data-simulation-message></span>
                        <button type="button" class="secondary" data-simulation-reset>実績値に戻す</button>
                        <button type="button" data-simulation-save>このシナリオを保存</button>
                    </div>
                </section>
            @endif

            <div class="actions modal-footer"><button type="button" onclick="this.closest('dialog').close()">閉じる</button></div>
        </dialog>
    @endforeach

    @if($canManageDebt)
        @foreach($rows as $row)
            @foreach($row['principal_repayment_loans']->where('includes_extra_repayment', true) as $loan)
                <form id="funding-form-{{ $row['year'] }}-{{ $loan['id'] }}" method="POST" action="{{ route('company-finance.repayment-capacity.extra-repayment-funding', $loan['id']) }}">
                    @csrf
                    @method('PUT')
                </form>
            @endforeach
        @endforeach
    @endif

    <div class="card capacity-note">
        <h3>数字の見方</h3>
        <p>1.50倍以上は「安全」、1.00倍以上1.50倍未満は「注意」、1.00倍未満は「要改善」です。借換えによる一括返済はDSCRから除外します。今期シミュレーションは経営判断の比較用であり、P/L確定実績や借入台帳は変更しません。</p>
    </div>
</div>

<style>
.capacity-page{width:min(1580px,calc(100vw - 32px));position:relative;left:50%;transform:translateX(-50%)}.capacity-formula{display:flex;align-items:stretch;gap:14px;margin-bottom:18px}.capacity-formula>div{flex:1;display:flex;flex-direction:column;gap:5px;padding:18px;border:1px solid #b7d5ce;border-radius:12px;background:#f2faf7}.capacity-formula span,.detail-metrics span,.breakdown-summary span,.simulation-result span{color:var(--muted);font-size:12px}.capacity-formula strong{color:var(--accent-dark);font-size:18px}.capacity-formula>b{display:flex;align-items:center;color:#4d8a94;font-size:24px}.capacity-table-wrap{overflow:auto}.capacity-table{width:100%;min-width:1050px;border-collapse:collapse}.capacity-table th,.capacity-table td{padding:12px 14px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap}.capacity-table th:first-child,.capacity-table td:first-child,.capacity-table th:nth-child(2),.capacity-table td:nth-child(2){text-align:left}.capacity-row--improvement{background:#fff9f7}.capacity-row--caution{background:#fffdf5}.capacity-type{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:12px}.capacity-type.actual{color:#126452;background:#e5f5ef}.capacity-type.current{color:#fff;background:var(--accent-dark)}.capacity-type.plan{color:#66551c;background:#fbf4d8}.capacity-type.missing{color:#7a3d28;background:#fae9df}.dscr-button{display:inline-flex;min-width:116px;flex-direction:column;align-items:center;gap:2px;padding:8px 12px;border:1px solid transparent;border-radius:10px}.dscr-button strong{font-size:21px}.dscr-button span{font-weight:800}.dscr-button small{font-weight:400;opacity:.75}.dscr-button--safe{color:#126452;background:#e5f5ef}.dscr-button--caution{color:#725b00;background:#fff2be}.dscr-button--improvement{color:#a83232;background:#fde7e4}.dscr-button--unavailable{color:#52616a;background:#eef2f3}.year-detail-modal{width:min(1050px,calc(100vw - 28px));max-height:calc(100vh - 28px);padding:0;border:0;border-radius:16px;box-shadow:0 24px 80px rgba(13,35,45,.3)}.year-detail-modal::backdrop{background:rgba(13,30,39,.52)}.modal-header{position:sticky;top:0;z-index:3;display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--line);background:#fff}.modal-header h2{margin:3px 0 0}.modal-close{width:42px;height:42px;padding:0;font-size:24px}.detail-hero{display:grid;grid-template-columns:180px 180px 1fr;gap:16px;align-items:center;padding:20px 24px}.detail-hero>div{display:flex;flex-direction:column}.detail-hero strong{font-size:28px}.detail-hero p{margin:0;padding:14px;border-radius:10px;background:rgba(255,255,255,.7)}.detail-hero--safe{background:#e5f5ef}.detail-hero--caution{background:#fff2be}.detail-hero--improvement{background:#fde7e4}.detail-hero--unavailable{background:#eef2f3}.detail-metrics,.breakdown-summary,.simulation-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:18px 24px}.detail-metrics>div,.breakdown-summary>div,.simulation-metrics>div{display:flex;flex-direction:column;gap:5px;padding:12px;border:1px solid var(--line);border-radius:9px}.detail-metrics input{width:100%;text-align:right}.detail-message,.refinance-note{margin:0 24px 18px;padding:13px 15px;border-radius:9px;background:#fff0ed;color:#8d3028}.detail-columns{display:grid;grid-template-columns:1fr 1fr;gap:18px;padding:0 24px 20px}.detail-columns section{padding:16px;border:1px solid var(--line);border-radius:10px}.detail-columns h3,.loan-breakdown h3,.simulation-panel h3{margin-top:0}.detail-columns li{margin:7px 0}.loan-breakdown{padding:20px 24px;border-top:1px solid var(--line)}.loan-breakdown .breakdown-summary{padding:0 0 12px}.loan-line{display:grid;grid-template-columns:1fr auto;gap:4px 12px;padding:12px 0;border-bottom:1px solid var(--line)}.loan-line>small{grid-column:1/-1;color:#9a5d2e}.repayment-funding-control{grid-column:1/-1;display:flex;align-items:center;gap:10px;padding:10px;border-radius:8px;background:#f4f8f8}.repayment-funding-control select{flex:1}.repayment-funding-control small{color:var(--accent-dark);font-weight:800}.simulation-panel{margin:0 24px 24px;padding:20px;border:2px solid #4d8a94;border-radius:14px;background:#f8fbfb}.simulation-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.simulation-heading h2{margin:3px 0 16px}.simulation-heading p{max-width:430px;margin:0;color:var(--muted)}.simulation-inputs{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.simulation-inputs label,.simulation-overrides label{display:flex;flex-direction:column;gap:6px}.simulation-inputs label span,.simulation-overrides label span{font-size:12px;color:var(--muted)}.simulation-overrides,.new-loan-panel{margin-top:16px;padding:16px;border:1px solid var(--line);border-radius:10px;background:#fff}.simulation-overrides label{display:grid;grid-template-columns:1fr 280px;align-items:center;margin-top:8px}.simulation-result{margin-top:18px;border-radius:12px;background:#fff;overflow:hidden}.simulation-status{display:flex;align-items:center;gap:16px;padding:17px 20px;background:#e5f5ef}.simulation-status--safe{background:#e5f5ef}.simulation-status--caution{background:#fff2be}.simulation-status--improvement{background:#fde7e4}.simulation-status--unavailable{background:#eef2f3}.simulation-status strong{font-size:28px}.simulation-status b{padding:5px 10px;border-radius:999px;background:#fff}.simulation-analysis{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:0 20px 18px}.simulation-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:14px}.simulation-actions [data-simulation-message]{margin-right:auto}.modal-footer{position:sticky;bottom:0;justify-content:flex-end;padding:14px 24px;background:#fff;border-top:1px solid var(--line)}.capacity-note{margin-top:18px}.capacity-note h3{margin-top:0}.capacity-note p{margin-bottom:0;color:var(--muted)}@media(max-width:800px){.capacity-formula{flex-direction:column}.capacity-formula>b{justify-content:center;transform:rotate(90deg)}.detail-hero,.detail-columns,.detail-metrics,.breakdown-summary,.simulation-metrics,.simulation-analysis{grid-template-columns:1fr}.simulation-inputs{grid-template-columns:1fr 1fr}.simulation-heading{flex-direction:column}.simulation-overrides label{grid-template-columns:1fr}.repayment-funding-control{align-items:stretch;flex-direction:column}}@media(max-width:520px){.simulation-inputs{grid-template-columns:1fr}}
</style>

@if($canManage && $canManageDebt)
<script>
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('current-simulation');
    if (!root) return;
    const savedDefaults = @json($simulationDefaults);
    const actualDefaults = @json($simulationBase);
    const simulateUrl = @json(route('company-finance.repayment-capacity.simulate'));
    const saveUrl = @json(route('company-finance.repayment-capacity.scenario.save'));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    let timer;

    const nullableNumber = value => value === '' || value === null ? null : Number(value);
    const collectInput = () => {
        const values = {fiscal_year: Number(savedDefaults.fiscal_year)};
        root.querySelectorAll('[data-sim]').forEach(input => {
            values[input.dataset.sim] = input.dataset.sim === 'execute_extra_repayments'
                ? input.value === '1'
                : nullableNumber(input.value);
        });
        values.extra_repayment_funding_overrides = {};
        root.querySelectorAll('[data-sim-loan]').forEach(input => {
            values.extra_repayment_funding_overrides[input.dataset.simLoan] = input.value;
        });
        const newLoan = {};
        root.querySelectorAll('[data-new-loan]').forEach(input => {
            newLoan[input.dataset.newLoan] = ['executed_on', 'repayment_mode'].includes(input.dataset.newLoan)
                ? (input.value || null)
                : nullableNumber(input.value);
        });
        values.new_loans = [newLoan];
        return values;
    };
    const applyDefaults = defaults => {
        root.querySelectorAll('[data-sim]').forEach(input => {
            const value = defaults[input.dataset.sim];
            input.value = input.dataset.sim === 'execute_extra_repayments'
                ? (value ? '1' : '0')
                : (value ?? '');
        });
        root.querySelectorAll('[data-sim-loan]').forEach(input => {
            input.value = defaults.extra_repayment_funding_overrides?.[input.dataset.simLoan] ?? 'self_funded';
        });
        const newLoan = defaults.new_loans?.[0] ?? {};
        root.querySelectorAll('[data-new-loan]').forEach(input => {
            input.value = newLoan[input.dataset.newLoan] ?? '';
        });
    };
    const money = value => value === null ? '—' : `${Number(value).toLocaleString('ja-JP')}円`;
    const renderList = (name, items) => {
        const list = root.querySelector(`[data-result-list="${name}"]`);
        list.replaceChildren();
        (items.length ? items : ['現時点で該当する項目はありません']).forEach(text => {
            const li = document.createElement('li');
            li.textContent = text;
            list.appendChild(li);
        });
    };
    const render = result => {
        root.querySelector('[data-result="coverage_ratio"]').textContent = result.coverage_ratio === null ? '—' : `${Number(result.coverage_ratio).toFixed(2)}倍`;
        root.querySelector('[data-result="assessment"]').textContent = result.assessment.label;
        root.querySelector('[data-result="repayment_source"]').textContent = money(result.repayment_source);
        root.querySelector('[data-result="principal_repayment"]').textContent = money(result.principal_repayment);
        root.querySelector('[data-result="annual_debt_service"]').textContent = money(result.annual_debt_service);
        root.querySelector('[data-result="capacity_label"]').textContent = result.shortfall > 0 ? '返済不足額' : '返済後余力';
        root.querySelector('[data-result="remaining_capacity"]').textContent = money(result.shortfall > 0 ? result.shortfall : result.surplus);
        root.querySelector('.simulation-status').className = `simulation-status simulation-status--${result.assessment.key}`;
        renderList('causes', result.causes);
        renderList('improvements', result.improvements);
    };
    const request = async (url, method = 'POST') => {
        const response = await fetch(url, {
            method,
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify(collectInput())
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(Object.values(data.errors ?? {}).flat()[0] ?? '再計算できませんでした。');
        }
        return response.json();
    };
    const recalculate = async () => {
        const message = root.querySelector('[data-simulation-message]');
        try {
            message.textContent = '再計算中…';
            render(await request(simulateUrl));
            message.textContent = '';
        } catch (error) {
            message.textContent = error.message;
        }
    };
    root.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(recalculate, 250);
    });
    root.addEventListener('change', () => {
        clearTimeout(timer);
        recalculate();
    });
    root.querySelector('[data-simulation-reset]').addEventListener('click', () => {
        applyDefaults(actualDefaults);
        recalculate();
    });
    root.querySelector('[data-simulation-save]').addEventListener('click', async () => {
        const message = root.querySelector('[data-simulation-message]');
        try {
            message.textContent = '保存中…';
            const data = await request(saveUrl, 'PUT');
            render(data.result);
            message.textContent = data.message;
        } catch (error) {
            message.textContent = error.message;
        }
    });
    applyDefaults(savedDefaults);
    recalculate();
});
</script>
@endif
@endsection
