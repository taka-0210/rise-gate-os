@extends('layouts.app', ['title' => 'Login - Rise Gate OS'])

@section('content')
    <section class="panel stack">
        <div>
            <h1>Login</h1>
            <p>Workspaceを持つRise Gate OSへログインします。</p>
        </div>

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
                <a href="{{ route('register') }}">新しく始める</a>
            </div>
        </form>
    </section>
@endsection
