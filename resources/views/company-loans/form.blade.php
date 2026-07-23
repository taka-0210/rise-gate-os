@extends('layouts.app', ['title' => ($loan?'借入契約を編集':'借入を登録').' - COMPANY OS'])
@section('content')
<div class="page-header"><div><div class="meta"><a href="{{ route('company-loans.index') }}">借入・資金計画</a> / INPUT</div><h1>{{ $loan ? $loan->financial_institution.' No.'.$loan->management_number : '借入を登録' }}</h1><p>残高には必ず基準日を付け、いつ時点の数字かを明確にします。</p></div></div>
@if(session('status'))<div class="alert success">{{ session('status') }}</div>@endif
@if($loan)<div class="card actions" style="margin-bottom:16px"><strong>{{ $loan->record_status==='confirmed'?'確定済み':'下書き' }}</strong><span class="meta">変更履歴 {{ $loan->revisions()->count() }}件</span>@if($loan->record_status!=='confirmed')<form method="POST" action="{{ route('company-loans.confirm',$loan) }}">@csrf<button>この内容を確定</button></form>@endif</div>@endif
<form class="card" method="POST" action="{{ route('company-loans.preview') }}">@csrf @if($loan)<input type="hidden" name="loan_id" value="{{ $loan->id }}">@endif
@include('company-loans.partials.fields',['values'=>$loan])
<div class="actions" style="margin-top:18px"><button>保存前に確認</button><a class="button secondary" href="{{ route('company-loans.index') }}">戻る</a></div></form>
@endsection
