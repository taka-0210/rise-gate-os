@extends('layouts.app', ['title' => 'Workspace編集 - System Admin'])

@section('content')
    <section class="stack">
        <div><a href="{{ route('system-admin.workspaces.index') }}">← Workspace管理</a></div>
        <div>
            <span class="badge">System Admin</span>
            <h1>Workspace名を編集</h1>
            <p>Organization: {{ $workspace->organization->name }}</p>
            <p class="meta">契約者: {{ $workspace->owner?->name ?? '未設定' }} / 区分: {{ $workspace->billing_type }} / 利用目的: {{ $workspace->purpose ?: '未設定' }}</p>
        </div>
        @if (session('status'))<div class="panel">{{ session('status') }}</div>@endif
        <div class="panel">
            <form class="stack" method="POST" action="{{ route('system-admin.workspaces.update', $workspace) }}">
                @csrf @method('PUT')
                <div class="field">
                    <label for="name">Workspace名</label>
                    <input id="name" name="name" value="{{ old('name', $workspace->name) }}" required>
                    @error('name') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div><button type="submit">Workspace名を更新</button></div>
            </form>
        </div>
        <div class="panel stack">
            <h2>利用状態</h2>
            <form class="actions" method="POST" action="{{ route('system-admin.workspaces.status.update', $workspace) }}">
                @csrf @method('PUT')
                <select style="width:auto" name="status">
                    <option value="pending" @selected($workspace->status === 'pending')>承認待ち</option>
                    <option value="active" @selected($workspace->status === 'active')>利用中</option>
                    <option value="suspended" @selected($workspace->status === 'suspended')>利用停止</option>
                </select>
                <button type="submit">利用状態を更新</button>
            </form>
        </div>
    </section>
@endsection
