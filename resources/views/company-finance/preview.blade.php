@extends('layouts.app', ['title' => '年度P/Lの保存前確認'])
@section('content')
<div class="page-header"><div><div class="meta">P/L / PREVIEW</div><h1>保存前確認</h1><p>計算結果を確認してから下書き保存します。</p></div></div>
<div class="card">@include('company-finance.partials.result-table', ['row'=>$calculated])</div>
<form method="POST" action="{{ $period ? route('company-finance.pl.update',$period) : route('company-finance.pl.store') }}" class="actions">@csrf @if($period)@method('PUT')@endif
@foreach($input as $name=>$value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
<button type="submit">下書き保存</button><button type="button" class="secondary" onclick="history.back()">入力へ戻る</button></form>
@endsection
