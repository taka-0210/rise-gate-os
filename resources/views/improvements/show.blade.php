@extends('layouts.app', ['title' => $improvement->title.' - 改善'])

@section('content')
    <section class="stack">
        <div class="actions" style="justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="meta">改善 / {{ $statuses[$improvement->status] ?? $improvement->status }}</div>
                <h1>{{ $improvement->title }}</h1>
                <p>Project: <a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></p>
            </div>
            @if ($canEditImprovement)<a class="button" href="{{ route('projects.improvements.edit', [$project, $improvement]) }}">改善を編集</a>@endif<a class="button secondary" href="{{ route('projects.improvements.index', $project) }}">改善一覧へ戻る</a>
        </div>

        <div class="grid">
            <div class="card">
                <h2>進行状況</h2>
                <p>{{ $statuses[$improvement->status] ?? $improvement->status }}</p>
            </div>
            <div class="card">
                <h2>公開範囲</h2>
                <p>{{ $visibilities[$improvement->visibility] ?? $improvement->visibility }}</p>
            </div>
            <div class="card">
                <h2>提案者</h2>
                <p>{{ $improvement->proposer?->name ?? '未設定' }}</p>
            </div>
        </div>

        <div class="grid">
            <div class="card">
                <h2>担当者</h2>
                <p>{{ $improvement->assignee?->name ?? '未設定' }}</p>
            </div>
            <div class="card">
                <h2>実施者</h2>
                <p>{{ $improvement->implementer?->name ?? '未実施' }}</p>
            </div>
            <div class="card">
                <h2>実施日</h2>
                <p>{{ $improvement->implemented_at?->format('Y-m-d H:i') ?? '未設定' }}</p>
            </div>
        </div>

        <div class="panel stack">
            @foreach ([
                'current_state' => '現状',
                'desired_state' => '理想の状態',
                'problem' => '問題',
                'hypothesis' => '仮説',
                'action' => '実施内容',
                'result' => '結果',
                'impact' => '影響',
                'next_action' => '次の行動',
            ] as $field => $label)
                <section class="stack" style="gap: 8px;">
                    <h2>{{ $label }}</h2>
                    <p>{{ $improvement->{$field} ?: 'まだ記録されていません。' }}</p>
                </section>
            @endforeach
        </div>
        <div class="panel stack">
            <div>
                <div class="meta">Improvement Outputs</div>
                <h2>この改善から生まれたもの</h2>
                <p>Improvementは記録して終わりではありません。Taskや新しいProjectを生み出し、会社の未来へつながります。</p>
            </div>

            <div class="grid">
                <div class="card stack">
                    <h2>生まれたTask</h2>
                    @forelse ($taskOutputs as $task)
                        <div style="border-top:1px solid var(--line); padding-top:12px;">
                            <div class="meta">{{ $taskStatuses[$task->status] ?? $task->status }} / {{ $taskPriorities[$task->priority] ?? $task->priority }}</div>
                            <h2>{{ $task->title }}</h2>
                            <p>{{ Str::limit($task->description ?: '詳細はまだありません。', 120) }}</p>
                            <p class="meta">担当者: {{ $task->assignee?->name ?? '未設定' }} @if ($task->due_date) / 期限: {{ $task->due_date->format('Y-m-d') }} @endif</p>
                        </div>
                    @empty
                        <p>まだTaskは生まれていません。</p>
                    @endforelse
                </div>

                <div class="card stack">
                    <h2>生まれたProject</h2>
                    @forelse ($projectOutputs as $outputProject)
                        <div style="border-top:1px solid var(--line); padding-top:12px;">
                            <div class="meta">{{ $projectStatuses[$outputProject->status] ?? $outputProject->status }} / {{ $projectPriorities[$outputProject->priority] ?? $outputProject->priority }}</div>
                            <h2><a href="{{ route('projects.show', $outputProject) }}">{{ $outputProject->name }}</a></h2>
                            <p>{{ Str::limit($outputProject->summary ?: 'この改善から新しいProjectが生まれました。', 120) }}</p>
                        </div>
                    @empty
                        <p>まだProjectは生まれていません。</p>
                    @endforelse
                </div>
            </div>

            <div class="grid">
                @if ($canCreateTaskOutput)
                    <form class="card stack" method="POST" action="{{ route('projects.improvements.outputs.tasks.store', [$project, $improvement]) }}">
                        @csrf
                        <h2>Taskを生み出す</h2>
                        <p>このImprovementを実現するための具体的な作業を登録します。</p>
                        <div class="field">
                            <label for="output_task_title">Task名</label>
                            <input id="output_task_title" name="title" value="{{ old('title') }}" required>
                        </div>
                        <div class="field">
                            <label for="output_task_description">説明</label>
                            <textarea id="output_task_description" name="description" rows="3">{{ old('description') }}</textarea>
                        </div>
                        <div class="field">
                            <label for="output_task_status">状態</label>
                            <select id="output_task_status" name="status" required>
                                @foreach ($taskStatuses as $value => $label)
                                    <option value="{{ $value }}" @selected(old('status', 'todo') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="output_task_priority">優先度</label>
                            <select id="output_task_priority" name="priority" required>
                                @foreach ($taskPriorities as $value => $label)
                                    <option value="{{ $value }}" @selected(old('priority', 'normal') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="output_task_assigned_to">担当者</label>
                            <select id="output_task_assigned_to" name="assigned_to">
                                <option value="">未設定</option>
                                @foreach ($assignableUsers as $user)
                                    <option value="{{ $user->id }}" @selected((string) old('assigned_to') === (string) $user->id)>{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="output_task_planned_start_date">開始予定日</label>
                            <input id="output_task_planned_start_date" name="planned_start_date" type="date" value="{{ old('planned_start_date') }}">
                        </div>
                        <div class="field">
                            <label for="output_task_due_date">終了予定日</label>
                            <input id="output_task_due_date" name="due_date" type="date" value="{{ old('due_date') }}">
                        </div>
                        <div class="actions">
                            <button type="submit">Taskを登録</button>
                        </div>
                    </form>
                @endif

                @if ($canCreateProjectOutput)
                    <form class="card stack" method="POST" action="{{ route('projects.improvements.outputs.projects.store', [$project, $improvement]) }}">
                        @csrf
                        <h2>新しいProjectを生み出す</h2>
                        <p>このImprovementが大きな取り組みへ育つ場合、新しいProjectとして切り出します。</p>
                        <div class="field">
                            <label for="output_project_name">Project名</label>
                            <input id="output_project_name" name="name" value="{{ old('name') }}" required>
                        </div>
                        <div class="field">
                            <label for="output_project_summary">概要</label>
                            <textarea id="output_project_summary" name="summary" rows="3">{{ old('summary') }}</textarea>
                        </div>
                        <div class="field">
                            <label for="output_project_status">状態</label>
                            <select id="output_project_status" name="status" required>
                                @foreach ($projectStatuses as $value => $label)
                                    <option value="{{ $value }}" @selected(old('status', 'draft') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="output_project_priority">優先度</label>
                            <select id="output_project_priority" name="priority" required>
                                @foreach ($projectPriorities as $value => $label)
                                    <option value="{{ $value }}" @selected(old('priority', 'normal') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="output_project_due_date">期限</label>
                            <input id="output_project_due_date" name="due_date" type="date" value="{{ old('due_date') }}">
                        </div>
                        <div class="actions">
                            <button type="submit">Projectを作成</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </section>
@endsection

