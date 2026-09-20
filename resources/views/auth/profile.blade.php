@extends('layouts.app', ['title' => 'Account - Company OS'])

@section('content')
    <section class='panel stack' style='margin-bottom:20px'>
        <h2>Avatar</h2>
        <div class='identity-row'><x-user-avatar :user='$user' :size='72' /><p>任意です。PNG / JPEG / WebP、5MB以下。Private保存し、安全なWebPへ再変換します。</p></div>
        <form class='stack' method='POST' action='{{ route('account.avatar.update') }}' enctype='multipart/form-data'>
            @csrf
            <input name='avatar' type='file' accept='image/png,image/jpeg,image/webp' required>
            <button type='submit'>Avatarを更新</button>
        </form>
    </section>
    <div class="stack">
        <section class="panel stack">
            <div>
                <h1>Account</h1>
                <p>NameとEmailは、所属するすべてのOrganizationで共通の本人情報です。所属・Role・権限はここでは変更できません。</p>
            </div>
            @if (session('status')) <div class="notice">{{ session('status') }}</div> @endif
            @if ($errors->any())
                <div class="error">
                    @foreach ($errors->all() as $error) <div>{{ $error }}</div> @endforeach
                </div>
            @endif
        </section>

        <section class="panel stack">
            <div>
                <h2>所属Organization</h2>
                <p>ここでは本人の所属情報だけを確認できます。Organization Role・Position・GroupはOrganizationごとに保持されます。</p>
            </div>
            <div class="grid">
                @forelse ($organizationMemberships as $membership)
                    <article class="card stack">
                        <div>
                            <div class="meta">{{ \App\Models\OrganizationUser::membershipStatuses()[$membership->membership_status] ?? $membership->membership_status }}</div>
                            <h3>{{ $membership->organization->name }}</h3>
                        </div>
                        <p><strong>Organization Role:</strong> {{ \App\Models\OrganizationUser::organizationRoles()[$membership->organization_role] ?? '未解決' }}</p>
                        <p><strong>Position:</strong> {{ $membership->position ?: '未設定' }}</p>
                        <p><strong>Group:</strong> {{ $membership->groups->pluck('name')->join(' / ') ?: 'Groupなし' }}</p>
                    </article>
                @empty
                    <p class="meta">所属Organizationはありません。Account管理は引き続き利用できます。</p>
                @endforelse
            </div>
        </section>

        <section class="panel stack">
            <h2>Profile</h2>
            <form class="stack" method="POST" action="{{ route('account.profile.update') }}">
                @csrf
                @method('PATCH')
                <div class="field">
                    <label for="name">Name</label>
                    <input id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name">
                </div>
                <button type="submit">Nameを更新</button>
            </form>
        </section>

        <section class="panel stack">
            <h2>現在のEmail</h2>
            <p><strong>{{ $user->email }}</strong></p>
            <p>{{ $user->email_verified_at ? '確認済み（'.$user->email_verified_at->timezone('Asia/Tokyo')->format('Y/m/d H:i').' JST）' : '未確認' }}</p>
            @unless ($user->email_verified_at)
                <form method="POST" action="{{ route('account.email.verify') }}">
                    @csrf
                    <button type="submit">現在のEmailへ確認Mailを送る</button>
                </form>
            @endunless
        </section>

        <section class="panel stack">
            <h2>Emailを変更</h2>
            <p>候補Emailで確認が完了するまで、LoginとPassword再設定には現在のEmailを使います。</p>
            @if ($pendingEmailRequest)
                <div class="notice">
                    {{ $pendingEmailRequest->pending_email }} の確認待ちです。
                    有効期限：{{ $pendingEmailRequest->expires_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST
                </div>
                <div class="actions">
                    <form method="POST" action="{{ route('account.email.change.resend') }}">
                        @csrf
                        <button type="submit">確認Mailを再送</button>
                    </form>
                    <form method="POST" action="{{ route('account.email.change.cancel') }}">
                        @csrf
                        @method('DELETE')
                        <button class="secondary" type="submit">変更を取り消す</button>
                    </form>
                </div>
            @endif
            <form class="stack" method="POST" action="{{ route('account.email.change') }}">
                @csrf
                <div class="field">
                    <label for="new_email">候補Email</label>
                    <input id="new_email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email">
                </div>
                <div class="field">
                    <label for="email_current_password">現在のPassword</label>
                    <input id="email_current_password" name="current_password" type="password" required autocomplete="current-password">
                </div>
                <button type="submit">候補Emailへ確認Mailを送る</button>
            </form>
        </section>

        <section class="panel stack">
            <h2>Passwordを変更</h2>
            <p>変更後は、すべてのCompany OS Web Sessionで再Loginが必要です。</p>
            <form class="stack" method="POST" action="{{ route('account.password.update') }}">
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="current_password">現在のPassword</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
                </div>
                <div class="field">
                    <label for="new_password">新しいPassword</label>
                    <input id="new_password" name="password" type="password" required autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="new_password_confirmation">新しいPassword（確認）</label>
                    <input id="new_password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                </div>
                <button type="submit">Passwordを変更</button>
            </form>
        </section>
    </div>
@endsection
