@extends('layouts.app', ['title' => '年度経営方針の共有・担当者 - '.$organization->name])
@section('content')
@include('annual-management-policy._styles')
<section class="amp-shell"><header class="amp-header"><div><p class="amp-kicker">SHARING AND POLICY TEAM</p><h1>共有・担当者</h1><p>「正式版を共有する人」と「方針づくりに参加する担当者」を分けて指定します。内部Permission Contractは変わりません。</p></div><a class="button secondary" href="{{ route('annual-management-policy.show',$annualPolicy) }}">方針へ戻る</a></header>
 @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
 @if($errors->any())<div class="panel" role="alert">@foreach($errors->all() as $error)<div class="error">{{ $error }}</div>@endforeach</div>@endif
 <form method="POST" action="{{ route('annual-management-policy.permissions.update',$annualPolicy) }}" class="amp-form">@csrf @method('PUT')<input type="hidden" name="request_id" value="{{ $requestId }}">
  <fieldset><legend>1. 正式版を誰に共有するか</legend><p>承認済みの年度経営方針と改定履歴を読める範囲です。</p><label class="amp-check"><input type="radio" name="approved_view_scope" value="all_active_staff" @checked(old('approved_view_scope',$annualPolicy->approved_view_scope)==='all_active_staff')><span><strong>在籍中のスタッフ全員へ共有</strong><br><small>現在activeな全員が正式版と履歴を読めます。</small></span></label><label class="amp-check"><input type="radio" name="approved_view_scope" value="explicit" @checked(old('approved_view_scope',$annualPolicy->approved_view_scope)==='explicit')><span><strong>選んだ人へ共有</strong><br><small>下の「正式版を共有」にチェックした人だけが読めます。</small></span></label></fieldset>
  <fieldset><legend>2. 方針づくりの担当者</legend><p>作成中の案を見る、編集する、承認する役割を人ごとに指定します。</p><div class="amp-table-wrap"><table class="amp-table"><thead><tr><th>メンバー</th><th>正式版を共有</th><th>作成中の案を見る</th><th>作成・編集する</th><th>承認する</th></tr></thead><tbody>
   @foreach($memberships as $membership)@php($grant=$grants->get($membership->id))<tr><td><strong>{{ $membership->user->name }}</strong><br><span class="meta">{{ $membership->organization_role }} / {{ $membership->position ?? 'Positionなし' }}</span></td>@foreach(['can_view_approved','can_view_draft','can_edit','can_approve'] as $capability)<td><input aria-label="{{ $membership->user->name }} {{ $capability }}" type="checkbox" name="grants[{{ $membership->id }}][{{ $capability }}]" value="1" @checked(old("grants.{$membership->id}.{$capability}",$grant?->{$capability} ?? false))></td>@endforeach</tr>@endforeach
  </tbody></table></div></fieldset>
  <div class="amp-warning">編集・承認にはDraft閲覧が必要です。すでに正式版がある場合、編集・承認者には正式版の閲覧も必要です。不整合な設定は保存されません。</div>
  <div><button type="submit">共有・担当者を保存</button></div>
 </form>
</section>
@endsection
