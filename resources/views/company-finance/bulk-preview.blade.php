@extends('layouts.app', ['title' => '一括入力の確認'])
@section('content')
<div class="page-header"><div><div class="meta">P/L / BULK PREVIEW</div><h1>{{ count($rows) }}期分を確認</h1><p>すべて下書きとして保存します。</p></div></div>
@foreach($rows as $row)<div class="card" style="margin-bottom:12px">@include('company-finance.partials.result-table',['row'=>$row])</div>@endforeach
<form method="POST" action="{{ route('company-finance.pl.bulk.store') }}">@csrf<textarea name="bulk_text" hidden>{{ $bulkText }}</textarea><button>一括で下書き保存</button></form>
@endsection
