@extends('layouts.app', ['title' => 'Password再設定 - Company OS'])

@section('content')
    <section class="panel stack" style="max-width:640px; margin:0 auto;">
        <div>
            <h1>新しいPasswordを設定</h1>
            <p>再設定後は、すべてのCompany OS Web Sessionで再Loginが必要です。</p>
        </div>
        <form class="stack" method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email">
                @error('email') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <label for="password">新しいPassword</label>
                <input id="password" name="password" type="password" required autocomplete="new-password">
                @error('password') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="field">
                <label for="password_confirmation">新しいPassword（確認）</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </div>
            <button type="submit">Passwordを再設定</button>
        </form>
    </section>
@endsection
