@extends('layouts.app', ['title' => 'Login - Company OS'])

@push('head')
    <link rel="stylesheet" href="{{ asset('css/company-os-login.css') }}">
@endpush

@section('content')
    <section class="login-brand" aria-labelledby="login-brand-title">
        <div class="login-brand__composition">
            <div class="login-brand__content">
                <p class="login-brand__eyebrow">THE OPERATING SYSTEM FOR YOUR COMPANY</p>
                <h1 id="login-brand-title" class="login-brand__wordmark">
                    <span>Company</span>
                    <span>OS</span>
                </h1>
                <p class="login-brand__statement">次世代の経営指針。</p>
                <p class="login-brand__description">
                    会社そのものをデジタル上に構造化し、<br>
                    その会社を理解するAIとともに経営する。
                </p>

                <form class="login-brand__form" method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="login-brand__form-heading">
                        <div>
                            <p class="login-brand__form-kicker">COMPANY ACCESS</p>
                            <h2>会社へログイン</h2>
                        </div>
                        <span class="login-brand__form-number" aria-hidden="true">01</span>
                    </div>

                    @if (session('status'))
                        <div class="notice login-brand__status" role="status">{{ session('status') }}</div>
                    @endif

                    <div class="login-brand__field">
                        <label for="email">メールアドレス</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            autocomplete="username"
                            required
                            autofocus
                            @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                        >
                        @error('email')
                            <div id="email-error" class="error">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="login-brand__field">
                        <label for="password">パスワード</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            required
                            @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                        >
                        @error('password')
                            <div id="password-error" class="error">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="login-brand__options">
                        <label class="login-brand__remember">
                            <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                            <span>ログイン状態を保持</span>
                        </label>
                        <a href="{{ route('password.request') }}">Passwordを忘れた場合</a>
                    </div>

                    <button class="login-brand__submit" type="submit">
                        <span>ログイン</span>
                        <span aria-hidden="true">→</span>
                    </button>

                    @if (\App\Models\User::query()->doesntExist())
                        <a class="login-brand__setup" href="{{ route('register') }}">初期セットアップ</a>
                    @endif
                </form>
            </div>

            <aside class="login-brand__visual" aria-hidden="true">
                <div class="login-brand__visual-wash"></div>
                <img
                    class="login-brand__visual-image"
                    src="{{ asset('images/company-os-brand-symbol.svg') }}"
                    alt=""
                    decoding="async"
                    fetchpriority="high"
                >
                <div class="login-brand__axis">
                    <span>PAST</span>
                    <i></i>
                    <span class="is-active">NOW</span>
                    <i></i>
                    <span>FUTURE</span>
                </div>
            </aside>
        </div>

        <footer class="login-brand__footer">
            <span>Company OS</span>
            <span>PRIVATE / AUTHENTICATED SPACE</span>
        </footer>
    </section>
@endsection
