@extends('layouts.app', ['title' => '借入・資金計画 - COMPANY OS'])
@section('content')
<div class="loan-page">
    <div class="page-header">
        <div><div class="meta">COMPANY OS / DEBT & FUNDING</div><h1>借入・資金計画</h1><p>借入残高、毎月の返済、将来の残高を会社の正式情報として管理します。</p></div>
        <div class="actions"><a class="button secondary" href="{{ route('company.home') }}">← 会社ホームへ</a>@if($canManage)<a class="button" href="{{ route('company-loans.create') }}">借入を登録</a><a class="button secondary" href="{{ route('company-loans.bulk') }}">表を貼り付けて一括入力</a>@endif</div>
    </div>
    @if(session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif

    <div class="loan-stats">
        <div class="card"><span>借入残高</span><strong>{{ number_format($totalBalance) }}</strong><small>円</small></div>
        <div class="card"><span>月額元金返済</span><strong>{{ number_format($monthlyPrincipal) }}</strong><small>円／月</small></div>
        <div class="card"><span>直近利息</span><strong>{{ number_format($recentInterest) }}</strong><small>円</small></div>
        <div class="card"><span>返済負担</span><strong>{{ number_format($monthlyPrincipal + $recentInterest) }}</strong><small>元金＋直近利息</small></div>
        <div class="card"><span>加重平均金利</span><strong>{{ $weightedRate === null ? '—' : number_format($weightedRate,2).'%' }}</strong><small>残高による加重平均</small></div>
    </div>

    @if($loans->isEmpty())
        <div class="card"><h2>借入情報はまだありません</h2><p>1件ずつ登録するか、Excelの表を貼り付けて一括入力できます。</p></div>
    @else
        <div class="loan-grid">
            <section class="card">
                <div class="meta">BY FINANCIAL INSTITUTION</div><h2>金融機関別残高</h2>
                <table class="compact-table"><thead><tr><th>金融機関</th><th>件数</th><th>残高</th><th>月額元金</th></tr></thead><tbody>
                @foreach($byInstitution as $name=>$summary)<tr><td>{{ $name }}</td><td>{{ $summary['count'] }}</td><td>{{ number_format($summary['balance']) }}</td><td>{{ number_format($summary['monthly']) }}</td></tr>@endforeach
                </tbody></table>
            </section>
            <section class="card">
                <div class="meta">5-YEAR OUTLOOK</div><h2>借入残高の見通し</h2>
                @php
                    $points = collect($projection)->filter(fn($item,$i)=>$i%12===0 || $i===count($projection)-1)->values();
                    $max = max(1,(int)$points->max('balance')); $w=760; $h=210; $l=55; $r=20; $t=20; $b=35;
                    $coords=$points->map(fn($item,$i)=>[
                        'x'=>$l+($points->count()===1?0:$i/($points->count()-1)*($w-$l-$r)),
                        'y'=>$t+(1-$item['balance']/$max)*($h-$t-$b),
                        'item'=>$item,
                    ]);
                @endphp
                <svg class="loan-chart" viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="5年間の借入残高見通し">
                    @foreach([0,.5,1] as $step)<line x1="{{ $l }}" y1="{{ $t+$step*($h-$t-$b) }}" x2="{{ $w-$r }}" y2="{{ $t+$step*($h-$t-$b) }}" stroke="#dfe6e9"/><text x="{{ $l-8 }}" y="{{ $t+$step*($h-$t-$b)+4 }}" text-anchor="end">{{ number_format(($max*(1-$step))/100000000,1) }}億</text>@endforeach
                    <polyline points="{{ $coords->map(fn($p)=>$p['x'].','.$p['y'])->join(' ') }}" fill="none" stroke="#165d6c" stroke-width="4"/>
                    @foreach($coords as $point)<circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="5" fill="#165d6c"><title>{{ $point['item']['date']->format('Y年m月') }}：{{ number_format($point['item']['balance']) }}円</title></circle><text x="{{ $point['x'] }}" y="{{ $h-10 }}" text-anchor="middle">{{ $point['item']['date']->format('Y') }}</text>@endforeach
                </svg>
                <p class="meta">月額元金返済と完済予定からの概算です。当座貸越・短期継続融資は残高を維持して表示します。</p>
            </section>
        </div>

        <form class="card loan-table-wrap" method="POST" action="{{ route('company-loans.confirm-drafts') }}">
            @csrf
            <div class="section-heading"><div><h2>借入一覧</h2><p class="meta">金融機関名・No.をクリックすると編集できます。完済済みも会社の借入履歴として残します。</p></div><div class="actions"><span class="badge">{{ $loans->count() }}件</span>@if($canManage && $loans->contains('record_status','draft'))<button type="submit" name="scope" value="selected" class="secondary">選択した下書きを確定</button><button type="submit" name="scope" value="all" onclick="return confirm('借入の下書きをすべて確定しますか？')">下書きをすべて確定</button>@endif</div></div>
            <table class="loan-table"><thead><tr>@if($canManage && $loans->contains('record_status','draft'))<th><input type="checkbox" data-check-all aria-label="下書きをすべて選択"></th>@endif<th>金融機関／No.</th><th>状態</th><th>用途</th><th>実行</th><th>当初借入額</th><th>現在残高</th><th>月額元金</th><th>金利</th><th>直近利息</th><th>完済予定</th><th>基準日</th></tr></thead><tbody>
            @foreach($loans as $loan)<tr class="{{ $loan->loan_status==='completed'?'is-completed':'' }}">
                @if($canManage && $loans->contains('record_status','draft'))<td>@if($loan->record_status==='draft')<input type="checkbox" name="ids[]" value="{{ $loan->id }}" data-check-item aria-label="{{ $loan->financial_institution }} No.{{ $loan->management_number }}を選択">@endif</td>@endif
                <td>@if($canManage)<a href="{{ route('company-loans.edit',$loan) }}"><strong>{{ $loan->financial_institution }}</strong><br>No.{{ $loan->management_number }}</a>@else<strong>{{ $loan->financial_institution }}</strong><br>No.{{ $loan->management_number }}@endif</td>
                <td><span class="status status--{{ $loan->loan_status }}">{{ ['active'=>'借入中','completed'=>'完済','planned'=>'実行予定'][$loan->loan_status] }}</span><br><small>{{ $loan->record_status==='confirmed'?'確定':'下書き' }}</small></td>
                <td>{{ $loan->purpose ?: '—' }}<br><small>{{ $loan->guarantee_type }}</small></td>
                <td>{{ $loan->executed_on?->format('Y.m') ?: '—' }}</td><td>{{ number_format($loan->original_amount) }}</td><td><strong>{{ number_format($loan->current_balance) }}</strong></td><td>{{ number_format($loan->monthly_principal_payment) }}</td>
                <td>{{ $loan->annual_interest_rate===null?'—':number_format((float)$loan->annual_interest_rate,3).'%' }}<br><small>{{ ['fixed'=>'固定','variable'=>'変動','other'=>'その他'][$loan->interest_type] ?? '' }}</small></td>
                <td>{{ number_format($loan->recent_interest_amount) }}</td><td>{{ $loan->maturity_on?->format('Y.m') ?: '—' }}</td><td>{{ $loan->balance_as_of?->format('Y.m.d') ?: '—' }}</td>
            </tr>@endforeach</tbody></table>
        </form>
    @endif
</div>
<style>
.loan-page{width:min(1580px,calc(100vw - 32px));position:relative;left:50%;transform:translateX(-50%)}.loan-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:16px}.loan-stats .card{display:flex;flex-direction:column;gap:5px}.loan-stats span,.loan-stats small{color:var(--muted)}.loan-stats strong{font-size:22px;color:var(--accent-dark)}.loan-grid{display:grid;grid-template-columns:.9fr 1.1fr;gap:16px;margin-bottom:16px}.compact-table,.loan-table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}.compact-table th,.compact-table td,.loan-table th,.loan-table td{padding:9px 8px;border-bottom:1px solid var(--line);text-align:right;white-space:nowrap}.compact-table th:first-child,.compact-table td:first-child,.loan-table th:first-child,.loan-table td:first-child,.loan-table th:nth-child(3),.loan-table td:nth-child(3){text-align:left}.loan-chart{display:block;width:100%;height:auto;margin-top:12px}.loan-chart text{fill:#667983;font-size:12px}.loan-table-wrap{overflow-x:auto}.loan-table{min-width:1200px;font-size:13px}.section-heading{display:flex;justify-content:space-between;gap:20px;margin-bottom:12px}.status{display:inline-flex;padding:4px 7px;border-radius:999px;font-size:11px;font-weight:700}.status--active{background:#e5f4ef;color:#176653}.status--planned{background:#fff1d8;color:#8a5c12}.status--completed{background:#edf0f2;color:#64747d}.is-completed{opacity:.65}@media(max-width:1000px){.loan-stats{grid-template-columns:repeat(2,1fr)}.loan-grid{grid-template-columns:1fr}}@media(max-width:600px){.loan-page{width:calc(100vw - 20px)}}
</style>
<script>
document.querySelectorAll('[data-check-all]').forEach((toggle)=>{
    toggle.addEventListener('change',()=>{
        toggle.closest('form').querySelectorAll('[data-check-item]').forEach((item)=>item.checked=toggle.checked);
    });
});
</script>
@endsection
