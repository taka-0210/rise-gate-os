@extends('layouts.app', ['title' => '年度経営方針の承認確認 - '.$organization->name])

@section('content')
@include('annual-management-policy._styles')
@php
 $snapshot=$preview['snapshot']; $period=data_get($snapshot,'annual.period',[]);
 $periodDiff=($period['declared_name']??null)!==($period['organization_name']??null)||($period['declared_starts_on']??null)!==($period['organization_starts_on']??null)||($period['declared_ends_on']??null)!==($period['organization_ends_on']??null);
@endphp
<section class="amp-shell">
 <header class="amp-header"><div><p class="amp-kicker">HUMAN APPROVAL / FULL SNAPSHOT</p><h1>正式に承認する全体内容</h1><p>以下の全体が1つの変更不能なRevisionになります。保存済みDraftを正式版へ切り替える操作です。</p></div><a class="button secondary" href="{{ route('annual-management-policy.show',$annualPolicy) }}">戻る</a></header>
 @if($errors->any())<div class="panel" role="alert"><strong>承認できませんでした。</strong>@foreach($errors->all() as $error)<div class="error">{{ $error }}</div>@endforeach</div>@endif
 <div class="amp-review">@include('annual-management-policy._snapshot',['snapshot'=>$snapshot])</div>
 <div class="panel stack"><h2>共有範囲と担当者</h2><p>{{ $annualPolicy->approved_view_scope === \App\Models\AnnualManagementPolicy::VIEW_SCOPE_ALL_ACTIVE_STAFF ? '在籍中のスタッフ全員' : '選んだ閲覧者だけ' }}</p><p class="meta">選んだ正式版閲覧者 {{ data_get($snapshot,'annual.access_binding.explicit_approved_viewer_count',0) }}人 / Draft閲覧 {{ data_get($snapshot,'annual.access_binding.draft_viewer_count',0) }}人 / 編集 {{ data_get($snapshot,'annual.access_binding.editor_count',0) }}人 / 承認 {{ data_get($snapshot,'annual.access_binding.approver_count',0) }}人</p><small>Draft閲覧、編集、承認の権限は自動では付与されません。この確認後に共有・担当設定が変わった場合、承認は停止します。</small></div>
 @if($periodDiff)<div class="amp-warning"><strong>会社の登録期間と、方針に表示する期間が異なります。</strong> 「今期」の判定は会社の登録期間だけで行われます。</div>@endif
 <form method="POST" action="{{ route('annual-management-policy.approve',$annualPolicy) }}" class="amp-form">@csrf
  <input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="expected_draft_version" value="{{ $annualPolicy->draft_version }}"><input type="hidden" name="expected_base_revision_no" value="{{ $annualPolicy->currentApprovedRevision?->revision_no }}"><input type="hidden" name="expected_relation_version" value="{{ $annualPolicy->relation_version }}"><input type="hidden" name="expected_snapshot_hash" value="{{ $preview['snapshot_hash'] }}">
  @if($periodDiff)<label class="amp-check"><input type="checkbox" name="period_difference_confirmed" value="1" required><span>会社の期間との差を確認しました。</span></label>@endif
  <div class="field"><label for="change-reason">変更理由（任意）</label><textarea id="change-reason" name="change_reason" rows="3" maxlength="2000">{{ old('change_reason') }}</textarea></div>
  <label class="amp-check"><input type="checkbox" name="confirm" value="1" required><span>表示された全体と共有範囲を確認し、正式版として承認します。</span></label>
  <div><button type="submit">正式版として承認</button></div>
 </form>
</section>
@endsection
