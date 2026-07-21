@extends('layouts.app', ['title' => '改善を登録 - '.$project->name])

@section('content')
    <section class="panel stack">
        <div>
            <div class="meta">Project / {{ $project->name }}</div>
            <h1>改善を登録</h1>
            <p>作業ではなく、改善そのものを記録します。現状から次の行動までを残すことで、知識として再利用できる形にします。</p>
        </div>

        <form class="stack" method="POST" action="{{ route('projects.improvements.store', $project) }}">
            @csrf

            <div class="field">
                <label for="roadmap_id">所属Roadmap</label>
                <select id="roadmap_id" name="roadmap_id" required>
                    <option value="">Roadmapを選択してください</option>
                    @foreach ($roadmaps as $roadmap)
                        <option value="{{ $roadmap->id }}" @selected((string) old('roadmap_id', $selectedRoadmapId) === (string) $roadmap->id)>{{ $roadmap->title }}</option>
                    @endforeach
                </select>
                @error('roadmap_id') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="title">改善タイトル</label>
                <input id="title" name="title" value="{{ old('title') }}" required autofocus>
                @error('title') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="grid">
                <div class="field"><label>進行状況</label><div class="meta">配下のタスクから自動計算されます。</div></div>
                <div class="field">
                    <label for="visibility">公開範囲</label>
                    <select id="visibility" name="visibility">
                        @foreach ($visibilities as $value => $label)
                            <option value="{{ $value }}" @selected(old('visibility', 'internal') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('visibility') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="assigned_to">担当者</label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">未設定</option>
                        @foreach ($assignableUsers as $user)
                            <option value="{{ $user->id }}" @selected((string) old('assigned_to') === (string) $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    @error('assigned_to') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid">
                @if(!$project->start_date && $project->duration_days)
                    <div class="field"><label for="planned_start_day">開始（着手後・日目）</label><input id="planned_start_day" name="planned_start_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('planned_start_day') }}">@error('planned_start_day') <div class="error">{{ $message }}</div> @enderror</div>
                    <div class="field"><label for="target_day">完了（着手後・日目）</label><input id="target_day" name="target_day" type="number" min="1" max="{{ $project->duration_days }}" value="{{ old('target_day') }}">@error('target_day') <div class="error">{{ $message }}</div> @enderror</div>
                @else
                    <div class="field"><label for="planned_start_date">開始予定日</label><input id="planned_start_date" name="planned_start_date" type="date" value="{{ old('planned_start_date') }}">@error('planned_start_date') <div class="error">{{ $message }}</div> @enderror</div>
                    <div class="field"><label for="target_date">完了予定日</label><input id="target_date" name="target_date" type="date" value="{{ old('target_date') }}">@error('target_date') <div class="error">{{ $message }}</div> @enderror</div>
                @endif
                <div class="field"><label for="completed_at">実際の完了日</label><input id="completed_at" name="completed_at" type="date" value="{{ old('completed_at') }}">@error('completed_at') <div class="error">{{ $message }}</div> @enderror</div>
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
                    <textarea id="{{ $field }}" name="{{ $field }}" rows="4">{{ old($field) }}</textarea>
                    @error($field) <div class="error">{{ $message }}</div> @enderror
                </div>
            @endforeach

            <div class="grid">
                <div class="field">
                    <label for="implemented_by">実施者</label>
                    <select id="implemented_by" name="implemented_by">
                        <option value="">未実施</option>
                        @foreach ($assignableUsers as $user)
                            <option value="{{ $user->id }}" @selected((string) old('implemented_by') === (string) $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    @error('implemented_by') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="implemented_at">実施日</label>
                    <input id="implemented_at" name="implemented_at" type="datetime-local" value="{{ old('implemented_at') }}">
                    @error('implemented_at') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div></div>
            </div>

            <div class="actions">
                <button type="submit">改善を登録</button>
                <a href="{{ route('projects.show', $project) }}#improvements">キャンセル</a>
            </div>
        </form>
    </section>
@endsection
