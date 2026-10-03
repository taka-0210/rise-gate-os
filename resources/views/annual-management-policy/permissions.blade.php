@extends('layouts.app', ['title' => '年度経営方針の共有・担当者 - '.$organization->name])
@section('content')
@include('annual-management-policy._styles')
<section class="amp-shell"><header class="amp-header"><div><p class="amp-kicker">EXPLICIT ACCESS</p><h1>共有・担当者</h1><p>正式版の共有範囲と、Draftを見る人・編集する人・承認する人を分けて指定します。Owner、Group、Positionからの自動付与はありません。</p></div><a class="button secondary" href="{{ route('annual-management-policy.show',$annualPolicy) }}">方針へ戻る</a></header>
 @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
 @if($errors->any())<div class="panel" role="alert">@foreach($errors->all() as $error)<div class="error">{{ $error }}</div>@endforeach</div>@endif
 <form method="POST" action="{{ route('annual-management-policy.permissions.update',$annualPolicy) }}" class="amp-form">@csrf @method('PUT')<input type="hidden" name="request_id" value="{{ $requestId }}">
  <fieldset><legend>正式版・履歴の共有範囲</legend><label class="amp-check"><input type="radio" name="approved_view_scope" value="all_active_staff" @checked(old('approved_view_scope',$annualPolicy->approved_view_scope)==='all_active_staff')><span><strong>在籍中のスタッフ全員へ共有</strong><br><small>現在activeな全員が正式版と履歴を読めます。</small></span></label><label class="amp-check"><input type="radio" name="approved_view_scope" value="explicit" @checked(old('approved_view_scope',$annualPolicy->approved_view_scope)==='explicit')><span><strong>選んだ人へ共有</strong><br><small>下の「正式版・履歴を読む」にチェックした人だけが読めます。</small></span></label></fieldset>
  <div class="panel"><table class="amp-table"><thead><tr><th>Staff</th><th>正式版・履歴を読む</th><th>Draftを見る</th><th>編集</th><th>承認</th></tr></thead><tbody>
   @foreach($memberships as $membership)@php($grant=$grants->get($membership->id))<tr><td><strong>{{ $membership->user->name }}</strong><br><span class="meta">{{ $membership->organization_role }} / {{ $membership->position ?? 'Positionなし' }}</span></td>@foreach(['can_view_approved','can_view_draft','can_edit','can_approve'] as $capability)<td><input aria-label="{{ $membership->user->name }} {{ $capability }}" type="checkbox" name="grants[{{ $membership->id }}][{{ $capability }}]" value="1" @checked(old("grants.{$membership->id}.{$capability}",$grant?->{$capability} ?? false))></td>@endforeach</tr>@endforeach
  </tbody></table></div>
  <div class="amp-warning">編集・承認にはDraft閲覧が必要です。すでに正式版がある場合、編集・承認者には正式版の閲覧も必要です。不整合な設定は保存されません。</div>
  <div><button type="submit">共有・担当者を保存</button></div>
 </form>
</section>
@endsection
