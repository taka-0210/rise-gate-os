@extends('layouts.app', ['title' => '年度P/Lを一括入力'])
@section('content')
<div class="page-header"><div><div class="meta"><a href="{{ route('company-finance.pl.index') }}">P/L</a> / BULK INPUT</div><h1>表を貼り付けて一括入力</h1><p>Excelやスプレッドシートから、10列をそのまま貼り付けられます。</p></div></div>
<div class="card"><p><strong>列順：</strong>期、年度、売上高、売上原価、販管費、営業外収益、営業外費用、特別利益、特別損失、法人税等</p>
<form method="POST" action="{{ route('company-finance.pl.bulk.preview') }}">@csrf
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<textarea name="bulk_text" rows="12" style="width:100%;font-family:monospace" placeholder="21	2024	100000000	60000000	30000000	0	0	0	0	3000000">{{ old('bulk_text') }}</textarea>
<div class="actions"><button>内容を確認</button></div></form></div>
@endsection
