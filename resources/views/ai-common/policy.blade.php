@extends('layouts.app')

@section('title', 'Organization AI Policy')

@section('content')
<div style="max-width:780px;margin:auto">
<h1>Organization AI Policy</h1>
<p>AI参照許可はData閲覧権を増やしません。Organization・Workspace・Resource・現在権限のすべてが必要です。</p>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif
<form class="card" method="post" action="{{ route('ai-common.policy.update') }}">@csrf @method('PUT')
    <input type="hidden" name="expected_version" value="{{ $policy?->version }}">
    <input type="hidden" name="is_enabled" value="0"><label><input type="checkbox" name="is_enabled" value="1" @checked($policy?->is_enabled)> Organization AIを有効にする</label>
    <input type="hidden" name="allows_transcription" value="0"><label><input type="checkbox" name="allows_transcription" value="1" @checked($policy?->allows_transcription)> Voice文字起こしを許可（default OFF）</label>
    @foreach(['common_entry'=>'共通入口','project'=>'Project','action'=>'Action','business_domain'=>'Business Domain','capture'=>'Capture','attachment'=>'Attachment / Transcript','company_management'=>'会社の経営指針（理念・Vision・会社方針・承認済み年度／部署方針）'] as $value=>$label)
        <label style="display:block"><input type="checkbox" name="allowed_categories[]" value="{{ $value }}" @checked(in_array($value,$policy?->allowed_categories ?? [],true))> {{ $label }}</label>
    @endforeach
    <button type="submit">Policyを確認して保存</button>
</form>
<section class="card"><h2>会議準備：経営指針のAI参照許可</h2>
<p>閲覧許可とは別の設定です。「共通入口」と「会社の経営指針」、下記の文書許可がすべて必要です。初期値はOFFです。部署方針は所属年度の許可を使います。共有会話では参加者全員の閲覧権限を毎回確認します。</p>
<p>Organization経営指針許可：{{ $policy?->allows('company_management') ? 'ON' : 'OFF' }} ／ 共通入口：{{ $policy?->allows('common_entry') ? 'ON' : 'OFF' }}</p>
@forelse(collect($managementDocuments)->where('type','!=','department_policy') as $document)
<form method="post" action="{{ route('ai-common.resource-policy.update') }}">@csrf @method('PUT')
<p>{{ $document['label'] }} ／ {{ $document['state_label'] }} ／ AI参照：{{ $document['allows_ai_reference'] ? 'ON' : 'OFF' }}</p>
<input type="hidden" name="resource_type" value="{{ $document['policy_type'] }}"><input type="hidden" name="resource_public_id" value="{{ $document['policy_id'] }}">
<input type="hidden" name="allows_ai_reference" value="0"><label><input type="checkbox" name="allows_ai_reference" value="1" @checked($document['allows_ai_reference'])> この文書のAI参照を許可する</label><button type="submit">文書許可を保存</button>
</form>
@empty<p>現在閲覧できる正式文書がありません。Ownerにも閲覧権限が必要です。</p>@endforelse
</section>
<form class="card" method="post" action="{{ route('ai-common.resource-policy.update') }}">@csrf @method('PUT')
    <h2>Resource AI Reference Policy</h2>
    <label>Type<select name="resource_type"><option value="project">Project</option><option value="action">Action</option><option value="business_domain">Business Domain</option><option value="capture">Capture</option></select></label>
    <label>Resource public ID<input name="resource_public_id" required></label>
    <input type="hidden" name="allows_ai_reference" value="0"><label><input type="checkbox" name="allows_ai_reference" value="1"> AI参照を許可</label>
    <button type="submit">Resource Policyを保存</button>
</form>
<section class="card"><h2>現在のResource Policy</h2>@forelse($resourcePolicies as $item)<p><code>{{ $item->resource_type }} / {{ $item->resource_public_id }}</code>：{{ $item->allows_ai_reference ? 'ON' : 'OFF' }}</p>@empty<p>未設定（OFF）</p>@endforelse</section>
</div>
@endsection
