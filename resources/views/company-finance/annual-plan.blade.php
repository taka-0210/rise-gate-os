@extends('layouts.app', ['title' => '今年度計画と進捗 - 経営数値'])

@section('content')
@php
    $closingMonth = $organization->fiscal_year_end_month ?: 12;
    $startMonth = $closingMonth === 12 ? 1 : $closingMonth + 1;
    $endYear = $closingMonth === 12 ? $fiscalYear : $fiscalYear + 1;
    $value = fn (string $name) => old($name, data_get($plan, $name));
    $amount = fn ($number) => $number === null ? '—' : number_format((int) $number).'円';
    $rate = fn ($number) => $number === null ? '—' : number_format($number * 100, 1).'%';
    $planOperatingProfit = $plan?->plan_gross_profit !== null && $plan?->plan_selling_general_admin_expenses !== null
        ? (int) $plan->plan_gross_profit - (int) $plan->plan_selling_general_admin_expenses
        : null;
@endphp
<div class="annual-plan-page">
    <div class="page-header">
        <div>
            <div class="meta"><a href="{{ route('company-finance.index') }}">経営数値</a> / ANNUAL PLAN & PROGRESS</div>
            <h1>今年度計画と進捗</h1>
            <p>年度目標と月次実績を税抜きで管理し、残り期間を計画どおり進めた場合の着地見込みを確認します。</p>
        </div>
        <div class="actions">
            <a class="button secondary" href="{{ route('company-finance.index') }}">← 経営数値へ戻る</a>
            <a class="button secondary" href="{{ route('company-finance.repayment-capacity.index') }}">返済余力を見る</a>
        </div>
    </div>

    @if(session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif

    <form id="annual-plan-form" method="POST" action="{{ route('company-finance.annual-plan.update') }}">
        @csrf
        @method('PUT')

        <div class="period-banner">
            <div><span>対象事業年度</span><strong>{{ $fiscalYear }}年度</strong></div>
            <div><span>対象期間（JST）</span><strong>{{ $fiscalYear }}.{{ str_pad($startMonth, 2, '0', STR_PAD_LEFT) }}〜{{ $endYear }}.{{ str_pad($closingMonth, 2, '0', STR_PAD_LEFT) }}</strong></div>
            <label><span>期</span><input type="number" name="period_number" min="1" max="999" value="{{ $value('period_number') }}" placeholder="例：22" @disabled(!$canManage)></label>
            <div class="tax-badge"><span>計算基準</span><strong>税抜き</strong></div>
        </div>

        <section class="card plan-section">
            <div class="section-heading">
                <div><span class="meta">01 ANNUAL PLAN</span><h2>年間計画</h2></div>
                <p>期首に決めた目標です。月次の見込み計算と06の返済余力の基準になります。</p>
            </div>
            <div class="annual-fields">
                @foreach([
                    'plan_net_sales' => '売上高',
                    'plan_gross_profit' => '売上総利益',
                    'plan_selling_general_admin_expenses' => '販売費及び一般管理費',
                    'plan_net_income' => '当期純利益',
                    'plan_interest_expense' => '支払利息',
                    'plan_depreciation_expense' => '減価償却費',
                ] as $name => $label)
                    <label>
                        <span>{{ $label }}</span>
                        <input type="number" name="{{ $name }}" value="{{ $value($name) }}" step="1" @if($name !== 'plan_net_income') min="0" @endif @disabled(!$canManage)>
                    </label>
                @endforeach
            </div>
            <div class="calculated-row">
                <div><span>目標粗利率</span><strong id="plan-gross-margin">{{ $plan?->plan_net_sales ? number_format($plan->plan_gross_profit / $plan->plan_net_sales * 100, 1).'%' : '—' }}</strong></div>
                <div><span>目標営業利益</span><strong id="plan-operating-profit">{{ $amount($planOperatingProfit) }}</strong></div>
            </div>
        </section>

        <section class="card plan-section">
            <div class="section-heading">
                <div><span class="meta">02 MONTHLY PROGRESS</span><h2>月別計画と実績</h2></div>
                <div class="section-tools">
                    <p>売上・売上原価・販管費の数値を税抜きで入力します。売上実績を入力した月までを実績期間として扱います。</p>
                    @if($canManage)<button class="button secondary compact" type="button" id="distribute-sales">年間売上を12か月に配分</button>@endif
                </div>
            </div>
            <div class="monthly-table-wrap">
                <table class="monthly-table">
                    <thead>
                        <tr>
                            <th>月</th>
                            <th>目標売上</th>
                            <th>売上実績</th>
                            <th>売上原価実績</th>
                            <th>粗利実績</th>
                            <th>販管費実績<br><small>freee</small></th>
                            <th>営業利益実績</th>
                            <th>売上達成率</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($months as $index => $month)
                            @php
                                $oldMonth = old("months.$index", []);
                                $monthValue = fn ($field) => array_key_exists($field, $oldMonth)
                                    ? $oldMonth[$field]
                                    : data_get($month, $field);
                                $hasActual = $monthValue('actual_net_sales') !== null && $monthValue('actual_net_sales') !== '';
                                $actualGross = $hasActual
                                    ? (int) $monthValue('actual_net_sales') - (int) ($monthValue('actual_cost_of_sales') ?: 0)
                                    : null;
                                $actualOperating = $hasActual && $monthValue('actual_selling_general_admin_expenses') !== null
                                    ? $actualGross - (int) $monthValue('actual_selling_general_admin_expenses')
                                    : null;
                                $achievement = $hasActual && (int) $monthValue('plan_net_sales') > 0
                                    ? (int) $monthValue('actual_net_sales') / (int) $monthValue('plan_net_sales')
                                    : null;
                            @endphp
                            <tr class="month-row {{ $hasActual ? 'has-actual' : '' }}">
                                <td class="month-label">
                                    <strong>{{ $month->month->format('Y年n月') }}</strong>
                                    <span class="actual-label">{{ $hasActual ? '実績' : '計画' }}</span>
                                    <input type="hidden" name="months[{{ $index }}][month]" value="{{ $month->month->format('Y-m-d') }}">
                                </td>
                                @foreach([
                                    'plan_net_sales',
                                    'actual_net_sales',
                                    'actual_cost_of_sales',
                                ] as $field)
                                    <td><input type="number" name="months[{{ $index }}][{{ $field }}]" value="{{ $monthValue($field) }}" min="0" step="1" @disabled(!$canManage)></td>
                                @endforeach
                                <td class="calculated-cell actual-gross">{{ $amount($actualGross) }}</td>
                                <td><input type="number" name="months[{{ $index }}][actual_selling_general_admin_expenses]" value="{{ $monthValue('actual_selling_general_admin_expenses') }}" min="0" step="1" @disabled(!$canManage)></td>
                                <td class="calculated-cell actual-operating">{{ $amount($actualOperating) }}</td>
                                <td class="calculated-cell achievement">{{ $rate($achievement) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card plan-section forecast-section">
            <div class="section-heading">
                <div><span class="meta">03 LATEST FORECAST</span><h2>最新の着地見込み</h2></div>
                <p>実績入力済み月は実績、残りの月は目標売上と年間計画の利益率を使って自動計算します。</p>
            </div>
            <div class="forecast-grid">
                <div><span>実績入力</span><strong id="actual-month-count">{{ $forecast['actual_month_count'] ?? 0 }}か月</strong></div>
                <div><span>売上実績累計</span><strong id="actual-sales">{{ $amount($forecast['actual_sales'] ?? 0) }}</strong></div>
                <div><span>目標との差異</span><strong id="sales-variance" class="{{ ($forecast['sales_variance'] ?? 0) < 0 ? 'negative' : '' }}">{{ $amount($forecast['sales_variance'] ?? 0) }}</strong></div>
                <div><span>売上達成率</span><strong id="sales-achievement">{{ $rate($forecast['sales_achievement_rate'] ?? null) }}</strong></div>
                <div><span>粗利実績累計</span><strong id="actual-gross-profit">{{ $amount($forecast['actual_gross_profit'] ?? 0) }}</strong></div>
                <div><span>実績粗利率</span><strong id="actual-gross-margin">{{ $rate($forecast['gross_margin'] ?? null) }}</strong></div>
            </div>
            <div class="landing-forecast">
                <h3>このまま計画どおり進めた場合</h3>
                <div class="forecast-grid forecast-grid--landing">
                    <div><span>売上高見込み</span><strong id="forecast-sales">{{ $amount($forecast['forecast_sales'] ?? null) }}</strong></div>
                    <div><span>売上総利益見込み</span><strong id="forecast-gross-profit">{{ $amount($forecast['forecast_gross_profit'] ?? null) }}</strong></div>
                    <div><span>販管費見込み</span><strong id="forecast-sga">{{ $amount($forecast['forecast_sga'] ?? null) }}</strong></div>
                    <div><span>営業利益見込み</span><strong id="forecast-operating-profit">{{ $amount($forecast['forecast_operating_profit'] ?? null) }}</strong></div>
                    <div><span>年間売上目標との差</span><strong id="forecast-sales-variance" class="{{ ($forecast['forecast_sales_variance'] ?? 0) < 0 ? 'negative' : '' }}">{{ $amount($forecast['forecast_sales_variance'] ?? null) }}</strong></div>
                </div>
            </div>
            <div class="repayment-fields">
                <div>
                    <h3>返済余力に使う最新見込</h3>
                    <p>月次表だけでは算出できない項目です。現時点の見込みを入力すると06へ連携します。</p>
                </div>
                @foreach([
                    'forecast_net_income' => '当期純利益見込み',
                    'forecast_interest_expense' => '支払利息見込み',
                    'forecast_depreciation_expense' => '減価償却費見込み',
                ] as $name => $label)
                    <label><span>{{ $label }}</span><input type="number" name="{{ $name }}" value="{{ $value($name) }}" step="1" @if($name !== 'forecast_net_income') min="0" @endif @disabled(!$canManage)></label>
                @endforeach
            </div>
        </section>

        @if($canManage)
            <div class="actions plan-actions"><button type="submit">計画・進捗・見込みを保存</button></div>
        @endif
    </form>

    <div class="card connection-note">
        <h3>06 減価償却・返済余力との連携</h3>
        <p>自動計算した売上・粗利・販管費の着地見込みと、上で入力した当期純利益・支払利息・減価償却費の見込みを保存します。06では最新見込を優先してDSCRを計算します。</p>
    </div>
</div>

<style>
.annual-plan-page{width:min(1460px,calc(100vw - 32px));position:relative;left:50%;transform:translateX(-50%)}.period-banner{display:grid;grid-template-columns:1fr 1.4fr 140px 140px;gap:14px;margin-bottom:18px;padding:18px;border:1px solid #b7d5ce;border-radius:12px;background:#f2faf7}.period-banner>div,.period-banner label{display:flex;flex-direction:column;gap:5px}.period-banner span,.annual-fields span,.calculated-row span,.forecast-grid span,.repayment-fields span{color:var(--muted);font-size:12px}.period-banner strong{font-size:18px;color:var(--accent-dark)}.tax-badge{padding-left:16px;border-left:1px solid #b7d5ce}.plan-section{padding:22px;margin-bottom:18px}.section-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin-bottom:18px}.section-heading h2{margin:4px 0 0}.section-heading p{max-width:570px;margin:0;color:var(--muted);font-size:13px}.section-tools{display:flex;align-items:center;gap:12px}.button.compact{padding:9px 12px;white-space:nowrap}.annual-fields{display:grid;grid-template-columns:repeat(3,1fr);gap:13px}.annual-fields label,.repayment-fields label{display:flex;flex-direction:column;gap:6px}.calculated-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px}.calculated-row>div{display:flex;justify-content:space-between;padding:14px;border-radius:9px;background:#f4f8f8}.calculated-row strong{color:var(--accent-dark)}.monthly-table-wrap{overflow-x:auto}.monthly-table{width:100%;min-width:1240px;border-collapse:collapse}.monthly-table th,.monthly-table td{padding:9px 8px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap}.monthly-table th{background:#f1f6f7;color:#315b64;font-size:12px}.monthly-table th:first-child,.monthly-table td:first-child{text-align:left}.monthly-table input{min-width:132px;width:100%;padding:9px}.month-label strong{display:block}.actual-label{display:inline-block;margin-top:3px;padding:2px 7px;border-radius:10px;background:#eef2f3;color:var(--muted);font-size:11px}.has-actual .actual-label{background:#dff3ec;color:#176c57}.has-actual{background:#fbfefd}.calculated-cell{font-size:13px}.forecast-section{border-color:#9ec7d6}.forecast-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}.forecast-grid>div{min-height:78px;padding:14px;border:1px solid var(--line);border-radius:9px;background:#fff}.forecast-grid strong{display:block;margin-top:8px;color:var(--accent-dark);font-size:17px}.negative{color:#b23a3a!important}.landing-forecast{margin-top:18px;padding:18px;border-radius:11px;background:#f2faf7}.landing-forecast h3{margin-top:0}.forecast-grid--landing{grid-template-columns:repeat(5,1fr)}.repayment-fields{display:grid;grid-template-columns:1.5fr repeat(3,1fr);align-items:end;gap:13px;margin-top:18px}.repayment-fields h3{margin:0 0 5px}.repayment-fields p{margin:0;color:var(--muted);font-size:12px}.plan-actions{justify-content:flex-end;margin:16px 0 22px}.connection-note h3{margin-top:0}.connection-note p{margin-bottom:0}@media(max-width:950px){.period-banner,.annual-fields,.forecast-grid,.forecast-grid--landing,.repayment-fields{grid-template-columns:1fr 1fr}.section-heading,.section-tools{flex-direction:column}.tax-badge{padding-left:0;border-left:0}}@media(max-width:600px){.period-banner,.annual-fields,.forecast-grid,.forecast-grid--landing,.repayment-fields,.calculated-row{grid-template-columns:1fr}}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('annual-plan-form');
    if (!form) return;
    const rows = [...form.querySelectorAll('.month-row')];
    const number = value => value === '' || value === null ? null : Number(value);
    const input = name => form.querySelector(`[name="${name}"]`);
    const yen = value => value === null || !Number.isFinite(value) ? '—' : `${Math.round(value).toLocaleString('ja-JP')}円`;
    const percent = value => value === null || !Number.isFinite(value) ? '—' : `${(value * 100).toFixed(1)}%`;
    const set = (id, text, negative = false) => {
        const element = document.getElementById(id);
        if (!element) return;
        element.textContent = text;
        element.classList.toggle('negative', negative);
    };

    function recalculate() {
        const planSales = number(input('plan_net_sales')?.value) || 0;
        const planGross = number(input('plan_gross_profit')?.value) || 0;
        const planSga = number(input('plan_selling_general_admin_expenses')?.value) || 0;
        const grossMargin = planSales > 0 ? planGross / planSales : 0;
        let actualCount = 0, actualSales = 0, actualCost = 0, actualSga = 0;
        let elapsedPlan = 0, remainingPlan = 0;

        rows.forEach((row, index) => {
            const plan = number(input(`months[${index}][plan_net_sales]`)?.value) || 0;
            const salesValue = input(`months[${index}][actual_net_sales]`)?.value ?? '';
            const sales = number(salesValue);
            const cost = number(input(`months[${index}][actual_cost_of_sales]`)?.value) || 0;
            const sgaValue = input(`months[${index}][actual_selling_general_admin_expenses]`)?.value ?? '';
            const sga = number(sgaValue);
            const hasActual = sales !== null;

            row.classList.toggle('has-actual', hasActual);
            row.querySelector('.actual-label').textContent = hasActual ? '実績' : '計画';
            if (hasActual) {
                actualCount++;
                actualSales += sales;
                actualCost += cost;
                actualSga += sga || 0;
                elapsedPlan += plan;
                const gross = sales - cost;
                row.querySelector('.actual-gross').textContent = yen(gross);
                row.querySelector('.actual-operating').textContent = sga === null ? '—' : yen(gross - sga);
                row.querySelector('.achievement').textContent = plan > 0 ? percent(sales / plan) : '—';
            } else {
                remainingPlan += plan;
                row.querySelector('.actual-gross').textContent = '—';
                row.querySelector('.actual-operating').textContent = '—';
                row.querySelector('.achievement').textContent = '—';
            }
        });

        const actualGross = actualSales - actualCost;
        const variance = actualSales - elapsedPlan;
        const forecastSales = actualSales + remainingPlan;
        const forecastGross = actualGross + remainingPlan * grossMargin;
        const forecastSga = actualSga + (planSga / 12 * (12 - actualCount));
        const operating = planGross - planSga;

        set('plan-gross-margin', planSales > 0 ? percent(grossMargin) : '—');
        set('plan-operating-profit', yen(operating));
        set('actual-month-count', `${actualCount}か月`);
        set('actual-sales', yen(actualSales));
        set('sales-variance', yen(variance), variance < 0);
        set('sales-achievement', elapsedPlan > 0 ? percent(actualSales / elapsedPlan) : '—');
        set('actual-gross-profit', yen(actualGross));
        set('actual-gross-margin', actualSales > 0 ? percent(actualGross / actualSales) : '—');
        set('forecast-sales', yen(forecastSales));
        set('forecast-gross-profit', yen(forecastGross));
        set('forecast-sga', yen(forecastSga));
        set('forecast-operating-profit', yen(forecastGross - forecastSga), forecastGross - forecastSga < 0);
        set('forecast-sales-variance', yen(forecastSales - planSales), forecastSales - planSales < 0);
    }

    form.addEventListener('input', recalculate);
    document.getElementById('distribute-sales')?.addEventListener('click', () => {
        const total = number(input('plan_net_sales')?.value) || 0;
        const base = Math.floor(total / 12);
        rows.forEach((row, index) => {
            input(`months[${index}][plan_net_sales]`).value = index === 11 ? total - base * 11 : base;
        });
        recalculate();
    });
    recalculate();
});
</script>
@endsection
