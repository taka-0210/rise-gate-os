@extends('layouts.app', ['title' => 'Register - Rise Gate OS'])

@section('content')
    <section class="panel stack">
        <div>
            <h1>Rise Gate OS を開始</h1>
            <p>最初のユーザー、Organization、Workspaceを作成します。作成したユーザーはOwnerになります。</p>
        </div>

        <form class="stack" method="POST" action="{{ route('register') }}">
            @csrf

            <div class="field">
                <label for="name">User name</label>
                <input id="name" name="name" value="{{ old('name') }}" required autofocus>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required>
                @error('email') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="organization_name">Organization</label>
                <input id="organization_name" name="organization_name" value="{{ old('organization_name', 'Rise Gate') }}" required>
                @error('organization_name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="workspace_name">Workspace</label>
                <input id="workspace_name" name="workspace_name" value="{{ old('workspace_name', 'Rise Gate Workspace') }}" required>
                @error('workspace_name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required>
                @error('password') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required>
            </div>

            <div class="actions">
                <button type="submit">Create workspace</button>
                <a href="{{ route('login') }}">すでにアカウントがある場合</a>
            </div>
        </form>
    </section>
@endsection
