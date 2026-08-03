@extends('layouts.app', ['title' => ($period ? '年度P/Lを編集' : '年度P/Lを入力').' - Company OS'])
@section('content')
<div class="page-header"><div><div class="meta"><a href="{{ route('company-finance.pl.index') }}">P/L</a> / ACTUAL INPUT</div><h1>{{ $period ? $period->period_number.'期を編集' : '年度P/L実績を入力' }}</h1><p>P/Lは終了した事業年度の実績専用です。計画・最新見込は「03 今年度計画と進捗」で入力します。</p></div></div>
@if(session('status'))<div class="alert success">{{ session('status') }}</div>@endif
@if($period)<div class="card record-state"><strong>{{ $period->record_status === 'confirmed' ? '確定済み' : '下書き' }}</strong><span>変更履歴 {{ $period->revisions()->count() }}件</span>@if($period->record_status !== 'confirmed')<form method="POST" action="{{ route('company-finance.pl.confirm',$period) }}">@csrf<button>この内容を確定</button></form>@endif</div>@endif
<form class="card finance-form" method="POST" action="{{ route('company-finance.pl.preview') }}">@csrf
@if($period)<input type="hidden" name="period_id" value="{{ $period->id }}">@endif
@include('company-finance.partials.input-fields', ['values' => $period ?? null])
<div class="actions"><button type="submit">計算結果を確認</button><a class="button secondary" href="{{ route('company-finance.pl.index') }}">戻る</a></div>
</form>
@endsection
