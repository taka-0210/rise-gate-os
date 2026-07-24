@extends('layouts.app', ['title' => '借入を一括入力'])
@section('content')
<div class="page-header"><div><div class="meta"><a href="{{ route('company-loans.index') }}">借入・資金計画</a> / BULK</div><h1>表を貼り付けて一括入力</h1><p>Excelから16列をコピーして貼り付けます。保存前に全件を確認できます。</p></div></div>
<div class="card">
<div class="bulk-columns" aria-label="貼り付ける列の順番">
@foreach(['金融機関','管理番号','用途','実行年月','期間','当初借入額','現在残高','月額元金','年利','金利区分','直近利息','完済年月','保証・区分','返済日','残高基準日','状態'] as $label)
    <div><b>{{ $loop->iteration }}</b><span>{{ $label }}</span></div>
@endforeach
</div>
<p class="meta">10 金利区分：fixed / variable / other　　16 状態：active / completed / planned</p>
<form method="POST" action="{{ route('company-loans.bulk.preview') }}">@csrf @if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<textarea name="bulk_text" rows="14" style="width:100%;font-family:monospace" placeholder="○○銀行	1	運転資金	2026-03	10年	30000000	29250000	250000	1.996	variable	0	2036-03	保証協付	25	2026-05-31	active">{{ old('bulk_text') }}</textarea><div class="actions"><button>内容を確認</button></div></form></div>
<style>
.bulk-columns{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:12px}.bulk-columns div{display:flex;align-items:center;gap:8px;padding:9px 10px;border:1px solid var(--line);border-radius:7px;background:#f7fafb}.bulk-columns b{display:grid;place-items:center;flex:0 0 24px;height:24px;border-radius:50%;color:#fff;background:var(--accent-dark);font-size:12px}.bulk-columns span{font-size:13px;font-weight:700}@media(max-width:700px){.bulk-columns{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
@endsection
