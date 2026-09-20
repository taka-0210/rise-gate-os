@extends('layouts.app', ['title' => '事業領域の編集担当 - '.$organization->name])

@section('content')
<section class="stack">
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="panel">@foreach($errors->all() as $error)<div class="error">{{ $error }}</div>@endforeach</div>@endif
    <div class="page-header"><div class="meta">BUSINESS DOMAIN / EDITOR GRANTS</div><h1>編集担当</h1><p>Ownerは指定なしで管理できます。Admin / Memberへ必要な場合だけ、事業領域専用の編集権限を指定します。</p></div>
    <div class="notice">この指定からProject・Workspace・Financial・AI等の権限は発生しません。</div>
    <div class="stack">
        @forelse($memberships as $membership)
            @php($activeGrant = $membership->businessDomainEditorGrant && !$membership->businessDomainEditorGrant->revoked_at)
            <article class="card editor-row">
                <div><strong>{{ $membership->user?->name ?? 'Deleted user' }}</strong><div class="meta">{{ strtoupper($membership->organization_role) }} / {{ strtoupper($membership->membership_status) }}</div></div>
                <span class="badge">{{ $activeGrant ? '編集担当' : '閲覧のみ' }}</span>
                @if($activeGrant)
                    <form method="POST" action="{{ route('business-domains.editors.revoke', $membership) }}">@csrf @method('DELETE')<input type="hidden" name="request_id" value="{{ $editorRequestIds[$membership->id] }}"><button class="danger" type="submit">解除</button></form>
                @elseif($membership->membership_status === 'active' && $membership->user?->is_active)
                    <form method="POST" action="{{ route('business-domains.editors.grant', $membership) }}">@csrf<input type="hidden" name="request_id" value="{{ $editorRequestIds[$membership->id] }}"><button type="submit">指定</button></form>
                @else
                    <span class="meta">停止・終了中のため指定不可</span>
                @endif
            </article>
        @empty<div class="panel"><p>指定可能なAdmin / Memberはいません。</p></div>@endforelse
    </div>
    <div><a class="button secondary" href="{{ route('business-domains.index') }}">事業領域へ戻る</a></div>
</section>
<style>.editor-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:14px}@media(max-width:600px){.editor-row{grid-template-columns:1fr}.editor-row form button{width:100%}}</style>
@endsection
