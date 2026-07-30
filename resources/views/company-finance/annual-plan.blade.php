@extends('layouts.app', ['title' => '今年度計画と進捗 - 経営数値'])

@section('content')
@php
    $closingMonth = $organization->fiscal_year_end_month ?: 12;
    $startMonth = $closingMonth === 12 ? 1 : $closingMonth + 1;
    $endYear = $closingMonth === 12 ? $fiscalYear : $fiscalYear + 1;
    $value = fn (string $name) => old($name, data_get($plan, $name));
    $planSales = (int) ($value('plan_net_sales') ?: 0);
    $planGross = (int) ($value('plan_gross_profit') ?: 0);
    $planSga = (int) ($value('plan_selling_general_admin_expenses') ?: 0);
    $planCost = $planSales - $planGross;
    $planOperating = $planGross - $planSga;
    $money = fn ($number) => $number === null ? '—' : number_format((int) round($number));
    $inputMoney = fn ($number) => $number === null || $number === '' ? '' : number_format((int) $number);
    $actualMonths = $months->filter(fn ($month) => data_get($month, 'actual_net_sales') !== null);
    $actualMonthIndexes = $months
        ->filter(fn ($month) => data_get($month, 'actual_net_sales') !== null)
        ->keys()
        ->values();
    $monthlyActualAverages = [
        'sales' => (int) round($actualMonths->avg('actual_net_sales') ?: 0),
        'cost' => (int) round($actualMonths->avg('actual_cost_of_sales') ?: 0),
        'sga' => (int) round($actualMonths->avg('actual_selling_general_admin_expenses') ?: 0),
    ];
    $monthlyActualAverages['gross'] = $monthlyActualAverages['sales'] - $monthlyActualAverages['cost'];
    $monthlyActualAverages['operating'] = $monthlyActualAverages['gross'] - $monthlyActualAverages['sga'];
    $monthlyActualGrossMargin = $monthlyActualAverages['sales'] > 0
        ? $monthlyActualAverages['gross'] / $monthlyActualAverages['sales'] * 100
        : 0;
@endphp

