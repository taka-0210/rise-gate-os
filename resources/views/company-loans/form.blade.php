@extends('layouts.app', ['title' => ($loan?'借入契約を編集':'借入を登録').' - COMPANY OS'])
@section('content')
<div class="page-header"><div><div class="meta"><a href="{{ route('company-loans.index') }}">借入・資金計画</a> / INPUT</div><h1>{{ $loan ? $loan->financial_institution.' No.'.$loan->management_number : '借入を登録' }}</h1><p>残高には必ず基準日を付け、いつ時点の数字かを明確にします。</p></div></div>
@if(session('status'))<div class="alert success">{{ session('status') }}</div>@endif
@if($loan)<div class="card actions" style="margin-bottom:16px"><strong>{{ $loan->record_status==='confirmed'?'確定済み':'下書き' }}</strong><span class="meta">変更履歴 {{ $loan->revisions()->count() }}件</span>@if($loan->record_status!=='confirmed')<form method="POST" action="{{ route('company-loans.confirm',$loan) }}">@csrf<button>この内容を確定</button></form>@endif</div>@endif
<form class="card" method="POST" action="{{ route('company-loans.preview') }}">@csrf @if($loan)<input type="hidden" name="loan_id" value="{{ $loan->id }}">@endif
@include('company-loans.partials.fields',['values'=>$loan])
<div class="actions" style="margin-top:18px"><button>保存前に確認</button><a class="button secondary" href="{{ route('company-loans.index') }}">戻る</a></div></form>
@if($loan)
<section class="card balance-history">
    <div>
        <h2>残高実績履歴</h2>
        <p class="meta">緑の「●」として残高推移表に表示される実績です。誤登録した実績だけを削除できます。</p>
    </div>
    @error('balance_snapshot')<div class="alert error">{{ $message }}</div>@enderror
    <div class="balance-history-list">
        @foreach($loan->balanceSnapshots as $snapshot)
        <div class="balance-history-row">
            <div><span>基準日</span><strong>{{ $snapshot->balance_as_of->format('Y/m/d') }}</strong></div>
            <div><span>残高</span><strong>{{ number_format($snapshot->balance) }}円</strong></div>
            <form method="POST" action="{{ route('company-loans.balance-snapshots.destroy', [$loan, $snapshot]) }}" onsubmit="return confirm('{{ $snapshot->balance_as_of->format('Y/m/d') }}時点の残高実績（{{ number_format($snapshot->balance) }}円）を削除しますか？')">
                @csrf
                @method('DELETE')
                <button class="danger" type="submit" @disabled($loan->balanceSnapshots->count() <= 1)>実績を削除</button>
            </form>
        </div>
        @endforeach
    </div>
    @if($loan->balanceSnapshots->count() <= 1)<p class="meta">最後の1件は削除できません。上の「基準日時点の残高」で日付・金額を修正してください。</p>@endif
</section>
<style>
.balance-history{margin-top:16px;display:grid;gap:14px}.balance-history h2{margin:0 0 4px}.balance-history-list{display:grid;gap:8px}.balance-history-row{display:grid;grid-template-columns:minmax(140px,1fr) minmax(180px,1fr) auto;gap:12px;align-items:center;padding:12px;border:1px solid var(--line);border-radius:8px}.balance-history-row>div{display:grid;gap:3px}.balance-history-row span{font-size:12px;color:var(--muted)}.balance-history-row form{margin:0}.balance-history-row button:disabled{opacity:.45;cursor:not-allowed}@media(max-width:700px){.balance-history-row{grid-template-columns:1fr 1fr}.balance-history-row form{grid-column:1/-1}.balance-history-row button{width:100%}}
</style>
@endif
@endsection
