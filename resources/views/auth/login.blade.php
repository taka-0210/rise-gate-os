@extends('layouts.app', ['title' => 'Login - Company OS'])

@section('content')
    <section class="panel stack">
        <div>
            <h1>Company OSへログイン</h1>
            <p>登録済みのアカウントでCompany OSを利用します。</p>
        </div>
        @if (session('status')) <div class="notice">{{ session('status') }}</div> @endif

        <form class="stack" method="POST" action="{{ route('login') }}">
            @csrf

            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
                @error('email') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required>
                @error('password') <div class="error">{{ $message }}</div> @enderror
            </div>

            <label style="display:flex; gap:8px; align-items:center; font-weight:400;">
                <input type="checkbox" name="remember" value="1" style="width:auto;"> Remember me
            </label>

            <div class="actions">
                <button type="submit">Login</button>
                <a href="{{ route('password.request') }}">Passwordを忘れた場合</a>
                @if (\App\Models\User::query()->doesntExist())
                    <a href="{{ route('register') }}">初期セットアップ</a>
                @endif
            </div>
        </form>
    </section>
@endsection
