@extends('layouts.app', ['title' => '借入残高推移表 - COMPANY OS'])

@section('content')
<div class="loan-schedule-page">
    <div class="page-header">
        <div>
            <div class="meta">COMPANY OS / DEBT &amp; FUNDING</div>
            <h1>借入残高推移表</h1>
            <p>登録済みの借入条件から月末予定残高を自動計算し、実績残高がある月はその値を表示します。</p>
        </div>
        <div class="actions">
            <a class="button secondary" href="{{ route('company-loans.index') }}">← 借入管理へ戻る</a>
        </div>
    </div>

    <form class="card schedule-filter" method="GET">
        <label><span>開始年</span><select name="start">@foreach($startYearOptions as $year)<option value="{{ $year }}-01" @selected($start->year === $year)>{{ $year }}年</option>@endforeach</select></label>
        <label><span>表示年数</span><select name="years">@foreach([5,10,15,20] as $years)<option value="{{ $years }}" @selected($durationYears === $years)>{{ $years }}年</option>@endforeach</select></label>
        <button>表示を更新</button>
        <a class="button secondary" href="{{ route('company-loans.schedule') }}">標準に戻す</a>
        <span class="meta">開始年は登録データの範囲から選択できます。●は登録済みの実績残高です。</span>
    </form>

    @if($loans->isEmpty())
        <div class="card"><h2>借入情報がありません</h2><p>借入を登録すると、ここに月別の残高推移が表示されます。</p></div>
    @else
        @php
            $activeLoans = $loans->where('loan_status', \App\Models\CompanyLoan::STATUS_ACTIVE);
            $sortUrl = fn(string $key) => route('company-loans.schedule', [
                'start' => $start->format('Y-m'),
                'years' => $durationYears,
                'sort' => $key,
                'direction' => $sort === $key && $direction === 'asc' ? 'desc' : 'asc',
            ]);
            $sortMark = fn(string $key) => $sort === $key ? ($direction === 'asc' ? '▲' : '▼') : '↕';
        @endphp
        <div class="card schedule-wrap">
            <table class="schedule-table">
                <thead>
                    <tr>
                        <th class="sticky-year">年度</th>
                        <th class="sticky-month">月</th>
                        @foreach($loans as $loan)<th class="{{ $loan->loan_status === 'completed' ? 'loan-completed' : '' }}">@if($canManage)<a class="loan-no loan-edit-link" href="{{ route('company-loans.edit', $loan) }}" title="No.{{ $loan->management_number }}を編集">No.{{ $loan->management_number }}</a>@else<span class="loan-no">No.{{ $loan->management_number }}</span>@endif @if($loan->loan_status === 'completed')<small class="completed-label">完済{{ $loan->completed_on ? ' '.$loan->completed_on->format('Y.m') : '' }}</small>@endif</th>@endforeach
                        <th class="total-column">残高合計</th>
                        <th class="repayment-column">元本返済額</th>
                    </tr>
                    <tr>
                        <th class="sticky-year"></th><th class="sticky-month"><a class="sort-link" href="{{ $sortUrl('institution') }}">金融機関 {{ $sortMark('institution') }}</a></th>
                        @foreach($loans as $loan)<th class="{{ $loan->loan_status === 'completed' ? 'loan-completed' : '' }}">{{ $loan->financial_institution }}</th>@endforeach
                        <th class="total-column"></th>
                        <th class="repayment-column"></th>
                    </tr>
                    <tr>
                        <th class="sticky-year"></th><th class="sticky-month"><a class="sort-link" href="{{ $sortUrl('amount') }}">借入額 {{ $sortMark('amount') }}</a></th>
                        @foreach($loans as $loan)<th class="{{ $loan->loan_status === 'completed' ? 'loan-completed' : '' }}">{{ number_format($loan->original_amount) }}</th>@endforeach
                        <th class="total-column">{{ number_format($activeLoans->sum('original_amount')) }}</th>
                        <th class="repayment-column"></th>
                    </tr>
                    <tr>
                        <th class="sticky-year"></th><th class="sticky-month"><a class="sort-link" href="{{ $sortUrl('monthly') }}">返済／月 {{ $sortMark('monthly') }}</a></th>
                        @foreach($loans as $loan)<th class="{{ $loan->loan_status === 'completed' ? 'loan-completed' : '' }}">{{ number_format($schedulePayments[$loan->id]) }}@if((int)$loan->monthly_principal_payment === 0 && $schedulePayments[$loan->id] > 0)<small class="calculated-label">自動計算</small>@endif</th>@endforeach
                        <th class="total-column">{{ number_format($activeLoans->sum(fn($loan) => $schedulePayments[$loan->id] ?? 0)) }}</th>
                        <th class="repayment-column"></th>
                    </tr>
                    <tr>
                        <th class="sticky-year"></th><th class="sticky-month">返済回数</th>
                        @foreach($loans as $loan)<th class="{{ $loan->loan_status === 'completed' ? 'loan-completed' : '' }}">{{ $loan->scheduled_payment_count ? number_format($loan->scheduled_payment_count).'回' : '—' }}</th>@endforeach
                        <th class="total-column"></th>
                        <th class="repayment-column"></th>
                    </tr>
                    <tr>
                        <th class="sticky-year"></th><th class="sticky-month"><a class="sort-link" href="{{ $sortUrl('term') }}">期間 {{ $sortMark('term') }}</a></th>
                        @foreach($loans as $loan)<th class="{{ $loan->loan_status === 'completed' ? 'loan-completed' : '' }}">{{ $loan->term_label ?: '—' }}</th>@endforeach
                        <th class="total-column"></th>
                        <th class="repayment-column"></th>
                    </tr>
                    <tr>
                        <th class="sticky-year"></th><th class="sticky-month">計算方式</th>
                        @foreach($loans as $loan)<th class="{{ $loan->loan_status === 'completed' ? 'loan-completed' : '' }}">{{ ['amortizing'=>'元金返済','hold'=>'据置','bullet'=>'期日一括','revolving'=>'当座貸越'][$loan->balance_projection_mode] ?? '元金返済' }}</th>@endforeach
                        <th class="total-column"></th>
                        <th class="repayment-column"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php($newYear = $loop->first || $row['month']->month === 1)
                        <tr class="{{ $newYear ? 'year-start' : '' }}">
                            <th class="sticky-year">{{ $newYear ? $row['month']->format('Y').'年' : '' }}</th>
                            <th class="sticky-month">{{ $row['month']->month }}</th>
                            @foreach($loans as $loan)
                                @php($cell = $row['cells'][$loan->id])
                                <td class="{{ $cell['actual'] ? 'is-actual ' : '' }}{{ $loan->loan_status === 'completed' ? 'loan-completed' : '' }}">
                                    @if($cell['balance'] !== null)
                                        @if($cell['payment_number'])<span class="payment-number" title="{{ $cell['payment_number'] }}回目の返済後残高">{{ $cell['payment_number'] }}回</span>@endif
                                        @if($cell['actual'])<span class="actual-mark" title="実績">●</span>@endif{{ number_format($cell['balance']) }}
                                    @endif
                                </td>
                            @endforeach
                            <td class="total-column"><strong>{{ number_format($row['total']) }}</strong></td>
                            <td class="repayment-column"><strong>{{ number_format($row['principal_repaid']) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card schedule-note">
            <strong>計算方法</strong>
            <p>残高基準日を起点に、月額元金を加減して予定残高を計算しています。当座貸越など月額元金が0円の契約は残高を維持します。繰上返済・据置・不規則返済は、今後追加する返済イベント機能で調整できるようにします。</p>
        </div>
    @endif
</div>

<style>
.loan-schedule-page{width:min(1800px,calc(100vw - 24px));position:relative;left:50%;transform:translateX(-50%)}
.schedule-filter{display:flex;align-items:center;flex-wrap:nowrap;gap:14px;margin-bottom:16px;overflow-x:auto}
.schedule-filter label{display:flex;align-items:center;gap:7px;flex:0 0 auto}.schedule-filter label span{color:var(--muted);font-size:12px;font-weight:700;white-space:nowrap}
.schedule-filter select{min-width:112px}.schedule-filter .button,.schedule-filter button{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;margin:0}.schedule-filter .meta{display:flex;align-items:center;flex:0 0 auto;min-height:42px;margin:0}
.schedule-wrap{padding:0;overflow:auto;max-height:calc(100vh - 250px)}
.schedule-table{border-collapse:separate;border-spacing:0;min-width:max-content;font-size:12px;font-variant-numeric:tabular-nums}
.schedule-table th,.schedule-table td{min-width:118px;padding:7px 9px;border-right:1px solid #dce4e7;border-bottom:1px solid #dce4e7;text-align:right;white-space:nowrap;background:#fff}
.schedule-table thead{position:sticky;top:0;z-index:8}
.schedule-table thead th{position:relative;z-index:4;background:#edf3f4;text-align:center;font-weight:700}
.schedule-table .sticky-year{position:sticky;left:0;z-index:3;min-width:76px;width:76px;text-align:center;background:#f5f7f8}
.schedule-table .sticky-month{position:sticky;left:76px;z-index:3;min-width:80px;width:80px;text-align:center;background:#f5f7f8;box-shadow:2px 0 0 #cbd7dc}
.schedule-table thead .sticky-year,.schedule-table thead .sticky-month{z-index:7;background:#e5edef}
.schedule-table .total-column{position:sticky;right:118px;z-index:3;background:#eaf5f2;border-left:2px solid #7ea99f}
.schedule-table .repayment-column{position:sticky;right:0;z-index:3;background:#fff5e8;border-left:1px solid #d9b77e}
.schedule-table thead .total-column,.schedule-table thead .repayment-column{z-index:7}
.schedule-table tbody tr.year-start th,.schedule-table tbody tr.year-start td{border-top:2px solid #7c929b}
.schedule-table td.is-actual{background:#eef8f4;color:#135f50;font-weight:700}
.schedule-table .loan-completed,.schedule-table td.is-actual.loan-completed{background:#e5e8ea;color:#78848a}
.actual-mark{margin-right:4px;color:#2b9b82;font-size:8px;vertical-align:middle}
.payment-number{display:inline-block;min-width:30px;margin-right:5px;padding:1px 4px;border-radius:4px;background:#edf2f4;color:#53666e;font-size:10px;text-align:center}
.schedule-table td.is-actual .payment-number{background:#d9eee6;color:#266454}
.loan-no{color:var(--accent-dark);font-size:13px}.loan-edit-link{text-decoration:underline;text-decoration-color:#91aaa5;text-underline-offset:3px}.loan-edit-link:hover{color:#0e745f;text-decoration-color:currentColor}.completed-label,.calculated-label{display:block;margin-top:2px;color:#6d787e}.sort-link{display:inline-flex;gap:4px;align-items:center;color:var(--accent-dark);text-decoration:none}.sort-link:hover{text-decoration:underline}.schedule-note{margin-top:16px}.schedule-note p{margin-bottom:0;color:var(--muted)}
@media(max-width:700px){.loan-schedule-page{width:calc(100vw - 12px)}.schedule-wrap{max-height:calc(100vh - 210px)}}
</style>
@endsection
