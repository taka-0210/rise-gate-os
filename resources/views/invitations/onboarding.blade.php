@extends('layouts.app', ['title' => 'Invitation - Company OS'])

@section('content')
    <div class='stack invitation-onboarding'>
        <section class='panel stack'>
            <div class='meta'>COMPANY OS / INVITATION</div>
            <h1>{{ $invitation->organization->name }} への招待</h1>
            <p>本人確認、Profile確認、任意のAvatar設定を行い、最後に所属を開始します。</p>
            @if (session('status')) <div class='notice'>{{ session('status') }}</div> @endif
            @if ($errors->any())
                <div class='error'>@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
            @endif
        </section>

        @guest
            @if ($existingAccount)
                <section class='panel stack'>
                    <h2>既存Accountで続ける</h2>
                    <p>{{ $invitation->normalized_email }} のAccountでLoginしてください。PasswordやProfileは招待によって変更されません。</p>
                    <a class='button' href='{{ route('login') }}'>Login</a>
                </section>
            @else
                <section class='panel stack'>
                    <h2>Accountを作成</h2>
                    <p>Emailは招待先の {{ $invitation->normalized_email }} で固定されます。Passwordはご本人だけが設定します。</p>
                    <form class='stack' method='POST' action='{{ route('invitations.register') }}'>
                        @csrf
                        <div class='field'><label for='invite-name'>Name</label><input id='invite-name' name='name' value='{{ old('name') }}' required maxlength='255' autocomplete='name'></div>
                        <div class='field'><label for='invite-password'>Password</label><input id='invite-password' name='password' type='password' required minlength='8' autocomplete='new-password'></div>
                        <div class='field'><label for='invite-password-confirmation'>Password（確認）</label><input id='invite-password-confirmation' name='password_confirmation' type='password' required minlength='8' autocomplete='new-password'></div>
                        <button type='submit'>Accountを作成して続ける</button>
                    </form>
                </section>
            @endif
        @else
            <section class='panel stack'>
                <div class='identity-row'>
                    <x-user-avatar :user='$user' :size='56' />
                    <div><h2>{{ $user->name }}</h2><p>{{ $user->email }}</p></div>
                </div>
                @if (! $membership)
                    <p>招待先Emailと現在のAccountが一致しています。続行すると、受諾前の invited 所属だけを準備します。</p>
                    <form method='POST' action='{{ route('invitations.prepare') }}'>@csrf<button type='submit'>本人確認して続ける</button></form>
                @else
                    <div class='grid'>
                        <article class='card'><div class='meta'>ORGANIZATION ROLE</div><strong>{{ \App\Models\OrganizationUser::organizationRoles()[$invitation->intended_organization_role] }}</strong></article>
                        <article class='card'><div class='meta'>GROUP</div><strong>{{ $invitation->groups->pluck('name')->join(' / ') ?: 'Groupなし' }}</strong></article>
                    </div>

                    <form class='stack card' method='POST' action='{{ route('account.profile.update') }}'>
                        @csrf @method('PATCH')
                        <h3>Profile確認</h3>
                        <div class='field'><label for='invite-profile-name'>Name</label><input id='invite-profile-name' name='name' value='{{ old('name', $user->name) }}' required maxlength='255'></div>
                        <button type='submit'>Nameを更新</button>
                    </form>

                    <div class='card stack'>
                        <h3>Email確認</h3>
                        <p>{{ $user->email_verified_at ? '確認済み' : '未確認です。確認Mailの操作完了まで所属はactiveになりません。' }}</p>
                        @unless ($user->email_verified_at)
                            <form method='POST' action='{{ route('account.email.verify') }}'>@csrf<button type='submit'>確認Mailを送る</button></form>
                        @endunless
                    </div>

                    <form class='stack card' method='POST' action='{{ route('account.avatar.update') }}' enctype='multipart/form-data'>
                        @csrf
                        <h3>Avatar（任意）</h3>
                        <p>PNG / JPEG / WebP、5MB以下。安全なWebPへ再変換してPrivate保存します。今はSkipして後からAccount画面で設定できます。</p>
                        <input name='avatar' type='file' accept='image/png,image/jpeg,image/webp'>
                        <button type='submit'>AvatarをUpload</button>
                    </form>

                    @if ($membership->membership_status === \App\Models\OrganizationUser::STATUS_ACTIVE)
                        <form method='POST' action='{{ route('invitations.accept') }}'>@csrf<button type='submit'>Already joinedとして完了</button></form>
                    @else
                        <form class='stack' method='POST' action='{{ route('invitations.accept') }}'>
                            @csrf
                            <h3>所属を開始</h3>
                            <p>この操作でOrganization所属、予定Group、標準Workspace所属、招待消費が同時に確定します。</p>
                            @unless ($invitation->organization->standard_workspace_id)<div class='notice'>Ownerによる標準Workspace初期設定を待っています。</div>@endunless
                            <button type='submit' @disabled(! $user->email_verified_at || ! $invitation->organization->standard_workspace_id)>所属を開始する</button>
                        </form>
                    @endif
                @endif
            </section>
        @endguest
    </div>
    <style>
        .identity-row { display:flex; gap:14px; align-items:center; }
        @media (max-width:390px) { .invitation-onboarding .grid { grid-template-columns:1fr; } }
    </style>
@endsection
