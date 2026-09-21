@extends('layouts.app', ['title' => '利用会社 - Company OS'])
@section('content')
<section class="stack" style="max-width:820px;margin-inline:auto">
    <div><div class="meta">Company OS</div><h1>利用会社</h1></div>
    @error('product_organization')<div class="error">{{ $message }}</div>@enderror
    @if($productOrganizationState === 'selection')
        <div class="notice"><strong>利用する会社を選択してください</strong><p>既存の複数会社利用です。会社は自動選択されません。</p></div>
        <div class="grid">
            @foreach($companies as $company)
                <article class="card stack"><div class="meta">会社</div><h2>{{ $company->name }}</h2><p>{{ $company->workspaces_count }} Workspace</p>
                    <form method="POST" action="{{ route('companies.switch', $company) }}">@csrf<button>この会社を利用する</button></form>
                </article>
            @endforeach
        </div>
    @elseif($productOrganizationState === 'suspended')
        <div class="notice"><strong>会社の利用が一時停止されています</strong><p>会社のOwnerまたは管理担当者へ確認してください。</p></div>
    @elseif($productOrganizationState === 'left')
        <div class="notice"><strong>会社への所属は終了しています</strong><p>過去の履歴は保持されています。</p></div>
    @elseif($productOrganizationState === 'review_required')
        <div class="notice"><strong>利用会社の確認が必要です</strong><p>既存Dataを変更せず保護しています。System Adminへ確認してください。</p></div>
    @else
        <div class="notice"><strong>利用する会社がまだ設定されていません</strong><p>Owner OnboardingまたはStaff Invitationから開始してください。停止・退職を含むAccountと所属履歴を確認することもできます。</p></div>
    @endif
    <div class="panel actions">
        <a class="button secondary" href="{{ route('account.profile') }}">Account</a>
        @if(auth()->user()->is_system_admin)<a class="button secondary" href="{{ route('system-admin.login') }}">System Admin</a>@endif
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="secondary">Logout</button></form>
    </div>
</section>
@endsection
