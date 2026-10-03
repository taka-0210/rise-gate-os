@extends('layouts.app', ['title' => '年度経営方針の改定履歴 - '.$organization->name])
@section('content')
@include('annual-management-policy._styles')
<section class="amp-shell"><header class="amp-header"><div><p class="amp-kicker">IMMUTABLE HISTORY</p><h1>{{ $annualPolicy->period->display_label }} の改定履歴</h1><p>正式承認された全体Snapshotを、Revisionごとに確認できます。</p></div><a class="button secondary" href="{{ route('annual-management-policy.show',$annualPolicy) }}">現在の正式版へ</a></header>
 <div class="amp-periods">@forelse($revisions as $revision)<a class="amp-period" href="{{ route('annual-management-policy.revisions.show',[$annualPolicy,$revision->revision_no]) }}"><span><strong>Revision {{ $revision->revision_no }}</strong><br><span class="meta">{{ $revision->approved_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST / {{ $revision->approver?->name ?? '退職済みユーザー' }}</span>@if($revision->change_reason)<br><span>{{ $revision->change_reason }}</span>@endif</span><span aria-hidden="true">→</span></a>@empty<div class="amp-empty">正式版はまだありません。</div>@endforelse</div>
 {{ $revisions->links() }}
</section>
@endsection
