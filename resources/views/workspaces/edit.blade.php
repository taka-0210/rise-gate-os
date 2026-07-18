@extends('layouts.app', ['title' => 'Workspace設定 - Rise Gate OS'])

@section('content')
    <section class="stack">
        <div><a href="{{ route('workspaces.index') }}">← Workspace一覧</a></div>
        <div>
            <h1>Workspace設定</h1>
            <p>{{ $workspace->organization->name }}に所属するWorkspaceの名称を変更します。</p>
        </div>
        @if (session('status'))<div class="panel">{{ session('status') }}</div>@endif
        <div class="panel stack">
            <form class="stack" method="POST" action="{{ route('workspaces.update', $workspace) }}">
                @csrf @method('PUT')
                <div class="field">
                    <label for="name">Workspace名</label>
                    <input id="name" name="name" value="{{ old('name', $workspace->name) }}" required>
                    @error('name') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div><button type="submit">Workspace名を更新</button></div>
            </form>
        </div>
    </section>
@endsection