<div class="annual-plan-page">
    <div class="page-header">
        <div>
            <div class="meta"><a href="{{ route('company-finance.index') }}">経営数値</a> / ANNUAL PLAN & PROGRESS</div>
            <h1>今年度計画と進捗</h1>
            <p>年間計画、単月、累計を同じ並びで確認し、年度の着地を判断します。</p>
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

        <div class="period-toolbar">
            <div><span>対象年度</span><strong>{{ $fiscalYear }}年度（{{ $fiscalYear }}.{{ str_pad($startMonth, 2, '0', STR_PAD_LEFT) }}〜{{ $endYear }}.{{ str_pad($closingMonth, 2, '0', STR_PAD_LEFT) }}）</strong></div>
            <label><span>期</span><input type="number" name="period_number" min="1" max="999" value="{{ $value('period_number') }}" @disabled(!$canManage)></label>
            <div class="cell-legend">
                <span><i class="editable-swatch"></i>入力できます</span>
                <span><i class="calculated-swatch"></i>自動計算</span>
                <span><i class="plan-swatch"></i>計画・見込</span>
            </div>
            <div class="tax-switch" aria-label="月次表の表示金額">
                <span>表示金額</span>
                <div>
                    <button type="button" class="tax-button active" data-tax-mode="exclusive">税抜</button>
                    <button type="button" class="tax-button" data-tax-mode="inclusive">税込</button>
                </div>
            </div>
        </div>

        <section class="card sheet-card">
            <div class="sheet-heading">
                <div>
                    <span class="meta">ANNUAL PLAN</span>
                    <div class="heading-with-help">
                        <h2>年間計画</h2>
                        <button type="button" class="reverse-plan-help" onclick="document.getElementById('reverse-plan-dialog').showModal()">逆算入力</button>
                    </div>
                </div>
                <p>入力・保存の基準は税抜です。税込は消費税10%で自動表示します。</p>
            </div>
            <input type="hidden" name="plan_gross_profit" value="{{ $planGross }}">
            <input type="hidden" name="plan_selling_general_admin_expenses" value="{{ $planSga }}">
            <div class="sheet-scroll">
                <table class="plan-sheet">
                    <thead>
                        <tr>
                            <th></th>
                            <th>売上目標</th>
                            <th>売上原価</th>
                            <th>粗利</th>
                            <th>販管費</th>
                            <th>営業利益</th>
                            <th>目標粗利率</th>
                            <th>売上原価率</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th>税抜</th>
                            <td class="input-cell"><input class="formatted-money-input" type="text" inputmode="numeric" name="plan_net_sales" value="{{ $inputMoney($value('plan_net_sales')) }}" @disabled(!$canManage)></td>
                            <td id="plan-cost-exclusive">{{ $money($planCost) }}</td>
                            <td id="plan-gross-exclusive">{{ $money($planGross) }}</td>
                            <td id="plan-sga-exclusive">{{ $money($planSga) }}</td>
                            <td class="input-cell"><input class="formatted-money-input" id="plan-operating-target" type="text" inputmode="numeric" value="{{ $inputMoney($planOperating) }}" @disabled(!$canManage)></td>
                            <td class="input-cell" rowspan="2"><div class="rate-input"><input id="plan-gross-margin-target" type="text" inputmode="decimal" value="{{ $planSales ? number_format($planGross / $planSales * 100, 1) : '' }}" @disabled(!$canManage)><span class="rate-suffix">%</span></div></td>
                            <td id="plan-cost-ratio" rowspan="2">{{ $planSales ? number_format($planCost / $planSales * 100, 1).'%' : '—' }}</td>
                        </tr>
                        <tr>
                            <th>税込</th>
                            <td id="plan-sales-inclusive">{{ $money($planSales * 1.1) }}</td>
                            <td id="plan-cost-inclusive">{{ $money($planCost * 1.1) }}</td>
                            <td id="plan-gross-inclusive">{{ $money($planGross * 1.1) }}</td>
                            <td id="plan-sga-inclusive">{{ $money($planSga * 1.1) }}</td>
                            <td id="plan-operating-inclusive">{{ $money($planOperating * 1.1) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="plan-supplement">
                <label><span>当期純利益計画</span><input class="formatted-money-input" type="text" inputmode="numeric" name="plan_net_income" value="{{ $inputMoney($value('plan_net_income')) }}" @disabled(!$canManage)></label>
                <label><span>支払利息計画</span><input class="formatted-money-input" type="text" inputmode="numeric" name="plan_interest_expense" value="{{ $inputMoney($value('plan_interest_expense')) }}" @disabled(!$canManage)></label>
                <label><span>減価償却費計画</span><input class="formatted-money-input" type="text" inputmode="numeric" name="plan_depreciation_expense" value="{{ $inputMoney($value('plan_depreciation_expense')) }}" @disabled(!$canManage)></label>
                @if($canManage)<button class="button secondary compact" type="button" id="distribute-sales">年間計画を12か月に反映</button>@endif
            </div>
            <div class="monthly-plan-bulk">
                <div class="bulk-heading">
                    <div><strong>選択期間の実績平均から残り月を計画</strong><span>選択した期間の平均額を、実績が未入力の月だけに反映します。</span></div>
                    <div class="plan-average-summary">
                        <span>選択期間の平均</span>
                        <div class="average-period">
                            <select id="average-period-from" aria-label="平均の開始月">
                                @foreach($actualMonthIndexes as $index)<option value="{{ $index }}">{{ $months[$index]->month->format('n月') }}</option>@endforeach
                            </select>
                            <span>〜</span>
                            <select id="average-period-to" aria-label="平均の終了月">
                                @foreach($actualMonthIndexes as $index)<option value="{{ $index }}" @selected($loop->last)>{{ $months[$index]->month->format('n月') }}</option>@endforeach
                            </select>
                        </div>
                        <b>売上 <i id="average-plan-sales">{{ $money($monthlyActualAverages['sales']) }}</i></b>
                        <b>原価 <i id="average-plan-cost">{{ $money($monthlyActualAverages['cost']) }}</i></b>
                        <b>粗利 <i id="average-plan-gross">{{ $money($monthlyActualAverages['gross']) }}</i></b>
                        <b>販管費 <i id="average-plan-sga">{{ $money($monthlyActualAverages['sga']) }}</i></b>
                        <b>営業利益 <i id="average-plan-operating">{{ $money($monthlyActualAverages['operating']) }}</i></b>
                    </div>
                </div>
                <div class="bulk-fields">
                    <label><span>売上</span><input class="formatted-money-input tax-convertible" id="bulk-plan-sales" type="text" inputmode="numeric" value="{{ $inputMoney($monthlyActualAverages['sales']) }}" data-tax-exclusive="{{ $monthlyActualAverages['sales'] }}" @disabled(!$canManage)></label>
                    <label><span>粗利率</span><div class="rate-input"><input id="bulk-plan-gross-margin" type="text" inputmode="decimal" value="{{ number_format($monthlyActualGrossMargin, 1) }}" @disabled(!$canManage)><span>%</span></div></label>
                    <label><span>販管費</span><input class="formatted-money-input tax-convertible" id="bulk-plan-sga" type="text" inputmode="numeric" value="{{ $inputMoney($monthlyActualAverages['sga']) }}" data-tax-exclusive="{{ $monthlyActualAverages['sga'] }}" @disabled(!$canManage)></label>
                    <div class="bulk-calculated"><span>売上原価</span><strong id="bulk-plan-cost">{{ $money($monthlyActualAverages['cost']) }}</strong></div>
                    <div class="bulk-calculated"><span>粗利</span><strong id="bulk-plan-gross">{{ $money($monthlyActualAverages['gross']) }}</strong></div>
                    <div class="bulk-calculated"><span>営業利益</span><strong id="bulk-plan-operating">{{ $money($monthlyActualAverages['operating']) }}</strong></div>
                    @if($canManage)
                        <div class="bulk-target">
                            <label><span>反映先</span><select id="bulk-target-month">
                                <option value="all">未入力月へ一括反映</option>
                                @foreach($months as $index => $month)
                                    @if(data_get($month, 'actual_net_sales') === null)
                                        <option value="{{ $index }}">{{ $month->month->format('n月') }}</option>
                                    @endif
                                @endforeach
                            </select></label>
                            <button type="button" id="apply-plan-averages">単月へセット</button>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        <dialog id="reverse-plan-dialog" class="reverse-plan-dialog">
            <div class="reverse-plan-dialog__header">
                <div><span class="meta">REVERSE PLANNING</span><h2>逆算入力とは</h2></div>
                <button type="button" class="secondary reverse-plan-dialog__close" onclick="this.closest('dialog').close()" aria-label="閉じる">×</button>
            </div>
            <div class="reverse-plan-dialog__body">
                <p>経営計画は、費用を積み上げて利益を予想するだけのものではありません。</p>
                <p>まず「いくら売るか」と「いくら利益を残すか」を決める。次に、必要な粗利と、使える販管費を逆算する。これが、目標から会社の動きを設計する「逆算経営」です。</p>
                <div class="reverse-plan-dialog__grid">
                    <section>
                        <h3>入力する数字</h3>
                        <ol class="reverse-plan-input-order">
                            <li><span>①</span>売上目標</li>
                            <li><span>②</span>目標粗利率</li>
                            <li><span>③</span>目標営業利益</li>
                        </ol>
                    </section>
                    <section>
                        <h3>自動で求める数字</h3>
                        <ul>
                            <li>粗利 ＝ 売上目標 × 目標粗利率</li>
                            <li>売上原価 ＝ 売上目標 − 粗利</li>
                            <li>販管費 ＝ 粗利 − 目標営業利益</li>
                        </ul>
                    </section>
                </div>
                <blockquote>利益は、最後に残った結果ではなく、最初に決める目標です。</blockquote>
            </div>
            <div class="reverse-plan-dialog__footer"><button type="button" onclick="this.closest('dialog').close()">閉じる</button></div>
        </dialog>

        <section class="card sheet-card">
            <div class="sheet-heading">
                <div><span class="meta">MONTHLY</span><h2>単月</h2></div>
                <p><span class="tax-mode-label">税抜</span>表示。月ごとの計画と実績を縦に確認できます。</p>
            </div>
            <div class="sheet-scroll">
                <table class="progress-sheet" id="monthly-sheet">
                    <thead>
                        <tr>
                            <th class="row-title">単月</th>
                            @foreach($months as $month)<th>{{ $month->month->format('Y年') }}<br><strong>{{ $month->month->format('n月') }}</strong></th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="plan-row plan-value-row">
                            <th>計画売上</th>
                            @foreach($months as $index => $month)
                                @php
                                    $oldMonth = old("months.$index", []);
                                    $monthValue = fn ($field) => array_key_exists($field, $oldMonth) ? $oldMonth[$field] : data_get($month, $field);
                                @endphp
                                <td class="input-cell">
                                    <input type="hidden" name="months[{{ $index }}][month]" value="{{ $month->month->format('Y-m-d') }}">
                                    <input class="sheet-input formatted-money-input tax-convertible" type="text" inputmode="numeric" name="months[{{ $index }}][plan_net_sales]" value="{{ $inputMoney($monthValue('plan_net_sales')) }}" data-tax-exclusive="{{ $monthValue('plan_net_sales') }}" @disabled(!$canManage)>
                                </td>
                            @endforeach
                        </tr>
                        @foreach([
                            ['plan_cost_of_sales', '計画売上原価'],
                            ['plan_selling_general_admin_expenses', '計画販管費'],
                        ] as [$planField, $planLabel])
                            <tr class="plan-row plan-value-row">
                                <th>{{ $planLabel }}</th>
                                @foreach($months as $index => $month)
                                    @php
                                        $oldMonth = old("months.$index", []);
                                        $planFieldValue = array_key_exists($planField, $oldMonth) ? $oldMonth[$planField] : data_get($month, $planField);
                                    @endphp
                                    <td class="input-cell">
                                        <input class="sheet-input formatted-money-input tax-convertible" type="text" inputmode="numeric" name="months[{{ $index }}][{{ $planField }}]" value="{{ $inputMoney($planFieldValue) }}" data-tax-exclusive="{{ $planFieldValue }}" @disabled(!$canManage)>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        @foreach([
                            ['actual_net_sales', '売上', 'actual-sales'],
                            [null, '差異', 'sales-variance'],
                            ['actual_cost_of_sales', '売上原価', 'actual-cost'],
                            [null, '粗利', 'actual-gross'],
                            ['actual_selling_general_admin_expenses', '販管費', 'actual-sga'],
                            [null, '営業利益', 'actual-operating'],
                        ] as [$field, $label, $class])
                            <tr class="{{ in_array($class, ['sales-variance','actual-operating']) ? 'emphasis-row' : '' }}">
                                <th>{{ $label }}</th>
                                @foreach($months as $index => $month)
                                    @php
                                        $oldMonth = old("months.$index", []);
                                        $fieldValue = $field ? (array_key_exists($field, $oldMonth) ? $oldMonth[$field] : data_get($month, $field)) : null;
                                    @endphp
                                    <td class="{{ $field ? 'input-cell' : 'calculated-cell' }}">
                                        @if($field)
                                            @php
                                                $forecastField = match ($field) {
                                                    'actual_net_sales' => 'forecast_net_sales',
                                                    'actual_cost_of_sales' => 'forecast_cost_of_sales',
                                                    'actual_selling_general_admin_expenses' => 'forecast_selling_general_admin_expenses',
                                                };
                                                $forecastValue = array_key_exists($forecastField, $oldMonth)
                                                    ? $oldMonth[$forecastField]
                                                    : data_get($month, $forecastField);
                                            @endphp
                                            <input class="sheet-input formatted-money-input tax-convertible forecast-entry" type="text" inputmode="numeric" name="months[{{ $index }}][{{ $forecastField }}]" value="{{ $inputMoney($forecastValue) }}" data-tax-exclusive="{{ $forecastValue }}" {{ $fieldValue !== null ? 'hidden' : '' }} @disabled(!$canManage)>
                                            <input class="sheet-input formatted-money-input tax-convertible actual-entry" type="text" inputmode="numeric" name="months[{{ $index }}][{{ $field }}]" value="{{ $inputMoney($fieldValue) }}" data-tax-exclusive="{{ $fieldValue }}" {{ $fieldValue === null ? 'hidden' : '' }} @disabled(!$canManage)>
                                        @else
                                            <span class="calculated {{ $class }}" data-index="{{ $index }}">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr class="ratio-separator"><th>売上達成率</th>@foreach($months as $index => $month)<td class="ratio-cell"><span class="monthly-achievement" data-index="{{ $index }}">—</span></td>@endforeach</tr>
                        <tr><th>原価率</th>@foreach($months as $index => $month)<td class="ratio-cell"><span class="monthly-cost-rate" data-index="{{ $index }}">—</span></td>@endforeach</tr>
                        <tr><th>粗利率</th>@foreach($months as $index => $month)<td class="ratio-cell"><span class="monthly-gross-rate" data-index="{{ $index }}">—</span></td>@endforeach</tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card sheet-card">
            <div class="sheet-heading">
                <div><span class="meta">CUMULATIVE</span><h2>累計</h2></div>
                <p>各月までの計画・実績・差異を累積表示します。</p>
            </div>
            <div class="sheet-scroll">
                <table class="progress-sheet" id="cumulative-sheet">
                    <thead>
                        <tr>
                            <th class="row-title">累計</th>
                            @foreach($months as $month)<th>{{ $month->month->format('Y年') }}<br><strong>{{ $month->month->format('n月') }}</strong></th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach([
                            ['目標売上', 'cumulative-plan', true],
                            ['売上', 'cumulative-sales', false],
                            ['差異', 'cumulative-variance', false],
                            ['売上原価', 'cumulative-cost', false],
                            ['粗利', 'cumulative-gross', false],
                            ['販管費', 'cumulative-sga', false],
                            ['営業利益', 'cumulative-operating', false],
                        ] as [$label, $class, $isPlan])
                            <tr class="{{ $isPlan ? 'plan-row' : ($class === 'cumulative-variance' || $class === 'cumulative-operating' ? 'emphasis-row' : '') }}">
                                <th>{{ $label }}</th>
                                @foreach($months as $index => $month)<td class="calculated-cell"><span class="{{ $class }}" data-index="{{ $index }}">—</span></td>@endforeach
                            </tr>
                        @endforeach
                        <tr class="ratio-separator"><th>売上達成率</th>@foreach($months as $index => $month)<td class="ratio-cell"><span class="cumulative-achievement" data-index="{{ $index }}">—</span></td>@endforeach</tr>
                        <tr><th>原価率</th>@foreach($months as $index => $month)<td class="ratio-cell"><span class="cumulative-cost-rate" data-index="{{ $index }}">—</span></td>@endforeach</tr>
                        <tr><th>粗利率</th>@foreach($months as $index => $month)<td class="ratio-cell"><span class="cumulative-gross-rate" data-index="{{ $index }}">—</span></td>@endforeach</tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card forecast-card">
            <div class="sheet-heading">
                <div><span class="meta">LATEST FORECAST</span><h2>最新の着地見込み</h2></div>
                <p>実績入力済み月＋残り月の計画から自動計算します。</p>
            </div>
            <div class="forecast-grid">
                <div><span>実績入力</span><strong id="actual-month-count">0か月</strong></div>
                <div><span>売上見込み</span><strong id="forecast-sales">—</strong></div>
                <div><span>粗利見込み</span><strong id="forecast-gross-profit">—</strong></div>
                <div><span>販管費見込み</span><strong id="forecast-sga">—</strong></div>
                <div><span>営業利益見込み</span><strong id="forecast-operating-profit">—</strong></div>
                <div><span>年間目標との差</span><strong id="forecast-sales-variance">—</strong></div>
            </div>
            <div class="repayment-fields">
                <div><h3>06へ連携する最新見込</h3><p>月次表から算出できない項目を入力します。</p></div>
                <label><span>当期純利益見込み</span><input class="formatted-money-input" type="text" inputmode="numeric" name="forecast_net_income" value="{{ $inputMoney($value('forecast_net_income')) }}" @disabled(!$canManage)></label>
                <label><span>支払利息見込み</span><input class="formatted-money-input" type="text" inputmode="numeric" name="forecast_interest_expense" value="{{ $inputMoney($value('forecast_interest_expense')) }}" @disabled(!$canManage)></label>
                <label><span>減価償却費見込み</span><input class="formatted-money-input" type="text" inputmode="numeric" name="forecast_depreciation_expense" value="{{ $inputMoney($value('forecast_depreciation_expense')) }}" @disabled(!$canManage)></label>
            </div>
        </section>

        @if($canManage)<div class="actions save-actions"><button type="submit">計画・進捗・見込みを保存</button></div>@endif
    </form>
</div>

<style>
.annual-plan-page{width:min(1540px,calc(100vw - 28px));position:relative;left:50%;transform:translateX(-50%)}.period-toolbar{display:flex;align-items:end;gap:22px;margin-bottom:18px;padding:16px 18px;border:1px solid #b7d5ce;border-radius:11px;background:#f2faf7}.period-toolbar>div:first-child{margin-right:auto}.period-toolbar span,.plan-supplement span,.forecast-grid span,.repayment-fields span{display:block;color:var(--muted);font-size:12px}.period-toolbar strong{display:block;margin-top:5px;color:var(--accent-dark)}.period-toolbar label{width:100px}.cell-legend{display:flex;gap:12px;padding-bottom:8px}.cell-legend span{display:flex;align-items:center;gap:5px;white-space:nowrap}.cell-legend i{width:14px;height:14px;border:1px solid #b5c5c7;border-radius:3px}.editable-swatch{background:#edf9f4}.calculated-swatch{background:#f0f2f3}.tax-switch>div{display:flex;margin-top:5px}.tax-button{padding:8px 16px;border:1px solid #aac5cb;background:#fff;color:#315b64}.tax-button:first-child{border-radius:7px 0 0 7px}.tax-button:last-child{border-radius:0 7px 7px 0}.tax-button.active{background:var(--accent-dark);color:#fff}.sheet-card,.forecast-card{padding:18px;margin-bottom:16px}.sheet-heading{display:flex;justify-content:space-between;align-items:start;gap:20px;margin-bottom:12px}.sheet-heading h2{margin:3px 0 0}.sheet-heading p{margin:0;color:var(--muted);font-size:13px}.sheet-scroll{overflow-x:auto;border:1px solid #aebdc0}.plan-sheet,.progress-sheet{width:100%;border-collapse:collapse;table-layout:fixed;font-variant-numeric:tabular-nums}.plan-sheet{min-width:1040px}.progress-sheet{min-width:1500px}.plan-sheet th,.plan-sheet td,.progress-sheet th,.progress-sheet td{border:1px solid #b5bec0;text-align:center}.plan-sheet th,.plan-sheet td{height:50px;padding:6px}.plan-sheet thead th,.progress-sheet thead th{background:#dfe6e8;color:#183e48}.plan-sheet tbody th{width:90px;background:#f8edc7}.plan-sheet tbody td{background:#f0f2f3;text-align:right;padding-right:10px}.plan-sheet td.input-cell{background:#edf9f4;padding:4px}.plan-sheet input{width:100%;min-width:120px;padding:9px 6px;text-align:right;font-variant-numeric:tabular-nums;border:1px solid transparent;background:transparent}.plan-sheet input:focus{border-color:#4c91a0;background:#fff}.plan-sheet td{font-weight:700}.plan-supplement{display:grid;grid-template-columns:repeat(3,1fr) auto;align-items:end;gap:12px;margin-top:14px}.plan-supplement label,.repayment-fields label{display:flex;flex-direction:column;gap:5px}.compact{padding:10px 13px;white-space:nowrap}.monthly-plan-bulk{margin-top:16px;padding:16px;border:1px solid #b8c9df;border-radius:10px;background:#f5f8fd}.bulk-heading{display:flex;align-items:center;justify-content:space-between;gap:16px}.bulk-heading span{display:block;margin-top:3px;color:var(--muted);font-size:12px}.plan-average-summary{display:flex;align-items:center;gap:13px}.plan-average-summary span{margin:0}.plan-average-summary b{color:#355f99;font-size:13px}.plan-average-summary i{font-style:normal}.bulk-fields{display:grid;grid-template-columns:repeat(3,1fr) auto;align-items:end;gap:10px;margin-top:12px}.bulk-fields label{margin:0}.progress-sheet th,.progress-sheet td{height:42px;padding:4px}.progress-sheet tbody td{background:#f0f2f3;text-align:right;padding-right:9px}.progress-sheet td.input-cell{background:#edf9f4;padding:3px}.progress-sheet .row-title,.progress-sheet tbody th{position:sticky;left:0;z-index:2;width:150px;background:#e5eaeb}.progress-sheet thead th{height:52px}.progress-sheet thead th:not(.row-title){width:112px}.progress-sheet .plan-row th{background:#e2eaf7;color:#355f99;z-index:3}.progress-sheet .plan-row td.input-cell{background:#edf2fb}.progress-sheet .plan-value-row input{color:#355f99;font-weight:800}.sheet-input{width:100%;min-width:90px;padding:7px 6px;text-align:right;font-variant-numeric:tabular-nums;border:1px solid transparent;background:transparent}.sheet-input:focus{border-color:#4c91a0;background:#fff}.calculated,.progress-sheet tbody td>span{display:block;width:100%;text-align:right;padding-right:0}.emphasis-row{border-bottom:3px solid #59747a}.ratio-separator{border-top:4px solid #59747a}.ratio-cell{padding:0!important;background:#f0f2f3!important}.ratio-cell span{padding:10px 8px!important}.rate-good{background:#aec3e6;color:#173f68;font-weight:700}.rate-watch{background:#efb788;color:#613819;font-weight:700}.negative{color:#d13b32!important;font-weight:700}.forecast-card{border-color:#9ec7d6}.forecast-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:9px}.forecast-grid>div{padding:13px;border:1px solid var(--line);border-radius:8px;background:#f8fbfb}.forecast-grid strong{display:block;margin-top:7px;color:var(--accent-dark);font-size:16px}.repayment-fields{display:grid;grid-template-columns:1.4fr repeat(3,1fr);align-items:end;gap:12px;margin-top:16px;padding-top:16px;border-top:1px solid var(--line)}.repayment-fields h3,.repayment-fields p{margin:0}.repayment-fields p{color:var(--muted);font-size:12px}.save-actions{justify-content:flex-end;margin:18px 0}@media(max-width:900px){.period-toolbar,.sheet-heading,.bulk-heading{align-items:stretch;flex-direction:column}.period-toolbar>div:first-child{margin-right:0}.cell-legend{padding-bottom:0}.plan-average-summary{flex-wrap:wrap}.plan-supplement,.bulk-fields,.forecast-grid,.repayment-fields{grid-template-columns:1fr 1fr}}@media(max-width:600px){.plan-supplement,.bulk-fields,.forecast-grid,.repayment-fields{grid-template-columns:1fr}}
.plan-swatch{background:#edf2fb}.progress-sheet .plan-value-row input{font-weight:700}.projected-value{background:#edf2fb!important;color:#355f99!important;font-weight:700}.progress-sheet td.input-cell:has(.forecast-entry:not([hidden])){background:#edf2fb}.forecast-entry{color:#355f99;font-weight:700}
.heading-with-help{display:flex;align-items:center;gap:10px}.heading-with-help h2{margin:3px 0 0}.reverse-plan-help{padding:4px 9px;border:1px solid #9dbbc2;border-radius:999px;background:#fff;color:var(--accent-dark);font-size:12px;font-weight:700}.reverse-plan-dialog{width:min(680px,calc(100vw - 28px));padding:0;border:0;border-radius:16px;box-shadow:0 24px 80px rgba(13,35,45,.3)}.reverse-plan-dialog::backdrop{background:rgba(13,30,39,.52)}.reverse-plan-dialog__header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--line)}.reverse-plan-dialog__header h2{margin:3px 0 0}.reverse-plan-dialog__close{width:42px;height:42px;padding:0;font-size:24px}.reverse-plan-dialog__body{padding:22px 24px}.reverse-plan-dialog__body>p:first-child{margin-top:0}.reverse-plan-dialog__grid{display:grid;grid-template-columns:1fr 1.35fr;gap:12px;margin:20px 0}.reverse-plan-dialog__grid section{padding:15px;border:1px solid var(--line);border-radius:10px;background:#f8fbfb}.reverse-plan-dialog__grid h3{margin:0 0 8px}.reverse-plan-dialog__grid p,.reverse-plan-dialog__grid ul{margin:0}.reverse-plan-dialog__grid li{margin:6px 0}.reverse-plan-dialog blockquote{margin:20px 0 0;padding:17px 18px;border-left:4px solid var(--accent-dark);background:#f2faf7;color:var(--accent-dark);font-size:17px;font-weight:800}.reverse-plan-dialog__footer{display:flex;justify-content:flex-end;padding:14px 24px;border-top:1px solid var(--line)}@media(max-width:600px){.reverse-plan-dialog__grid{grid-template-columns:1fr}}
.rate-input{display:flex;align-items:center;justify-content:flex-end;gap:5px}.plan-sheet .rate-input input{width:calc(100% - 22px);min-width:70px}.bulk-fields .rate-input input{width:100%;text-align:right}.rate-suffix{flex:0 0 auto}.bulk-fields{grid-template-columns:repeat(6,minmax(120px,1fr)) minmax(270px,auto)}.bulk-calculated{display:flex;min-height:44px;flex-direction:column;justify-content:center;padding:6px 10px;border:1px solid #b5c5c7;border-radius:7px;background:#f0f2f3;text-align:right}.bulk-calculated span{font-size:12px;color:var(--muted);text-align:left}.bulk-calculated strong{margin-top:3px}.bulk-target{display:grid;grid-template-columns:minmax(155px,1fr) auto;align-items:end;gap:8px}.bulk-target label{min-width:0}.bulk-target select{width:100%}.bulk-target button{white-space:nowrap}.reverse-plan-input-order{display:flex;flex-direction:column;gap:9px;margin:0;padding:0;list-style:none}.reverse-plan-input-order li{display:flex;align-items:center;gap:8px;margin:0}.reverse-plan-input-order li span{color:var(--accent-dark);font-weight:800}@media(max-width:1250px){.bulk-fields{grid-template-columns:repeat(3,1fr)}.bulk-target{grid-column:1/-1}}@media(max-width:700px){.bulk-fields{grid-template-columns:1fr}.bulk-target{grid-template-columns:1fr}}
.average-period{display:flex;align-items:center;gap:4px}.average-period span{margin:0}.average-period select{min-width:66px;padding:5px 7px}.rate-input{display:flex;align-items:center;justify-content:flex-end;gap:5px}.plan-sheet .rate-input input{width:calc(100% - 22px);min-width:70px}.bulk-fields .rate-input input{width:100%;text-align:right}.rate-suffix{flex:0 0 auto}.bulk-fields{grid-template-columns:repeat(6,minmax(120px,1fr)) minmax(270px,auto)}.bulk-calculated{display:flex;min-height:44px;flex-direction:column;justify-content:center;padding:6px 10px;border:1px solid #b5c5c7;border-radius:7px;background:#f0f2f3;text-align:right}.bulk-calculated span{font-size:12px;color:var(--muted);text-align:left}.bulk-calculated strong{margin-top:3px}.bulk-target{display:grid;grid-template-columns:minmax(155px,1fr) auto;align-items:end;gap:8px}.bulk-target label{min-width:0}.bulk-target select{width:100%}.bulk-target button{white-space:nowrap}.reverse-plan-input-order{display:flex;flex-direction:column;gap:9px;margin:0;padding:0;list-style:none}.reverse-plan-input-order li{display:flex;align-items:center;gap:8px;margin:0}.reverse-plan-input-order li span{color:var(--accent-dark);font-weight:800}@media(max-width:1250px){.bulk-fields{grid-template-columns:repeat(3,1fr)}.bulk-target{grid-column:1/-1}}@media(max-width:700px){.bulk-fields{grid-template-columns:1fr}.bulk-target{grid-template-columns:1fr}}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('annual-plan-form');
    if (!form) return;
    const count = 12;
    let taxMode = 'exclusive';
    let selectedAverageValues = null;
    const taxFactor = () => taxMode === 'inclusive' ? 1.1 : 1;
    const num = value => value === '' || value === null || value === undefined
        ? null
        : Number(String(value).replaceAll(',', '').trim());
    const field = name => form.querySelector(`[name="${name}"]`);
    const canonical = element => num(element?.dataset.taxExclusive);
    const money = value => value === null || !Number.isFinite(value) ? '—' : Math.round(value * taxFactor()).toLocaleString('ja-JP');
    const rawMoney = value => value === null || !Number.isFinite(value) ? '—' : Math.round(value).toLocaleString('ja-JP');
    const formatInput = value => value === null || !Number.isFinite(value) ? '' : Math.round(value).toLocaleString('ja-JP');
    const rate = value => value === null || !Number.isFinite(value) ? '—' : `${(value * 100).toFixed(1)}%`;
    const set = (selector, index, value, kind = 'money') => {
        const element = form.querySelector(`${selector}[data-index="${index}"]`);
        if (!element) return;
        element.textContent = kind === 'rate' ? rate(value) : money(value);
        element.classList.toggle('negative', kind === 'money' && value !== null && value < 0);
    };
    const assessRate = (selector, index, value, isGood) => {
        const element = form.querySelector(`${selector}[data-index="${index}"]`);
        if (!element) return;
        element.classList.toggle('rate-good', value !== null && isGood);
        element.classList.toggle('rate-watch', value !== null && !isGood);
    };
    const planValue = name => num(field(name)?.value) || 0;

    function updatePlan() {
        const sales = planValue('plan_net_sales');
        const operating = num(document.getElementById('plan-operating-target')?.value) || 0;
        const grossMargin = (num(document.getElementById('plan-gross-margin-target')?.value) || 0) / 100;
        const gross = Math.round(sales * grossMargin);
        const cost = sales - gross;
        const sga = gross - operating;
        field('plan_gross_profit').value = String(gross);
        field('plan_selling_general_admin_expenses').value = String(sga);
        const values = {
            'plan-cost-exclusive': cost,
            'plan-gross-exclusive': gross,
            'plan-sga-exclusive': sga,
            'plan-sales-inclusive': sales * 1.1,
            'plan-cost-inclusive': cost * 1.1,
            'plan-gross-inclusive': gross * 1.1,
            'plan-sga-inclusive': sga * 1.1,
            'plan-operating-inclusive': operating * 1.1,
        };
        Object.entries(values).forEach(([id, value]) => document.getElementById(id).textContent = rawMoney(value));
        document.getElementById('plan-cost-ratio').textContent = sales ? rate(cost / sales) : '—';
    }

    function distributeAnnualPlan() {
        const totals = {
            plan_net_sales: planValue('plan_net_sales'),
            plan_cost_of_sales: planValue('plan_net_sales') - planValue('plan_gross_profit'),
            plan_selling_general_admin_expenses: planValue('plan_selling_general_admin_expenses'),
        };

        Object.entries(totals).forEach(([name, total]) => {
            const base = Math.floor(total / count);
            for (let i = 0; i < count; i++) {
                const input = field(`months[${i}][${name}]`);
                const value = i === count - 1 ? total - base * (count - 1) : base;
                input.dataset.taxExclusive = String(value);
                input.value = formatInput(value * taxFactor());
            }
        });
    }

    function recalculate() {
        updatePlan();
        const annualSales = planValue('plan_net_sales');
        const annualGross = planValue('plan_gross_profit');
        const annualSga = planValue('plan_selling_general_admin_expenses');
        const plannedGrossMargin = annualSales ? annualGross / annualSales : 0;
        const plannedCostRate = annualSales ? (annualSales - annualGross) / annualSales : 0;
        let cumPlan = 0, cumSales = 0, cumCost = 0, cumSga = 0;
        let actualSalesTotal = 0, actualCostTotal = 0, actualSgaTotal = 0;
        let actualCount = 0, averageActualCount = 0, actualCostCount = 0, actualSgaCount = 0;
        const selectedFrom = Number(document.getElementById('average-period-from')?.value ?? 0);
        const selectedTo = Number(document.getElementById('average-period-to')?.value ?? count - 1);
        const averageStart = Math.min(selectedFrom, selectedTo);
        const averageEnd = Math.max(selectedFrom, selectedTo);
        const cumulativeSelectors = [
            '.cumulative-sales', '.cumulative-variance', '.cumulative-cost',
            '.cumulative-gross', '.cumulative-sga', '.cumulative-operating',
        ];

        for (let i = 0; i < count; i++) {
            const plan = canonical(field(`months[${i}][plan_net_sales]`)) || 0;
            const planCost = canonical(field(`months[${i}][plan_cost_of_sales]`)) || 0;
            const planSga = canonical(field(`months[${i}][plan_selling_general_admin_expenses]`)) || 0;
            const salesInput = field(`months[${i}][actual_net_sales]`);
            const costInput = field(`months[${i}][actual_cost_of_sales]`);
            const sgaInput = field(`months[${i}][actual_selling_general_admin_expenses]`);
            const forecastSalesInput = field(`months[${i}][forecast_net_sales]`);
            const forecastCostInput = field(`months[${i}][forecast_cost_of_sales]`);
            const forecastSgaInput = field(`months[${i}][forecast_selling_general_admin_expenses]`);
            const forecastSales = canonical(forecastSalesInput);
            const forecastCost = canonical(forecastCostInput);
            const forecastSga = canonical(forecastSgaInput);
            const sales = canonical(salesInput);
            const costRaw = canonical(costInput);
            const cost = costRaw || 0;
            const sgaRaw = canonical(sgaInput);
            const hasActual = sales !== null;
            const gross = hasActual ? sales - cost : null;
            const operating = hasActual && sgaRaw !== null ? gross - sgaRaw : null;
            const displayedSales = hasActual ? sales : forecastSales;
            const displayedCost = hasActual ? cost : forecastCost;
            const displayedSga = hasActual ? sgaRaw : forecastSga;
            const displayedGross = displayedSales !== null && displayedCost !== null
                ? displayedSales - displayedCost
                : null;
            const displayedOperating = displayedGross !== null && displayedSga !== null
                ? displayedGross - displayedSga
                : null;

            [salesInput, costInput, sgaInput].forEach(input => {
                if (input) input.hidden = !hasActual;
            });
            [[forecastSalesInput, forecastSales], [forecastCostInput, forecastCost], [forecastSgaInput, forecastSga]].forEach(([input, forecastValue]) => {
                if (!input) return;
                input.hidden = hasActual;
            });

            set('.sales-variance', i, displayedSales !== null ? displayedSales - plan : null);
            set('.actual-gross', i, displayedGross);
            set('.actual-operating', i, displayedOperating);
            const monthlyAchievement = plan ? displayedSales / plan : null;
            const monthlyCostRate = displayedSales ? displayedCost / displayedSales : null;
            const monthlyGrossRate = displayedSales ? displayedGross / displayedSales : null;
            set('.monthly-achievement', i, monthlyAchievement, 'rate');
            set('.monthly-cost-rate', i, monthlyCostRate, 'rate');
            set('.monthly-gross-rate', i, monthlyGrossRate, 'rate');
            assessRate('.monthly-achievement', i, monthlyAchievement, monthlyAchievement >= 1);
            assessRate('.monthly-cost-rate', i, monthlyCostRate, monthlyCostRate <= plannedCostRate);
            assessRate('.monthly-gross-rate', i, monthlyGrossRate, monthlyGrossRate >= plannedGrossMargin);
            ['.sales-variance', '.actual-gross', '.actual-operating'].forEach(selector => {
                const element = form.querySelector(`${selector}[data-index="${i}"]`);
                element?.classList.toggle('projected-value', !hasActual);
            });

            cumPlan += plan;
            set('.cumulative-plan', i, cumPlan);
            if (hasActual) {
                actualCount++;
                if (i >= averageStart && i <= averageEnd) {
                    actualSalesTotal += sales;
                    averageActualCount++;
                    if (costRaw !== null) {
                        actualCostTotal += costRaw;
                        actualCostCount++;
                    }
                    if (sgaRaw !== null) {
                        actualSgaTotal += sgaRaw;
                        actualSgaCount++;
                    }
                }
                cumSales += sales;
                cumCost += cost;
                cumSga += sgaRaw || 0;
            } else {
                cumSales += forecastSales ?? 0;
                cumCost += forecastCost ?? 0;
                cumSga += forecastSga ?? 0;
            }

            const cumGross = cumSales - cumCost;
            set('.cumulative-sales', i, cumSales);
            set('.cumulative-variance', i, cumSales - cumPlan);
            set('.cumulative-cost', i, cumCost);
            set('.cumulative-gross', i, cumGross);
            set('.cumulative-sga', i, cumSga);
            set('.cumulative-operating', i, cumGross - cumSga);
            set('.cumulative-achievement', i, cumPlan ? cumSales / cumPlan : null, 'rate');
            set('.cumulative-cost-rate', i, cumSales ? cumCost / cumSales : null, 'rate');
            set('.cumulative-gross-rate', i, cumSales ? cumGross / cumSales : null, 'rate');
            const cumulativeAchievement = cumPlan ? cumSales / cumPlan : null;
            const cumulativeCostRate = cumSales ? cumCost / cumSales : null;
            const cumulativeGrossRate = cumSales ? cumGross / cumSales : null;
            assessRate('.cumulative-achievement', i, cumulativeAchievement, cumulativeAchievement >= 1);
            assessRate('.cumulative-cost-rate', i, cumulativeCostRate, cumulativeCostRate <= plannedCostRate);
            assessRate('.cumulative-gross-rate', i, cumulativeGrossRate, cumulativeGrossRate >= plannedGrossMargin);
            cumulativeSelectors.forEach(selector => {
                const element = form.querySelector(`${selector}[data-index="${i}"]`);
                element?.classList.toggle('projected-value', !hasActual);
            });
        }

        document.getElementById('average-plan-sales').textContent = money(averageActualCount ? actualSalesTotal / averageActualCount : null);
        document.getElementById('average-plan-cost').textContent = money(actualCostCount ? actualCostTotal / actualCostCount : null);
        document.getElementById('average-plan-sga').textContent = money(actualSgaCount ? actualSgaTotal / actualSgaCount : null);
        const averageSales = averageActualCount ? actualSalesTotal / averageActualCount : null;
        const averageCost = actualCostCount ? actualCostTotal / actualCostCount : null;
        const averageSga = actualSgaCount ? actualSgaTotal / actualSgaCount : null;
        const averageGross = averageSales !== null && averageCost !== null ? averageSales - averageCost : null;
        const averageOperating = averageGross !== null && averageSga !== null ? averageGross - averageSga : null;
        selectedAverageValues = {
            sales: averageSales,
            grossMargin: averageSales ? averageGross / averageSales * 100 : null,
            sga: averageSga,
        };
        document.getElementById('average-plan-gross').textContent = money(averageGross);
        document.getElementById('average-plan-operating').textContent = money(averageOperating);
        const bulkSales = canonical(document.getElementById('bulk-plan-sales'));
        const bulkGrossMargin = (num(document.getElementById('bulk-plan-gross-margin')?.value) || 0) / 100;
        const bulkSga = canonical(document.getElementById('bulk-plan-sga'));
        const bulkGross = bulkSales !== null ? Math.round(bulkSales * bulkGrossMargin) : null;
        const bulkCost = bulkSales !== null && bulkGross !== null ? bulkSales - bulkGross : null;
        const bulkOperating = bulkGross !== null && bulkSga !== null ? bulkGross - bulkSga : null;
        document.getElementById('bulk-plan-cost').textContent = money(bulkCost);
        document.getElementById('bulk-plan-gross').textContent = money(bulkGross);
        document.getElementById('bulk-plan-operating').textContent = money(bulkOperating);
        const forecastSales = cumSales;
        const forecastGross = cumSales - cumCost;
        const forecastSga = cumSga;
        const forecast = {
            'actual-month-count': `${actualCount}か月`,
            'forecast-sales': money(forecastSales),
            'forecast-gross-profit': money(forecastGross),
            'forecast-sga': money(forecastSga),
            'forecast-operating-profit': money(forecastGross - forecastSga),
            'forecast-sales-variance': money(forecastSales - annualSales),
        };
        Object.entries(forecast).forEach(([id, text]) => {
            const element = document.getElementById(id);
            element.textContent = text;
            element.classList.toggle('negative', id !== 'actual-month-count' && (id === 'forecast-operating-profit' ? forecastGross - forecastSga : id === 'forecast-sales-variance' ? forecastSales - annualSales : 0) < 0);
        });
    }

    form.querySelectorAll('.tax-convertible').forEach(input => {
        input.addEventListener('input', () => {
            const entered = num(input.value);
            input.dataset.taxExclusive = input.value === ''
                ? ''
                : String(taxMode === 'inclusive' ? Math.round(entered / 1.1) : entered);
        });
    });
    form.querySelectorAll('.formatted-money-input').forEach(input => {
        input.addEventListener('focus', () => {
            const value = num(input.value);
            input.value = value === null ? '' : String(value);
        });
        input.addEventListener('blur', () => {
            const value = num(input.value);
            input.value = formatInput(value);
        });
    });
    field('plan_net_sales')?.addEventListener('input', () => {
        updatePlan();
        distributeAnnualPlan();
    });
    ['plan-operating-target', 'plan-gross-margin-target'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', () => {
            updatePlan();
            distributeAnnualPlan();
        });
    });
    function loadSelectedAverageIntoSetter() {
        if (!selectedAverageValues) return;
        [
            ['bulk-plan-sales', selectedAverageValues.sales],
            ['bulk-plan-sga', selectedAverageValues.sga],
        ].forEach(([id, value]) => {
            const input = document.getElementById(id);
            input.dataset.taxExclusive = value === null ? '' : String(Math.round(value));
            input.value = formatInput(value === null ? null : value * taxFactor());
        });
        document.getElementById('bulk-plan-gross-margin').value = selectedAverageValues.grossMargin === null
            ? ''
            : selectedAverageValues.grossMargin.toFixed(1);
    }
    ['average-period-from', 'average-period-to'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => {
            recalculate();
            loadSelectedAverageIntoSetter();
            recalculate();
        });
    });
    form.addEventListener('input', recalculate);
    document.querySelectorAll('.tax-button').forEach(button => button.addEventListener('click', () => {
        taxMode = button.dataset.taxMode;
        document.querySelectorAll('.tax-button').forEach(item => item.classList.toggle('active', item === button));
        document.querySelectorAll('.tax-mode-label').forEach(item => item.textContent = taxMode === 'inclusive' ? '税込' : '税抜');
        form.querySelectorAll('.tax-convertible').forEach(input => {
            const value = canonical(input);
            input.value = formatInput(value === null ? null : value * taxFactor());
        });
        recalculate();
    }));
    document.getElementById('distribute-sales')?.addEventListener('click', () => {
        distributeAnnualPlan();
        recalculate();
    });
    document.getElementById('apply-plan-averages')?.addEventListener('click', () => {
        const sales = canonical(document.getElementById('bulk-plan-sales')) || 0;
        const grossMargin = (num(document.getElementById('bulk-plan-gross-margin')?.value) || 0) / 100;
        const gross = Math.round(sales * grossMargin);
        const values = {
            forecast_net_sales: sales,
            forecast_cost_of_sales: sales - gross,
            forecast_selling_general_admin_expenses: canonical(document.getElementById('bulk-plan-sga')) || 0,
        };
        const target = document.getElementById('bulk-target-month')?.value || 'all';
        for (let i = 0; i < count; i++) {
            if (canonical(field(`months[${i}][actual_net_sales]`)) !== null) continue;
            if (target !== 'all' && Number(target) !== i) continue;
            Object.entries(values).forEach(([name, value]) => {
                const input = field(`months[${i}][${name}]`);
                input.dataset.taxExclusive = String(value);
                input.value = formatInput(value * taxFactor());
            });
        }
        recalculate();
    });
    form.addEventListener('submit', () => {
        form.querySelectorAll('.formatted-money-input').forEach(input => {
            const value = num(input.value);
            input.value = value === null ? '' : String(value);
        });
        form.querySelectorAll('.sheet-input').forEach(input => input.value = input.dataset.taxExclusive);
    });
    recalculate();
});
</script>
@endsection
