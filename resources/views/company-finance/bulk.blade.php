@extends('layouts.app', ['title' => '年度P/Lを一括入力'])
@section('content')
<div class="page-header"><div><div class="meta"><a href="{{ route('company-finance.pl.index') }}">P/L</a> / BULK INPUT</div><h1>表を貼り付けて一括入力</h1><p>Excelやスプレッドシートから、10列をそのまま貼り付けられます。</p></div></div>
<div class="card">
<div class="bulk-columns" aria-label="貼り付ける列の順番">
@foreach(['期','年度','売上高','売上原価','販管費','営業外収益','営業外費用','特別利益','特別損失','法人税等'] as $label)
    <div><b>{{ $loop->iteration }}</b><span>{{ $label }}</span></div>
@endforeach
</div>
<form method="POST" action="{{ route('company-finance.pl.bulk.preview') }}">@csrf
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<textarea name="bulk_text" rows="12" style="width:100%;font-family:monospace" placeholder="21	2024	100000000	60000000	30000000	0	0	0	0	3000000">{{ old('bulk_text') }}</textarea>
<div class="actions"><button>内容を確認</button></div></form></div>
<style>
.bulk-columns{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:16px}.bulk-columns div{display:flex;align-items:center;gap:8px;padding:9px 10px;border:1px solid var(--line);border-radius:7px;background:#f7fafb}.bulk-columns b{display:grid;place-items:center;flex:0 0 24px;height:24px;border-radius:50%;color:#fff;background:var(--accent-dark);font-size:12px}.bulk-columns span{font-size:13px;font-weight:700}@media(max-width:700px){.bulk-columns{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
@endsection
