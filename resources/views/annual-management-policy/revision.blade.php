@extends('layouts.app', ['title' => '年度経営方針 Revision '.$revision->revision_no.' - '.$organization->name])
@section('content')
@include('annual-management-policy._styles')
<section class="amp-shell"><header class="amp-header"><div><p class="amp-kicker">APPROVED REVISION</p><h1>Revision {{ $revision->revision_no }}</h1><p>{{ $revision->approved_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST に正式承認された変更不能な全体Snapshotです。</p></div><div class="actions"><a class="button secondary" href="{{ route('annual-management-policy.history',$annualPolicy) }}">履歴へ</a><a class="button secondary" href="{{ route('annual-management-policy.source-evidence',[$annualPolicy,'revision'=>$revision->revision_no]) }}">Source / Citation確認</a></div></header>
 @if($revision->change_reason)<div class="panel"><strong>変更理由</strong><p>{{ $revision->change_reason }}</p></div>@endif
 @include('annual-management-policy._snapshot',['snapshot'=>$revision->snapshot])
 <div class="meta amp-code">Snapshot SHA-256: {{ $revision->snapshot_hash }}</div>
</section>
@endsection
