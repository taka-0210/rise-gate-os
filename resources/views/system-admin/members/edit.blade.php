@extends('layouts.app', ['title' => 'メンバー編集 - Rise Gate OS'])

@section('content')
    <section class="stack">
        <div class="actions">
            <a href="{{ route('system-admin.members.index') }}">← メンバー一覧</a>
        </div>
        <div>
            <h1>{{ $member->name }}を編集</h1>
            <p>アカウント情報とWorkspace所属を管理します。</p>
        </div>

        @if (session('status'))<div class="panel">{{ session('status') }}</div>@endif
        @if ($errors->any())
            <div class="panel error"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <div class="panel stack">
            <h2>アカウント情報</h2>
            <form class="stack" method="POST" action="{{ route('system-admin.members.update', $member) }}">
                @csrf @method('PUT')
                <div class="field"><label for="name">氏名</label><input id="name" name="name" value="{{ old('name', $member->name) }}" required></div>
                <div class="field"><label for="email">メールアドレス</label><input id="email" name="email" type="email" value="{{ old('email', $member->email) }}" required></div>
                <div class="grid">
                    <div class="field"><label for="password">新しいパスワード</label><input id="password" name="password" type="password"><div class="meta">変更しない場合は空欄</div></div>
                    <div class="field"><label for="password_confirmation">新しいパスワード（確認）</label><input id="password_confirmation" name="password_confirmation" type="password"></div>
                </div>
                <input type="hidden" name="is_system_admin" value="0">
                <label><input style="width:auto" type="checkbox" name="is_system_admin" value="1" @checked(old('is_system_admin', $member->is_system_admin))> System Admin権限を付与</label>
                <input type="hidden" name="is_active" value="0">
                <label><input style="width:auto" type="checkbox" name="is_active" value="1" @checked(old('is_active', $member->is_active))> アカウントを有効にする</label>
                <div><button type="submit">アカウント情報を更新</button></div>
            </form>
        </div>

        <div class="panel stack">
            <h2>Workspace所属</h2>
            @forelse ($member->workspaces as $workspace)
                <div class="card stack">
                    <div><strong>{{ $workspace->organization->name }} / {{ $workspace->name }}</strong></div>
                    <form class="actions" method="POST" action="{{ route('system-admin.members.workspaces.update', [$member, $workspace]) }}">
                        @csrf @method('PUT')
                        <select style="width:auto" name="workspace_role">
                            @foreach (['owner' => 'Owner', 'admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'] as $value => $label)
                                <option value="{{ $value }}" @selected($workspace->pivot->role === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <button type="submit">権限更新</button>
                    </form>
                    <form method="POST" action="{{ route('system-admin.members.workspaces.destroy', [$member, $workspace]) }}">
                        @csrf @method('DELETE')
                        <button class="secondary" type="submit">Workspaceから解除</button>
                    </form>
                </div>
            @empty
                <p>所属Workspaceはありません。</p>
            @endforelse

            <h2>Workspaceへ追加</h2>
            <form class="actions" method="POST" action="{{ route('system-admin.members.workspaces.store', $member) }}">
                @csrf
                <select style="width:auto" name="workspace_id" required>
                    <option value="">Workspaceを選択</option>
                    @foreach ($workspaces as $workspace)
                        @unless ($member->workspaces->contains($workspace->id))
                            <option value="{{ $workspace->id }}">{{ $workspace->organization->name }} / {{ $workspace->name }}</option>
                        @endunless
                    @endforeach
                </select>
                <select style="width:auto" name="workspace_role" required>
                    <option value="member">Member</option><option value="admin">Admin</option><option value="viewer">Viewer</option><option value="owner">Owner</option>
                </select>
                <button type="submit">追加</button>
            </form>
        </div>
    </section>
@endsection
