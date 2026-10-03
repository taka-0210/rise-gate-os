@extends('layouts.app', ['title' => $annualPolicy->period->display_label.' 年度経営方針 - '.$organization->name])

@section('content')
@include('annual-management-policy._styles')
<section class="amp-shell">
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    <header class="amp-reader-header"><p class="amp-kicker">ANNUAL MANAGEMENT POLICY</p><div class="amp-reader-header__title"><div><h1>{{ $annualPolicy->period->display_label }}</h1><p>{{ $annualPolicy->period->starts_on->format('Y/m/d') }} — {{ $annualPolicy->period->ends_on->format('Y/m/d') }} / JST</p></div><span class="amp-status amp-status--{{ $lifecycle['effective_status'] }}">{{ $lifecyclePresenter->label($lifecycle) }}</span></div></header>
    @if($approved)
        <article class="amp-reader" aria-label="正式な年度経営方針"><div class="amp-reader__revision"><span>正式Revision {{ $annualPolicy->currentApprovedRevision->revision_no }}</span><span>{{ $annualPolicy->currentApprovedRevision->approved_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST 承認</span></div>@include('annual-management-policy._snapshot', ['snapshot' => $approved])</article>
    @elseif($canDraft)
        <div class="amp-empty"><strong>正式Revisionはまだありません。</strong><p>作成中の案は権限を持つ担当者だけが編集・承認できます。</p></div>
    @else
        <div class="amp-empty">現在、閲覧できる正式Revisionはありません。</div>
    @endif
    @if($canDraft || $canEdit || $canApprove || $canManage)
        <aside class="amp-management-tools" aria-label="年度経営方針の管理"><div><p class="amp-kicker">MANAGEMENT</p><h2>作成・承認・管理</h2>@if($canDraft)<p>Draft version {{ $annualPolicy->draft_version }}。Draftは承認されるまで正式版・現在方針にはなりません。</p>@endif</div><div class="actions">@if($canEdit)<a class="button" href="{{ route('annual-management-policy.edit', $annualPolicy) }}">{{ $approved ? '改定案を編集' : '方針を作成' }}</a>@endif @if($canApprove)<a class="button secondary" href="{{ route('annual-management-policy.approval', $annualPolicy) }}">承認内容を確認</a>@endif @if($canManage)<a class="button secondary" href="{{ route('annual-management-policy.permissions', $annualPolicy) }}">共有・担当者</a>@endif @if($approved)<a class="button secondary" href="{{ route('annual-management-policy.history', $annualPolicy) }}">改定履歴</a>@endif @if($canEdit)<a class="button secondary" href="{{ route('annual-management-policy.relations', $annualPolicy) }}">関連付け</a>@endif</div></aside>
    @endif
    <div><a class="button secondary" href="{{ route('annual-management-policy.index') }}">年度一覧へ戻る</a></div>
</section>
@endsection
