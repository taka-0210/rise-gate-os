@extends('layouts.app', ['title' => '理念・Vision・方針の権限 - '.$organization->name])

@section('content')
@include('management-design._styles')
<section class="mdc-shell stack">
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="panel" role="alert">@foreach($errors->all() as $error)<div class="error">{{ $error }}</div>@endforeach</div>@endif
    <header class="mdc-form__intro"><div><p class="mdc-kicker">MANAGEMENT DESIGN / PERMISSION</p><h1>閲覧・編集権限</h1><p>Ownerはこの権限を管理しますが、本文を自動的に閲覧・編集できません。必要な権限をOwner自身にも明示してください。</p></div><a class="button secondary" href="{{ route('management-design.index') }}">読む入口へ</a></header>
    <div class="notice">Organization Role・Group・Position、Business Domain、Project、AIの権限はここから派生しません。停止・退職・無効Userは現在権限を失います。</div>
    <div class="mdc-permissions">
        @foreach(\App\Models\ManagementDesignItem::TYPES as $type)
            @php
                $setting = $settings->get($type);
                $typeGrants = $grants->get($type, collect());
            @endphp
            <form class="mdc-permission-type stack" method="POST" action="{{ route('management-design.permissions.update', $type) }}" data-permission-form>
                @csrf @method('PUT')
                <input type="hidden" name="request_id" value="{{ $requestIds[$type] }}">
                <div><p class="mdc-kicker">{{ strtoupper($type) }}</p><h2>{{ \App\Models\ManagementDesignItem::label($type) }}</h2></div>
                <div class="field"><label for="scope-{{ $type }}">閲覧範囲</label><select id="scope-{{ $type }}" name="view_scope"><option value="explicit" @selected(($setting?->view_scope ?? 'explicit') === 'explicit')>明示したviewerのみ</option><option value="all_active_staff" @selected($setting?->view_scope === 'all_active_staff')>すべてのactive Staff</option></select></div>
                <div>
                    <div class="mdc-permission-row"><strong>所属ユーザー</strong><strong>明示View</strong><strong>Edit</strong></div>
                    @foreach($memberships as $membership)
                        @php($grant = $typeGrants->get($membership->id))
                        <div class="mdc-permission-row">
                            <div><strong>{{ $membership->user?->name ?? 'Deleted user' }}</strong><div class="meta">{{ strtoupper($membership->organization_role) }} / ACTIVE</div></div>
                            <label class="mdc-check"><input type="hidden" name="grants[{{ $membership->id }}][can_view]" value="0"><input type="checkbox" name="grants[{{ $membership->id }}][can_view]" value="1" @checked($grant?->can_view)> View</label>
                            <label class="mdc-check"><input type="hidden" name="grants[{{ $membership->id }}][can_edit]" value="0"><input type="checkbox" name="grants[{{ $membership->id }}][can_edit]" value="1" @checked($grant?->can_edit) data-edit-grant> Edit</label>
                        </div>
                    @endforeach
                </div>
                <div><button type="submit">{{ \App\Models\ManagementDesignItem::label($type) }}の権限を保存</button></div>
            </form>
        @endforeach
    </div>
</section>
<script>
document.querySelectorAll('[data-permission-form]').forEach(form => {
    form.addEventListener('change', event => {
        if (!event.target.matches('[data-edit-grant]') || !event.target.checked) return;
        event.target.closest('.mdc-permission-row').querySelector('input[type=checkbox][name$="[can_view]"]').checked = true;
    });
});
</script>
@endsection
