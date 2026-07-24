@extends('layouts.app', ['title' => '借入契約の保存前確認'])
@section('content')
<div class="page-header"><div><div class="meta">DEBT / PREVIEW</div><h1>保存前確認</h1><p>金額、金利、残高基準日を確認してください。</p></div></div>
<div class="card">
<table style="width:100%;border-collapse:collapse">@foreach(['financial_institution'=>'金融機関','management_number'=>'管理番号','purpose'=>'用途','executed_on'=>'実行日','original_amount'=>'当初借入額','current_balance'=>'現在残高','monthly_principal_payment'=>'月額元金','annual_interest_rate'=>'年利','recent_interest_amount'=>'直近利息','maturity_on'=>'完済予定','completed_on'=>'完済日（実績）','balance_as_of'=>'残高基準日','loan_status'=>'状態'] as $key=>$label)<tr><th style="text-align:left;padding:9px;border-bottom:1px solid var(--line)">{{ $label }}</th><td style="text-align:right;padding:9px;border-bottom:1px solid var(--line)">@if(in_array($key,['original_amount','current_balance','monthly_principal_payment','recent_interest_amount'])){{ number_format($input[$key]??0) }}円 @elseif($key==='annual_interest_rate'){{ $input[$key]??'—' }}% @else{{ $input[$key]??'—' }}@endif</td></tr>@endforeach</table>
</div>
<form method="POST" action="{{ $loan?route('company-loans.save',$loan):route('company-loans.store') }}" class="actions">@csrf @foreach($input as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach<button>下書き保存</button><button type="button" class="secondary" onclick="history.back()">入力へ戻る</button></form>
@endsection
