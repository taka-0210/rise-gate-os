@extends('layouts.app', ['title' => $title.' - 経営数値'])
@section('content')
<div class="page-header"><div><div class="meta"><a href="{{ route('company-finance.index') }}">経営数値</a> / {{ $title }}</div><h1>{{ $title }}</h1><p>{{ $organization->name }}の数字として、P/Lと分離して管理します。</p></div></div>
<div class="card"><h2>入口を用意しました</h2><p>この領域の入力・集計機能は次の段階で実装します。</p></div>
@endsection
