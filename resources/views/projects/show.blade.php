@extends('layouts.app', ['title' => $project->name.' - Rise Gate OS'])

@section('content')
    <section class="stack">
        <div class="actions" style="justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="meta">Project / {{ $statuses[$project->status] ?? $project->status }}</div>
                <h1>{{ $project->name }}</h1>
                <p>{{ $project->summary ?: '概要はまだありません。' }}</p>
            </div>
            @if ($canEditProject)<a class="button" href="{{ route('projects.edit', $project) }}">Projectを編集</a>@endif<a class="button secondary" href="{{ route('projects.index') }}">Project一覧へ戻る</a>
        </div>

        @if (session('status'))
            <div class="panel" style="border-color:#b7d8c2; background:#f3fbf6;">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="panel" style="border-color:#e3b8b8; background:#fff7f7;">
                @foreach ($errors->all() as $error)
                    <div class="error">{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <div class="grid">
            <div class="card">
                <h2>Company</h2>
                <p>
                    @if ($project->client)
                        <a href="{{ route('clients.show', $project->client) }}">{{ $project->client->name }}</a>
                    @else
                        社内Project
                    @endif
                </p>
            </div>
            <div class="card">
                <h2>作業スペース</h2>
                <p>{{ $project->owningWorkspace->name }}</p>
            </div>
            <div class="card">
                <h2>管理者</h2>
                <p>{{ $project->owner?->name ?? '未設定' }}</p>
            </div>
            <div class="card">
                <h2>優先度</h2>
                <p>{{ $priorities[$project->priority] ?? $project->priority }}</p>
            </div>
        </div>

        <div class="grid">
            <div class="card">
                <h2>Projectコード</h2>
                <p>{{ $project->code ?: '未設定' }}</p>
            </div>
            <div class="card">
                <h2>開始日</h2>
                <p>{{ $project->start_date?->format('Y-m-d') ?? '未設定' }}</p>
            </div>
            <div class="card">
                <h2>期限</h2>
                <p>{{ $project->due_date?->format('Y-m-d') ?? '未設定' }}</p>
            </div>
        </div>

        <div class="panel stack">
            <div class="actions" style="gap:8px;">
                <a class="button" href="#members">メンバー</a>
                <a class="button secondary" href="#tasks">Tasks</a>
                <a class="button secondary" href="#improvements">改善</a>
                <a class="button secondary" href="#documents">Documents</a>
                <a class="button secondary" href="#events">Project Events</a>
            </div>

            <section id="members" class="stack">
                <div class="actions" style="justify-content: space-between; align-items: flex-start;">
                    <div>
                        <h2>メンバー</h2>
                        <p>Projectへ参加している社内メンバー、パートナー、お客様を管理します。</p>
                    </div>
                </div>

                <div class="grid">
                    @foreach ($project->members as $member)
                        @php($isCurrentUser = $member->user_id === auth()->id())
                        <article class="card stack" @style([
                            'border-color: var(--accent-dark)' => $isCurrentUser,
                            'border-left: 5px solid var(--accent-dark)' => $isCurrentUser,
                            'background: #f1faf8' => $isCurrentUser,
                        ])>
                            <div class="actions" style="justify-content: space-between; align-items: flex-start;">
                                <div>
                                    <h2>{{ $member->user->name }}</h2>
                                    <div class="meta">{{ $member->user->email }}</div>
                                </div>
                                @if ($isCurrentUser)
                                    <span class="workspace-pill" style="border-color: var(--accent-dark); color: var(--accent-dark); background:#fff;">あなた</span>
                                @endif
                            </div>
                            <p>
                                役割: {{ $roles[$member->project_role] ?? $member->project_role }}<br>
                                権限: {{ $permissions[$member->permission_level] ?? $member->permission_level }}<br>
                                所属Workspace: {{ $member->workspace->name }}
                            </p>
                            @if ($canManageMembers && $member->user_id !== $project->owner_user_id)
                                <form method="POST" action="{{ route('projects.members.destroy', [$project, $member]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="secondary" type="submit">削除</button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>

                @if ($canManageMembers)
                    <div class="card stack">
                        <h2>メンバー追加</h2>
                        <p>メールアドレスで登録済みユーザーを検索し、追加対象を確認してからProjectへ追加します。</p>

                        <form class="stack" method="GET" action="{{ route('projects.show', $project) }}#members">
                            <div class="field">
                                <label for="member_email">メールアドレス</label>
                                <input id="member_email" name="member_email" type="email" value="{{ request('member_email', old('email')) }}" required>
                                @error('email') <div class="error">{{ $message }}</div> @enderror
                            </div>

                            <div class="grid">
                                <div class="field">
                                    <label for="preview_project_role">役割</label>
                                    <select id="preview_project_role" name="project_role" required>
                                        @foreach ($roles as $value => $label)
                                            <option value="{{ $value }}" @selected(request('project_role', old('project_role', 'viewer')) === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('project_role') <div class="error">{{ $message }}</div> @enderror
                                </div>

                                <div class="field">
                                    <label for="preview_permission_level">権限</label>
                                    <select id="preview_permission_level" name="permission_level" required>
                                        @foreach ($permissions as $value => $label)
                                            <option value="{{ $value }}" @selected(request('permission_level', old('permission_level', 'view')) === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('permission_level') <div class="error">{{ $message }}</div> @enderror
                                </div>

                                <div></div>
                            </div>

                            <div class="actions">
                                <button type="submit">検索して確認</button>
                            </div>
                        </form>

                        @if ($memberPreviewError)
                            <div class="panel" style="border-color:#e3b8b8; background:#fff7f7; padding:16px;">
                                <div class="error">{{ $memberPreviewError }}</div>
                            </div>
                        @endif

                        @if ($memberPreview)
                            <div class="panel stack" style="border-color:#b7d8c2; background:#f3fbf6; padding:18px;">
                                <div>
                                    <div class="meta">追加対象の確認</div>
                                    <h2>{{ $memberPreview['user']->name }}</h2>
                                    <p>{{ $memberPreview['user']->email }}</p>
                                </div>
                                <p>
                                    所属Workspace: {{ $memberPreview['workspace']->name }}<br>
                                    役割: {{ $roles[$memberPreview['project_role']] ?? $memberPreview['project_role'] }}<br>
                                    権限: {{ $permissions[$memberPreview['permission_level']] ?? $memberPreview['permission_level'] }}
                                </p>
                                <form method="POST" action="{{ route('projects.members.store', $project) }}">
                                    @csrf
                                    <input type="hidden" name="email" value="{{ $memberPreview['user']->email }}">
                                    <input type="hidden" name="project_role" value="{{ $memberPreview['project_role'] }}">
                                    <input type="hidden" name="permission_level" value="{{ $memberPreview['permission_level'] }}">
                                    <button type="submit">この内容で追加</button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endif
            </section>
        </div>

        <div class="grid">
            <div id="tasks" class="card">
                <h2>Tasks</h2>
                <p>Coming Soon</p>
            </div>
            <div id="improvements" class="card stack">
                <div class="actions" style="justify-content: space-between; align-items: flex-start;">
                    <div>
                        <h2>改善</h2>
                        <p>改善はProjectで生まれ、会社の知識として積み上がります。</p>
                    </div>
                    <div class="actions">
                        @if ($canCreateImprovement)
                            <a class="button" href="{{ route('projects.improvements.create', $project) }}">登録</a>
                        @endif
                        <a class="button secondary" href="{{ route('projects.improvements.index', $project) }}">すべて見る</a>
                    </div>
                </div>

                @if ($improvements->isEmpty())
                    <p>まだ改善はありません。</p>
                @else
                    @foreach ($improvements as $improvement)
                        <div style="border-top:1px solid var(--line); padding-top:12px;">
                            <div class="meta">
                                {{ $improvementStatuses[$improvement->status] ?? $improvement->status }} / {{ $improvementVisibilities[$improvement->visibility] ?? $improvement->visibility }}
                            </div>
                            <h2><a href="{{ route('projects.improvements.show', [$project, $improvement]) }}">{{ $improvement->title }}</a></h2>
                            <p>{{ Str::limit($improvement->problem ?: $improvement->next_action ?: '詳細は改善ページで確認できます。', 120) }}</p>
                        </div>
                    @endforeach
                @endif
            </div>
            <div id="documents" class="card">
                <h2>Documents</h2>
                <p>Coming Soon</p>
            </div>
            <div id="events" class="card">
                <h2>Project Events</h2>
                <p>Coming Soon</p>
            </div>
        </div>
    </section>
@endsection


