@extends('layouts.app', ['title' => '今年度計画と進捗 - 経営数値'])

@section('content')
@php
    $closingMonth = $organization->fiscal_year_end_month ?: 12;
    $startMonth = $closingMonth === 12 ? 1 : $closingMonth + 1;
    $endYear = $closingMonth === 12 ? $fiscalYear : $fiscalYear + 1;
    $fields = [
        'net_sales' => '売上高',
        'gross_profit' => '売上総利益',
        'selling_general_admin_expenses' => '販売費及び一般管理費',
        'net_income' => '当期純利益',
        'interest_expense' => '支払利息',
        'depreciation_expense' => '減価償却費',
    ];
    $value = fn (string $name) => old($name, data_get($plan, $name));
    $operating = function (string $kind) use ($plan) {
        $gross = data_get($plan, $kind.'_gross_profit');
        $sga = data_get($plan, $kind.'_selling_general_admin_expenses');
        return $gross !== null && $sga !== null ? (int) $gross - (int) $sga : null;
    };
@endphp
<div class="annual-plan-page">
    <div class="page-header">
        <div>
            <div class="meta"><a href="{{ route('company-finance.index') }}">経営数値</a> / ANNUAL PLAN</div>
            <h1>今年度計画と進捗</h1>
            <p>今期の目標と最新見込を並べ、経営判断に使う現在地を整理します。</p>
        </div>
        <div class="actions">
            <a class="button secondary" href="{{ route('company-finance.index') }}">← 経営数値へ戻る</a>
            <a class="button secondary" href="{{ route('company-finance.repayment-capacity.index') }}">返済余力を見る</a>
        </div>
    </div>
    @if(session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif

    <div class="period-banner">
        <div><span>対象事業年度</span><strong>{{ $fiscalYear }}年度</strong></div>
        <div><span>対象期間</span><strong>{{ $fiscalYear }}.{{ str_pad($startMonth, 2, '0', STR_PAD_LEFT) }}〜{{ $endYear }}.{{ str_pad($closingMonth, 2, '0', STR_PAD_LEFT) }}</strong></div>
        <label><span>期</span><input type="number" name="period_number" form="annual-plan-form" min="1" max="999" value="{{ $value('period_number') }}" placeholder="例：22" @disabled(!$canManage)></label>
    </div>

    <form id="annual-plan-form" method="POST" action="{{ route('company-finance.annual-plan.update') }}">
        @csrf
        @method('PUT')
        <div class="plan-columns">
            @foreach(['plan'=>'年度計画','forecast'=>'最新見込'] as $kind=>$title)
                <section class="card plan-card plan-card--{{ $kind }}">
                    <div class="plan-card__heading">
                        <div><span class="meta">{{ $kind === 'plan' ? 'PLAN' : 'LATEST FORECAST' }}</span><h2>{{ $title }}</h2></div>
                        <p>{{ $kind === 'plan' ? '期首に決めた目標です。原則として基準値として残します。' : '進捗を踏まえた現在の着地予測です。未入力なら年度計画を使用します。' }}</p>
                    </div>
                    <div class="plan-fields">
                        @foreach($fields as $field=>$label)
                            <label>
                                <span>{{ $label }}</span>
                                <input type="number" name="{{ $kind }}_{{ $field }}" value="{{ $value($kind.'_'.$field) }}" step="1" @if($field !== 'net_income') min="0" @endif @disabled(!$canManage)>
                            </label>
                        @endforeach
                    </div>
                    <div class="calculated-profit">
                        <span>営業利益（売上総利益－販管費）</span>
                        <strong>{{ $operating($kind) === null ? '—' : number_format($operating($kind)).'円' }}</strong>
                    </div>
                </section>
            @endforeach
        </div>
        @if($canManage)<div class="actions plan-actions"><button type="submit">計画・最新見込を保存</button></div>@endif
    </form>

    <div class="card connection-note">
        <h3>06 減価償却・返済余力との連携</h3>
        <p>最新見込が入力されていれば最新見込を、未入力なら年度計画を返済シミュレーションの初期値として使用します。返済シミュレーション側で変更しても、ここに保存した計画・見込は書き換わりません。</p>
    </div>
</div>
<style>
.annual-plan-page{width:min(1320px,calc(100vw - 32px));position:relative;left:50%;transform:translateX(-50%)}.period-banner{display:grid;grid-template-columns:1fr 1fr 180px;gap:14px;margin-bottom:18px;padding:18px;border:1px solid #b7d5ce;border-radius:12px;background:#f2faf7}.period-banner>div,.period-banner label{display:flex;flex-direction:column;gap:5px}.period-banner span,.plan-fields span,.calculated-profit span{color:var(--muted);font-size:12px}.period-banner strong{font-size:19px;color:var(--accent-dark)}.plan-columns{display:grid;grid-template-columns:1fr 1fr;gap:18px}.plan-card{padding:22px}.plan-card--forecast{border-color:#9ec7d6}.plan-card__heading{display:flex;justify-content:space-between;gap:18px;margin-bottom:18px}.plan-card__heading h2{margin-top:4px}.plan-card__heading p{max-width:330px;margin:0;font-size:13px}.plan-fields{display:grid;grid-template-columns:1fr 1fr;gap:13px}.plan-fields label{display:flex;flex-direction:column;gap:6px}.calculated-profit{display:flex;align-items:center;justify-content:space-between;margin-top:16px;padding:14px;border-radius:9px;background:#f4f8f8}.calculated-profit strong{color:var(--accent-dark);font-size:19px}.plan-actions{justify-content:flex-end;margin-top:16px}.connection-note{margin-top:18px}.connection-note h3{margin-top:0}.connection-note p{margin-bottom:0}@media(max-width:800px){.period-banner,.plan-columns{grid-template-columns:1fr}.plan-card__heading{flex-direction:column}.plan-fields{grid-template-columns:1fr}}
</style>
@endsection
