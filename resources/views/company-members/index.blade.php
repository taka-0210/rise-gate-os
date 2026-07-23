@extends('layouts.app', ['title' => '会社ユーザー・権限 - COMPANY OS'])

@section('content')
    <div class="page-header">
        <div>
            <div class="meta">COMPANY OS / COMPANY ACCOUNT</div>
            <h1>会社ユーザー・権限</h1>
            <p>{{ $organization->name }}に所属するユーザーごとに、経営情報へのアクセス範囲を設定します。</p>
        </div>
    </div>

    @if (session('success'))<div class="success">{{ session('success') }}</div>@endif

    <div class="stack">
        @foreach ($memberships as $membership)
            <form class="card member-access" method="POST" action="{{ route('company-members.update', $membership->user) }}">
                @csrf
                @method('PUT')
                <div class="member-access__identity">
                    <div>
                        <div class="meta">ユーザーアカウント</div>
                        <h2>{{ $membership->user->name }}</h2>
                        <p>{{ $membership->user->email }}</p>
                    </div>
                    <span class="badge">所属権限: {{ $membership->role }}</span>
                </div>
                <div>
                    <label for="company-role-{{ $membership->id }}">会社内の役割</label>
                    <select id="company-role-{{ $membership->id }}" name="company_role">
                        @foreach (\App\Models\OrganizationUser::companyRoles() as $value => $label)
                            <option value="{{ $value }}" @selected(($membership->company_role ?? \App\Models\OrganizationUser::defaultCompanyRole($membership->role)) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <fieldset class="permission-grid">
                    <legend>個別に許可する操作</legend>
                    @foreach (\App\Models\OrganizationUser::permissionLabels() as $value => $label)
                        <label>
                            <input type="checkbox" name="permissions[]" value="{{ $value }}" @checked(in_array($value, $membership->permissions ?? [], true))>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </fieldset>
                @if ($membership->role === \App\Models\OrganizationUser::ROLE_OWNER)
                    <p class="meta">会社Ownerは、事故防止のためすべての会社権限を持ちます。</p>
                @elseif ($membership->role === \App\Models\OrganizationUser::ROLE_ADMIN)
                    <p class="meta">会社Adminは、会社ユーザー管理・P/L閲覧・取込を標準で許可します。</p>
                @endif
                <div><button type="submit">権限を保存</button></div>
            </form>
        @endforeach
    </div>

    <style>
        .member-access { display:grid; grid-template-columns:minmax(220px,1.1fr) minmax(180px,.7fr) minmax(320px,1.5fr) auto; align-items:start; gap:18px; }
        .member-access__identity { display:flex; justify-content:space-between; gap:12px; }
        .permission-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin:0; padding:14px; border:1px solid var(--line); border-radius:10px; }
        .permission-grid label { display:flex; align-items:flex-start; gap:8px; font-weight:400; }
        @media (max-width:1100px) { .member-access { grid-template-columns:1fr; } }
    </style>
@endsection
