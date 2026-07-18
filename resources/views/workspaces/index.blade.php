@extends('layouts.app', ['title' => 'Workspaces - Rise Gate OS'])

@section('content')
    <section class="stack">
        <div>
            <h1>Workspaceを選択</h1>
            <p>所有しているWorkspaceと、招待されて所属しているWorkspaceを切り替えます。</p>
        </div>
        <div class="actions"><a class="button" href="{{ route('workspaces.create') }}">新しいWorkspaceを作成</a></div>

        @if (session('status'))<div class="panel">{{ session('status') }}</div>@endif

        @if ($workspaces->isEmpty())
            <div class="panel">
                <p>参加中のWorkspaceがありません。管理者に招待を依頼してください。</p>
            </div>
        @else
            <div class="grid">
                @foreach ($workspaces as $workspace)
                    <article class="card stack">
                        <div>
                            <h2>
                                {{ $workspace->name }}
                                @if (in_array($workspace->pivot->role, ['owner', 'admin'], true))
                                    <a href="{{ route('workspaces.edit', $workspace) }}" style="font-size:12px; font-weight:400; white-space:nowrap;">（名前変更）</a>
                                @endif
                            </h2>
                            <div class="meta">{{ $workspace->organization->name }} / {{ $workspace->pivot->role }}</div>
                        </div>
                        <div class="actions">
                            @if ($workspace->owner_user_id === auth()->id())
                                <span class="badge">{{ $workspace->billing_type === 'included' ? '基本Workspace' : '追加Workspace' }}</span>
                            @else
                                <span class="badge">招待されたWorkspace</span>
                            @endif
                            @if ($workspace->status === 'pending')<span class="workspace-pill">承認待ち</span>@endif
                            @if ($workspace->status === 'suspended')<span class="workspace-pill">利用停止</span>@endif
                        </div>
                        <div class="workspace-stats">
                            <div><strong>{{ $workspace->projects_count }}</strong><span>Project</span></div>
                            <div><strong>{{ $workspace->clients_count }}</strong><span>クライアント</span></div>
                            <div><strong>{{ $workspace->improvements_count }}</strong><span>改善</span></div>
                            <div><strong>{{ $workspace->open_improvements_count }}</strong><span>育成中</span></div>
                            <div><strong>{{ $workspace->recent_improvements_count }}</strong><span>今週追加</span></div>
                        </div>
                        @if ($workspace->status === 'active')
                            <div class="actions">
                                <form method="POST" action="{{ route('workspaces.switch', $workspace) }}">
                                    @csrf
                                    <button type="submit">Dashboardへ</button>
                                </form>
                                <form method="POST" action="{{ route('workspaces.projects', $workspace) }}">
                                    @csrf
                                    <button class="secondary" type="submit">Project一覧へ</button>
                                </form>
                            </div>
                        @else
                            <p class="meta">System Adminの承認後に選択できます。</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
    <style>
        .workspace-stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 6px;
        }
        .workspace-stats div {
            display: grid;
            gap: 2px;
            padding: 9px 7px;
            border: 1px solid var(--line);
            border-radius: 6px;
            text-align: center;
            background: #f8fafb;
        }
        .workspace-stats strong { font-size: 18px; color: var(--accent-dark); }
        .workspace-stats span { color: var(--muted); font-size: 10px; white-space: nowrap; }
        @media (max-width: 520px) {
            .workspace-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
@endsection
