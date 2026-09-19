@extends('layouts.app', ['title' => 'Password再設定 - Company OS'])

@section('content')
    <section class="panel stack" style="max-width:640px; margin:0 auto;">
        <div>
            <h1>Passwordを忘れた場合</h1>
            <p>現在のEmailを入力してください。利用可能なAccountの場合だけ、60分有効な再設定Mailを送信します。</p>
        </div>
        @if (session('status')) <div class="notice">{{ session('status') }}</div> @endif
        <form class="stack" method="POST" action="{{ route('password.email') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email">
                @error('email') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div class="actions">
                <button type="submit">再設定Mailを送る</button>
                <a href="{{ route('login') }}">Loginへ戻る</a>
            </div>
        </form>
    </section>
@endsection
