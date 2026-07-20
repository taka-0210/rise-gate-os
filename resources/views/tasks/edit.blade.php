@extends('layouts.app', ['title' => 'Taskを編集 - '.$task->title])

@section('content')
    <section class="panel stack">
        <div>
            <div class="meta">Project / {{ $project->name }}</div>
            <h1>Taskを編集</h1>
            <p>作業内容・進行状況・担当者・期限を更新します。</p>
        </div>

        <form class="stack" method="POST" action="{{ route('projects.tasks.update', [$project, $task]) }}">
            @csrf
            @method('PUT')

            @if(!$project->start_date && $project->duration_days)
            <div class="field"><label for="planned_start_day">開始（着手後・日目）</label><input id="planned_start_day" name="planned_start_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('planned_start_day', $task->planned_start_day) }}">@error('planned_start_day') <div class="error">{{ $message }}</div> @enderror</div>
            <div class="field"><label for="due_day">完了（着手後・日目）</label><input id="due_day" name="due_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('due_day', $task->due_day) }}">@error('due_day') <div class="error">{{ $message }}</div> @enderror</div>
            @else
            <div class="field">
                <label for="improvement_id">所属する取り組み</label>
                <select id="improvement_id" name="improvement_id" required>
                    @foreach ($initiatives as $initiative)
                        <option value="{{ $initiative->id }}" @selected((string) old('improvement_id', $task->improvement_id) === (string) $initiative->id)>
                            {{ $initiative->roadmap?->title }} / {{ $initiative->title }}
                        </option>
                    @endforeach
                </select>
                @error('improvement_id') <div class="error">{{ $message }}</div> @enderror
            </div>
            @endif

            <div class="field">
                <label for="title">Task名</label>
                <input id="title" name="title" value="{{ old('title', $task->title) }}" required autofocus>
                @error('title') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="description">説明</label>
                <textarea id="description" name="description" rows="5">{{ old('description', $task->description) }}</textarea>
                @error('description') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="grid">
                <div class="field">
                    <label for="status">進行状況</label>
                    <select id="status" name="status" required>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $task->status) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="priority">優先度</label>
                    <select id="priority" name="priority" required>
                        @foreach ($priorities as $value => $label)
                            <option value="{{ $value }}" @selected(old('priority', $task->priority) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('priority') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="assigned_to">担当者</label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">未設定</option>
                        @foreach ($assignableUsers as $user)
                            <option value="{{ $user->id }}" @selected((string) old('assigned_to', $task->assigned_to) === (string) $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    @error('assigned_to') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="field" style="max-width: 320px;">
                <label for="planned_start_date">開始予定日</label>
                <input id="planned_start_date" name="planned_start_date" type="date" value="{{ old('planned_start_date', $task->planned_start_date?->format('Y-m-d')) }}">
                @error('planned_start_date') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field" style="max-width: 320px;">
                <label for="due_date">終了予定日</label>
                <input id="due_date" name="due_date" type="date" value="{{ old('due_date', $task->due_date?->format('Y-m-d')) }}">
                @error('due_date') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="actions">
                <button type="submit">更新する</button>
                <a href="{{ route('projects.tasks.show', [$project, $task]) }}">キャンセル</a>
            </div>
        </form>
    </section>
@endsection
