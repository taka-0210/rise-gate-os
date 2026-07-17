@extends('layouts.app', ['title' => 'System Admin - Rise Gate OS'])

@section('content')
    <section class="stack">
        <div>
            <h1>システム管理</h1>
            <p>Rise Gate OSのメンバーを登録し、専用Workspaceの作成または既存Workspaceへの所属を行います。</p>
        </div>

        @if (session('status'))
            <div class="panel">{{ session('status') }}</div>
        @endif

        <div class="panel stack">
            <h2>メンバー登録</h2>
            <form class="stack" method="POST" action="{{ route('system-admin.members.store') }}">
                @csrf
                <div class="field">
                    <label for="name">氏名</label>
                    <input id="name" name="name" value="{{ old('name') }}" required>
                    @error('name') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="email">メールアドレス</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required>
                    @error('email') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="grid">
                    <div class="field">
                        <label for="password">初期パスワード</label>
                        <input id="password" name="password" type="password" required>
                        @error('password') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="password_confirmation">初期パスワード（確認）</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required>
                    </div>
                </div>
                <div class="field">
                    <label for="assignment_type">登録方法</label>
                    <select id="assignment_type" name="assignment_type" required>
                        <option value="new_workspace" @selected(old('assignment_type', 'new_workspace') === 'new_workspace')>専用Workspaceを自動作成</option>
                        <option value="existing_workspace" @selected(old('assignment_type') === 'existing_workspace')>既存Workspaceへ追加</option>
                    </select>
                    @error('assignment_type') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="grid">
                    <div class="field">
                        <label for="organization_name">新しいOrganization名</label>
                        <input id="organization_name" name="organization_name" value="{{ old('organization_name') }}">
                        @error('organization_name') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="workspace_name">新しいWorkspace名</label>
                        <input id="workspace_name" name="workspace_name" value="{{ old('workspace_name') }}">
                        @error('workspace_name') <div class="error">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="grid">
                    <div class="field">
                        <label for="workspace_id">既存Workspace</label>
                        <select id="workspace_id" name="workspace_id">
                            <option value="">選択してください</option>
                            @foreach ($workspaces as $workspace)
                                <option value="{{ $workspace->id }}" @selected((string) old('workspace_id') === (string) $workspace->id)>{{ $workspace->organization->name }} / {{ $workspace->name }}</option>
                            @endforeach
                        </select>
                        @error('workspace_id') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="workspace_role">Workspace権限</label>
                        <select id="workspace_role" name="workspace_role">
                            <option value="member" @selected(old('workspace_role', 'member') === 'member')>Member</option>
                            <option value="admin" @selected(old('workspace_role') === 'admin')>Admin</option>
                            <option value="viewer" @selected(old('workspace_role') === 'viewer')>Viewer</option>
                        </select>
                        @error('workspace_role') <div class="error">{{ $message }}</div> @enderror
                    </div>
                </div>
                <p class="meta">登録方法に対応する項目だけが使用されます。専用Workspaceを作成したメンバーはOwnerになります。</p>
                <div><button type="submit">メンバーを登録</button></div>
            </form>
        </div>

        <div class="stack">
            <h2>登録済みメンバー</h2>
            <div class="grid">
                @foreach ($members as $member)
                    <article class="card stack">
                        <div>
                            <h2>{{ $member->name }}</h2>
                            <div class="meta">{{ $member->email }}</div>
                        </div>
                        @if ($member->is_system_admin)<span class="badge">System Admin</span>@endif
                        <div class="meta">
                            @forelse ($member->workspaces as $workspace)
                                <div>{{ $workspace->organization->name }} / {{ $workspace->name }}（{{ $workspace->pivot->role }}）</div>
                            @empty
                                Workspace未所属
                            @endforelse
                        </div>
                        <div><a class="button secondary" href="{{ route('system-admin.members.edit', $member) }}">編集</a></div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endsection
