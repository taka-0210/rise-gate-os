@extends('layouts.app', ['title' => 'Owner Onboarding - Company OS'])

@section('content')
<div class="stack owner-onboarding">
    <section class="panel stack">
        <div class="meta">COMPANY OS / OWNER ONBOARDING</div>
        <h1>{{ $onboarding->organization_name }} を開始</h1>
        <p>開始管理番号 {{ $onboarding->public_id }}</p>
        @if(session('status')) <div class="notice">{{ session('status') }}</div> @endif
        @if($errors->any()) <div class="error">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div> @endif
    </section>

    @guest
        @if($existingAccount)
            <section class="panel stack"><h2>既存Accountで続ける</h2><p>{{ $onboarding->normalized_email }} のAccountでLoginしてください。Passwordや他Organizationの所属は変更しません。</p><a class="button" href="{{ route('login') }}">Login</a></section>
        @else
            <section class="panel stack">
                <h2>Accountを作成</h2><p>Emailは {{ $onboarding->normalized_email }} で固定され、Passwordはご本人だけが設定します。この時点ではOrganizationを作りません。</p>
                <form method="POST" action="{{ route('owner-onboarding.register') }}" class="stack">@csrf
                    <div class="field"><label for="owner-name">Name</label><input id="owner-name" name="name" value="{{ old('name') }}" required maxlength="255"></div>
                    <div class="field"><label for="owner-password">Password</label><input id="owner-password" type="password" name="password" required minlength="8"></div>
                    <div class="field"><label for="owner-password-confirmation">Password（確認）</label><input id="owner-password-confirmation" type="password" name="password_confirmation" required minlength="8"></div>
                    <label><input type="checkbox" name="accept_terms" value="1" required> <a href="{{ $legal['terms_url'] }}" target="_blank" rel="noopener">利用規約（{{ $legal['terms_version'] }}）</a>に同意します</label>
                    <label><input type="checkbox" name="accept_privacy" value="1" required> <a href="{{ $legal['privacy_url'] }}" target="_blank" rel="noopener">Privacy（{{ $legal['privacy_version'] }}）</a>に同意します</label>
                    <button type="submit">Accountを作成して続ける</button>
                </form>
            </section>
        @endif
    @else
        <section class="panel stack">
            <h2>{{ $user->name }}</h2><p>{{ $user->email }}</p>
            @if($onboarding->status === \App\Models\OwnerOnboarding::STATUS_COMPLETED)
                <p>この開始案件は完了済みです。現在の所属状態を確認して同じOrganizationへ戻ります。</p>
                <form method="POST" action="{{ route('owner-onboarding.complete') }}">@csrf<input type="hidden" name="confirm_owner_responsibility" value="1"><button type="submit">Company Homeへ</button></form>
            @elseif(!$hasCurrentConsent || $onboarding->claimed_user_id !== $user->id)
                <p>招待先Emailと現在のAccountが一致しています。現行文書への同意を記録して続けます。</p>
                <form method="POST" action="{{ route('owner-onboarding.prepare') }}" class="stack">@csrf
                    <label><input type="checkbox" name="accept_terms" value="1" required> <a href="{{ $legal['terms_url'] }}" target="_blank" rel="noopener">利用規約（{{ $legal['terms_version'] }}）</a>に同意します</label>
                    <label><input type="checkbox" name="accept_privacy" value="1" required> <a href="{{ $legal['privacy_url'] }}" target="_blank" rel="noopener">Privacy（{{ $legal['privacy_version'] }}）</a>に同意します</label>
                    <button type="submit">本人確認して続ける</button>
                </form>
            @else
                <div class="card stack"><h3>Email確認</h3><p>{{ $user->email_verified_at ? '現在のEmailは確認済みです。' : '現在のEmailは未確認です。会社の開始前に確認してください。' }}</p>@unless($user->email_verified_at)<form method="POST" action="{{ route('account.email.verify') }}">@csrf<button type="submit">確認Mailを送る</button></form>@endunless</div>
                <div class="grid"><article class="card"><div class="meta">ORGANIZATION</div><strong>{{ $onboarding->organization_name }}</strong></article><article class="card"><div class="meta">INITIAL ROLE</div><strong>Owner</strong></article><article class="card"><div class="meta">WORKSPACE</div><strong>共有標準Workspace 1件</strong></article></div>
                <form method="POST" action="{{ route('owner-onboarding.complete') }}" class="stack card">@csrf
                    <label><input type="checkbox" name="confirm_owner_responsibility" value="1" required> この会社のOwnerおよび標準Workspaceの管理主体になることを確認しました</label>
                    <p class="meta">会社名以外の初期入力は不要です。Group、Position、Avatar、Staff招待、Financial情報は後から設定できます。個人Workspaceはこの新Organizationでは有効化されません。</p>
                    <button type="submit" @disabled(!$user->email_verified_at)>会社を開始する</button>
                </form>
            @endif
        </section>
    @endguest
</div>
<style>
    .owner-onboarding, .owner-onboarding > *, .owner-onboarding .panel { min-width: 0; }
    .owner-onboarding h1, .owner-onboarding p, .owner-onboarding .meta { overflow-wrap: anywhere; }
    .owner-onboarding input[type="checkbox"] { width: auto; }
    @media(max-width:390px){.owner-onboarding .grid{grid-template-columns:1fr}.owner-onboarding .button,.owner-onboarding button{width:100%}}
</style>
@endsection
