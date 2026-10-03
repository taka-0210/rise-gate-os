@extends('layouts.app', ['title' => $annualPolicy->period->name.' 年度経営方針 - '.$organization->name])

@section('content')
@include('annual-management-policy._styles')
<section class="amp-shell">
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    <header class="amp-header"><div><p class="amp-kicker">ANNUAL MANAGEMENT POLICY</p><h1>{{ $annualPolicy->period->name }}</h1><p>{{ $annualPolicy->period->starts_on->format('Y/m/d') }} – {{ $annualPolicy->period->ends_on->format('Y/m/d') }}</p></div>
        <div class="actions">@if($canEdit)<a class="button" href="{{ route('annual-management-policy.edit', $annualPolicy) }}">作成中の方針を編集</a>@endif @if($canManage)<a class="button secondary" href="{{ route('annual-management-policy.permissions', $annualPolicy) }}">共有・担当者</a>@endif</div>
    </header>
    @if($canDraft)
        <div class="amp-warning"><strong>作成中の内容があります。</strong> 保存だけでは正式版になりません。現在のDraft versionは {{ $annualPolicy->draft_version }} です。@if($canApprove)<a href="{{ route('annual-management-policy.approval', $annualPolicy) }}">承認内容を確認</a>@endif</div>
    @endif
    @if($approved)
        <div class="actions" style="justify-content:space-between"><div><span class="amp-status">正式版 Revision {{ $annualPolicy->currentApprovedRevision->revision_no }}</span><span class="meta"> {{ $annualPolicy->currentApprovedRevision->approved_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST</span></div><div class="actions"><a href="{{ route('annual-management-policy.history', $annualPolicy) }}">改定履歴</a>@if($canEdit)<a href="{{ route('annual-management-policy.relations', $annualPolicy) }}">関連付け</a>@endif</div></div>
        @include('annual-management-policy._snapshot', ['snapshot' => $approved])
    @elseif($canDraft)
        <div class="amp-empty">正式版はまだ承認されていません。Draftは権限を持つ人だけが確認できます。</div>
    @else
        <div class="amp-empty">現在、閲覧できる正式版はありません。</div>
    @endif
    <div><a class="button secondary" href="{{ route('annual-management-policy.index') }}">年度一覧へ戻る</a></div>
</section>
@endsection
