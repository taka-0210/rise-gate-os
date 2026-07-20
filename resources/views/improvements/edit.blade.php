@extends('layouts.app', ['title' => '改善を編集 - '.$improvement->title])

@section('content')
    <section class="panel stack">
        <div>
            <div class="meta">Project / {{ $project->name }}</div>
            <h1>改善を編集</h1>
            <p>改善は提案して終わりではありません。実施、結果、影響、次の行動を追記して育てます。</p>
        </div>

        <form class="stack" method="POST" action="{{ route('projects.improvements.update', [$project, $improvement]) }}">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="roadmap_id">所属Roadmap</label>
                <select id="roadmap_id" name="roadmap_id" required>
                    @foreach ($roadmaps as $roadmap)
                        <option value="{{ $roadmap->id }}" @selected((string) old('roadmap_id', $improvement->roadmap_id) === (string) $roadmap->id)>{{ $roadmap->title }}</option>
                    @endforeach
                </select>
                @error('roadmap_id') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="title">改善タイトル</label>
                <input id="title" name="title" value="{{ old('title', $improvement->title) }}" required autofocus>
                @error('title') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="grid">
                <div class="field">
                    <label for="status">進行状況</label>
                    <select id="status" name="status">
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $improvement->status) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="visibility">公開範囲</label>
                    <select id="visibility" name="visibility">
                        @foreach ($visibilities as $value => $label)
                            <option value="{{ $value }}" @selected(old('visibility', $improvement->visibility) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('visibility') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="assigned_to">担当者</label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">未設定</option>
                        @foreach ($assignableUsers as $user)
                            <option value="{{ $user->id }}" @selected((string) old('assigned_to', $improvement->assigned_to) === (string) $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    @error('assigned_to') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid">
                @if(!$project->start_date && $project->duration_days)
                    <div class="field"><label for="planned_start_day">開始（着手後・日目）</label><input id="planned_start_day" name="planned_start_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('planned_start_day', $improvement->planned_start_day) }}">@error('planned_start_day') <div class="error">{{ $message }}</div> @enderror</div>
                    <div class="field"><label for="target_day">完了（着手後・日目）</label><input id="target_day" name="target_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('target_day', $improvement->target_day) }}">@error('target_day') <div class="error">{{ $message }}</div> @enderror</div>
                @else
                    <div class="field"><label for="planned_start_date">開始予定日</label><input id="planned_start_date" name="planned_start_date" type="date" value="{{ old('planned_start_date', $improvement->planned_start_date?->format('Y-m-d')) }}">@error('planned_start_date') <div class="error">{{ $message }}</div> @enderror</div>
                    <div class="field"><label for="target_date">完了予定日</label><input id="target_date" name="target_date" type="date" value="{{ old('target_date', $improvement->target_date?->format('Y-m-d')) }}">@error('target_date') <div class="error">{{ $message }}</div> @enderror</div>
                @endif
                <div class="field"><label for="completed_at">実際の完了日</label><input id="completed_at" name="completed_at" type="date" value="{{ old('completed_at', $improvement->completed_at?->format('Y-m-d')) }}">@error('completed_at') <div class="error">{{ $message }}</div> @enderror</div>
            </div>

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
                <div class="field">
                    <label for="{{ $field }}">{{ $label }}</label>
                    <textarea id="{{ $field }}" name="{{ $field }}" rows="4">{{ old($field, $improvement->{$field}) }}</textarea>
                    @error($field) <div class="error">{{ $message }}</div> @enderror
                </div>
            @endforeach

            <div class="grid">
                <div class="field">
                    <label for="implemented_by">実施者</label>
                    <select id="implemented_by" name="implemented_by">
                        <option value="">未実施</option>
                        @foreach ($assignableUsers as $user)
                            <option value="{{ $user->id }}" @selected((string) old('implemented_by', $improvement->implemented_by) === (string) $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    @error('implemented_by') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="implemented_at">実施日</label>
                    <input id="implemented_at" name="implemented_at" type="datetime-local" value="{{ old('implemented_at', $improvement->implemented_at?->format('Y-m-d\\TH:i')) }}">
                    @error('implemented_at') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div></div>
            </div>

            <div class="actions">
                <button type="submit">更新する</button>
                <a href="{{ route('projects.improvements.show', [$project, $improvement]) }}">キャンセル</a>
            </div>
        </form>
    </section>
@endsection
