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

        @php($sourceImprovement = $project->sourceImprovementOutput?->improvement)
        @if ($project->sourceImprovementOutput)
            <div class="panel stack origin-panel">
                <div>
                    <div class="badge">改善から生まれたProject</div>
                    <h2>このProjectの起点</h2>
                    <p>このProjectは、別のProjectで生まれた改善から切り出されました。</p>
                </div>

                @if ($sourceImprovement && Gate::allows('view', $sourceImprovement))
                    <div class="grid">
                        <div class="card">
                            <h2>元Project</h2>
                            <p><a href="{{ route('projects.show', $sourceImprovement->project) }}">{{ $sourceImprovement->project->name }}</a></p>
                        </div>
                        <div class="card">
                            <h2>元Improvement</h2>
                            <p><a href="{{ route('projects.improvements.show', [$sourceImprovement->project, $sourceImprovement]) }}">{{ $sourceImprovement->title }}</a></p>
                        </div>
                    </div>
                @else
                    <p>起点となった改善は公開範囲により表示されません。</p>
                @endif
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
            <div id="tasks" class="card stack">
                <div class="actions" style="justify-content: space-between; align-items: flex-start;">
                    <div>
                        <h2>Tasks</h2>
                        <p>TaskはProject内で進める具体的な作業です。Improvementから生まれたTaskは、その由来も残ります。</p>
                    </div>
                </div>

                @if ($tasks->isEmpty())
                    <p>まだTaskはありません。</p>
                @else
                    @foreach ($tasks as $task)
                        <div style="border-top:1px solid var(--line); padding-top:12px;">
                            <div class="meta">
                                {{ $taskStatuses[$task->status] ?? $task->status }} / {{ $taskPriorities[$task->priority] ?? $task->priority }}
                            </div>
                            <h2>{{ $task->title }}</h2>
                            <p>{{ Str::limit($task->description ?: '詳細はまだありません。', 120) }}</p>
                            <p class="meta">
                                担当者: {{ $task->assignee?->name ?? '未設定' }}
                                @if ($task->due_date)
                                    / 期限: {{ $task->due_date->format('Y-m-d') }}
                                @endif
                                @if ($task->improvement)
                                    / 起点: <a href="{{ route('projects.improvements.show', [$project, $task->improvement]) }}">{{ $task->improvement->title }}</a>
                                @endif
                            </p>
                        </div>
                    @endforeach
                @endif

                @if ($canCreateTask)
                    <form class="stack" method="POST" action="{{ route('projects.tasks.store', $project) }}">
                        @csrf
                        <h2>ProjectからTaskを登録</h2>
                        <p>通常作業として登録します。改善から生まれた作業は、Improvement詳細からTask化できます。</p>
                        <div class="field">
                            <label for="task_title">Task名</label>
                            <input id="task_title" name="title" value="{{ old('title') }}" required>
                        </div>
                        <div class="field">
                            <label for="task_description">説明</label>
                            <textarea id="task_description" name="description" rows="3">{{ old('description') }}</textarea>
                        </div>
                        <div class="grid">
                            <div class="field">
                                <label for="task_status">状態</label>
                                <select id="task_status" name="status" required>
                                    @foreach ($taskStatuses as $value => $label)
                                        <option value="{{ $value }}" @selected(old('status', 'todo') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="task_priority">優先度</label>
                                <select id="task_priority" name="priority" required>
                                    @foreach ($taskPriorities as $value => $label)
                                        <option value="{{ $value }}" @selected(old('priority', 'normal') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="task_assigned_to">担当者</label>
                                <select id="task_assigned_to" name="assigned_to">
                                    <option value="">未設定</option>
                                    @foreach ($assignableUsers as $user)
                                        <option value="{{ $user->id }}" @selected((string) old('assigned_to') === (string) $user->id)>{{ $user->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="field">
                            <label for="task_due_date">期限</label>
                            <input id="task_due_date" name="due_date" type="date" value="{{ old('due_date') }}">
                        </div>
                        <div class="actions">
                            <button type="submit">Taskを登録</button>
                        </div>
                    </form>
                @endif
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


