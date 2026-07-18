@extends('layouts.app', ['title' => 'System Admin Login - Rise Gate OS'])

@section('content')
    <section class="panel stack">
        <div>
            <span class="badge">System Admin</span>
            <h1>システム管理ログイン</h1>
            <p>メンバー・Organization・Workspaceを管理する専用画面へログインします。</p>
        </div>

        <form class="stack" method="POST" action="{{ route('system-admin.login.store') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autofocus>
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
                <button type="submit">システム管理へログイン</button>
                <a href="{{ route('login') }}">Workspaceログインへ</a>
            </div>
        </form>
    </section>
@endsection
