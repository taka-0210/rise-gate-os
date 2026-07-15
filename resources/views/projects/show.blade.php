@extends('layouts.app', ['title' => $project->name.' - Rise Gate OS'])

@section('content')
    <style>
        .roadmap-lanes { display: grid; gap: 22px; }
        .roadmap-lane {
            display: grid;
            grid-template-columns: minmax(240px, 300px) minmax(0, 1fr);
            gap: 18px;
            align-items: stretch;
        }
        .roadmap-start { border-color: #a9d6cf; background: #f2fbf9; }
        .roadmap-flow {
            display: flex;
            align-items: stretch;
            gap: 12px;
            overflow-x: auto;
            padding: 4px 4px 14px;
            scroll-snap-type: x proximity;
        }
        .roadmap-step {
            min-width: 250px;
            max-width: 290px;
            scroll-snap-align: start;
        }
        .roadmap-step-empty { border-style: dashed; background: #fbfcfd; }
        .roadmap-arrow {
            display: grid;
            place-items: center;
            flex: 0 0 32px;
            color: var(--accent-dark);
            font-size: 22px;
            font-weight: 900;
        }
        .roadmap-arrow-forward { color: var(--ink); }
        @media (max-width: 760px) {
            .roadmap-lane { grid-template-columns: 1fr; }
            .roadmap-flow {
                display: grid;
                overflow-x: visible;
                padding: 0;
            }
            .roadmap-step {
                min-width: 0;
                max-width: none;
            }
            .roadmap-arrow {
                min-height: 28px;
                transform: rotate(90deg);
            }
        }
    </style>

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
                <a class="button secondary" href="#roadmaps">Roadmap</a>
                <a class="button secondary" href="#tasks">Tasks</a>
                <a class="button secondary" href="#improvements">改善</a>
                <a class="button secondary" href="#documents">Documents</a>
                <a class="button secondary" href="#events">Project Events</a>
            </div>

            <section id="roadmaps" class="stack">
                <div class="actions" style="justify-content: space-between; align-items: flex-start;">
                    <div>
                        <h2>Roadmap</h2>
                        <p>Roadmapは、このProjectが目指す未来へ向かうテーマです。Improvementは、そのテーマを一歩ずつ前へ進める具体的な改善です。</p>
                    </div>
                </div>

                @if ($roadmaps->isEmpty())
                    <div class="card stack">
                        <h2>Roadmapはまだありません</h2>
                        <p>まずはImprovementを育てるだけでも十分です。改善が増えてきたら、未来へ向かうテーマとしてRoadmapを育てられます。</p>
                        @if ($unclassifiedImprovements->count() >= 3)
                            <p class="meta">このProjectには未分類の改善が{{ $unclassifiedImprovements->count() }}件あります。テーマごとに束ねると、次に進む道筋が見えやすくなります。</p>
                        @endif
                    </div>
                @else
                    <div class="roadmap-lanes">
                        @foreach ($roadmaps as $roadmap)
                            @php($roadmapImprovements = $roadmap->improvements)
                            @php($completedCount = $roadmapImprovements->whereIn('status', ['implemented', 'measured', 'closed'])->count())
                            <article class="roadmap-lane">
                                <div class="roadmap-start card stack">
                                    <div class="meta">ロードマップテーマ / {{ $roadmapStatuses[$roadmap->status] ?? $roadmap->status }}</div>
                                    <h2>{{ $roadmap->title }}</h2>
                                    <p>{{ $roadmap->purpose ?: 'このテーマが目指す未来はまだ記録されていません。' }}</p>
                                    <p class="meta">{{ $roadmapImprovements->count() }}件中{{ $completedCount }}件が前へ進みました。</p>
                                </div>

                                <div class="roadmap-flow" aria-label="{{ $roadmap->title }}のテーマの流れ">
                                    <div class="roadmap-arrow roadmap-arrow-forward" aria-hidden="true">▶</div>

                                    @if ($roadmapImprovements->isEmpty())
                                        <div class="roadmap-step roadmap-step-empty card">
                                            <div class="meta">次の一歩</div>
                                            <h2>Improvementを追加できます</h2>
                                            <p>このテーマを前へ進める具体的な改善は、まだ追加されていません。</p>
                                        </div>
                                    @else
                                        @foreach ($roadmapImprovements as $roadmapImprovement)
                                            <div class="roadmap-step card stack">
                                                <div>
                                                    <div class="meta">Step {{ $loop->iteration }} / {{ $improvementStatuses[$roadmapImprovement->status] ?? $roadmapImprovement->status }}</div>
                                                    <h2><a href="{{ route('projects.improvements.show', [$project, $roadmapImprovement]) }}">{{ $roadmapImprovement->title }}</a></h2>
                                                    <p>{{ Str::limit($roadmapImprovement->next_action ?: $roadmapImprovement->problem ?: 'この改善はテーマを前へ進める一歩です。', 100) }}</p>
                                                </div>
                                                @if ($canCreateRoadmap)
                                                    <form method="POST" action="{{ route('projects.improvements.roadmap.remove', [$project, $roadmapImprovement]) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button class="secondary" type="submit">未分類に戻す</button>
                                                    </form>
                                                @endif
                                            </div>

                                            @unless ($loop->last)
                                                <div class="roadmap-arrow" aria-hidden="true">▶</div>
                                            @endunless
                                        @endforeach
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif

                @if ($canCreateRoadmap)
                    <div class="card stack">
                        <h2>ロードマップテーマを作成</h2>
                        <p>最初から完成した計画にする必要はありません。Projectの未来へ向かうテーマとして、育てながら使います。</p>
                        <form class="stack" method="POST" action="{{ route('projects.roadmaps.store', $project) }}">
                            @csrf
                            <div class="field">
                                <label for="roadmap_title">テーマ名</label>
                                <input id="roadmap_title" name="title" value="{{ old('title') }}" required>
                            </div>
                            <div class="field">
                                <label for="roadmap_purpose">目指す未来</label>
                                <textarea id="roadmap_purpose" name="purpose" rows="3">{{ old('purpose') }}</textarea>
                            </div>
                            <div class="field">
                                <label for="roadmap_status">状態</label>
                                <select id="roadmap_status" name="status" required>
                                    @foreach ($roadmapStatuses as $value => $label)
                                        <option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="position_after_roadmap_id">表示位置</label>
                                <select id="position_after_roadmap_id" name="position_after_roadmap_id">
                                    <option value="">先頭に置く</option>
                                    @foreach ($roadmaps as $positionRoadmap)
                                        <option value="{{ $positionRoadmap->id }}" @selected((string) old('position_after_roadmap_id') === (string) $positionRoadmap->id)>
                                            「{{ $positionRoadmap->title }}」の後ろに置く
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="actions">
                                <button type="submit">ロードマップテーマを作成</button>
                            </div>
                        </form>
                    </div>

                    <div class="card stack">
                        <h2>未分類のImprovement</h2>
                        <p>Roadmapが必要になったタイミングで、既存のImprovementを後からテーマへ追加できます。</p>
                        @if ($unclassifiedImprovements->isEmpty())
                            <p>未分類のImprovementはありません。</p>
                        @else
                            @foreach ($unclassifiedImprovements as $unclassifiedImprovement)
                                <div style="border-top:1px solid var(--line); padding-top:12px;">
                                    <div class="meta">{{ $improvementStatuses[$unclassifiedImprovement->status] ?? $unclassifiedImprovement->status }}</div>
                                    <h2><a href="{{ route('projects.improvements.show', [$project, $unclassifiedImprovement]) }}">{{ $unclassifiedImprovement->title }}</a></h2>
                                    <p>{{ Str::limit($unclassifiedImprovement->next_action ?: $unclassifiedImprovement->problem ?: '詳細は改善ページで確認できます。', 120) }}</p>
                                    @if ($roadmaps->isNotEmpty())
                                        <form class="actions" method="POST" action="{{ route('projects.improvements.roadmap.assign', [$project, $unclassifiedImprovement]) }}">
                                            @csrf
                                            <select name="roadmap_id" style="max-width:260px;" required>
                                                @foreach ($roadmaps as $roadmap)
                                                    <option value="{{ $roadmap->id }}">{{ $roadmap->title }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit">テーマへ追加</button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        @endif
                    </div>
                @endif
            </section>

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


