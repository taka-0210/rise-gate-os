@extends('layouts.app', ['title' => 'Account - Company OS'])

@section('content')
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
