@extends('layouts.app', ['title' => '新しいWorkspace - Rise Gate OS'])

@section('content')
    <section class="stack">
        <div><a href="{{ route('company.home') }}">← {{ $currentCompany->name }}</a></div>
        <div>
            <h1>新しいWorkspace</h1>
            <p>{{ $currentCompany->name }}の中に、共有または個人用の仕事場を作成します。</p>
        </div>
        <div class="panel stack">
            <div class="card">
                <strong>追加Workspaceについて</strong>
                <p class="meta">所有する1個目は基本Workspaceです。2個目以降は追加Workspaceとして申請され、System Adminの承認後に利用できます。招待されて所属しているWorkspaceは、この数に含みません。</p>
            </div>
            <form class="stack" method="POST" action="{{ route('workspaces.store') }}">
                @csrf
                <div class="field">
                    <label for="workspace_name">Workspace名</label>
                    <input id="workspace_name" name="workspace_name" value="{{ old('workspace_name') }}" required>
                    @error('workspace_name') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="type">Workspaceの種類</label>
                    <select id="type" name="type" required>
                        <option value="shared" @selected(old('type', 'shared') === 'shared')>共有Workspace</option>
                        <option value="personal" @selected(old('type') === 'personal')>個人Workspace</option>
                    </select>
                    <p class="meta">個人Workspaceも会社の資産です。原則として本人だけが利用し、会社Ownerが管理できます。</p>
                    @error('type') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="purpose">利用目的</label>
                    <input id="purpose" name="purpose" value="{{ old('purpose') }}" placeholder="例：新規事業、個人活動、別ブランド">
                    @error('purpose') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div><button type="submit">Workspaceを作成・申請</button></div>
            </form>
        </div>
    </section>
@endsection
