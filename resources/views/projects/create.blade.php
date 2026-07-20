@extends('layouts.app', ['title' => 'Create Project - Rise Gate OS'])

@section('content')
    <style>
        .project-start-options { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
        .project-start-option { display:block; padding:16px; border:2px solid var(--line); border-radius:10px; background:#fff; cursor:pointer; }
        .project-start-option:has(input:checked) { border-color:var(--accent-dark); background:#f3f8fa; }
        .project-start-option input { width:auto; margin:0 8px 0 0; }
        .project-start-option strong { font-size:17px; }
        .project-start-option span { display:block; margin:8px 0 0 26px; color:var(--muted); }
        .future-field { padding:16px; border:1px solid #b7dbc9; border-radius:10px; background:linear-gradient(145deg,#f8fffb,#eaf7f0); }
        .future-field label::before { content:"✦"; margin-right:7px; color:#3d966d; }
        @media (max-width:700px) { .project-start-options { grid-template-columns:1fr; } }
    </style>
    <section class="panel stack">
        <div>
            <h1>Create Project</h1>
            <p>Projectは、クライアントの改善を実行し、進捗を共有する中心単位です。</p>
        </div>

        <form class="stack" method="POST" action="{{ route('projects.store') }}">
            @csrf

            <div class="field">
                <label for="client_id">クライアント</label>
                <select id="client_id" name="client_id" required>
                    <option value="">クライアントを選択</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) old('client_id', $selectedClientId) === (string) $client->id)>{{ $client->name }}</option>
                    @endforeach
                </select>
                @error('client_id') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="name">Project name</label>
                <input id="name" name="name" value="{{ old('name') }}" required autofocus>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="code">Project code</label>
                <input id="code" name="code" value="{{ old('code') }}" placeholder="例: RG-001">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="summary">概要</label>
                <textarea id="summary" name="summary" rows="5">{{ old('summary') }}</textarea>
                <div class="meta">このプロジェクトが依頼に至った経緯や背景を記録します。</div>
                @error('summary') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="current_state">現状</label>
                <textarea id="current_state" name="current_state" rows="5">{{ old('current_state') }}</textarea>
                <div class="meta">現在の業務、運用方法、困りごとや課題を記録します。</div>
                @error('current_state') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field future-field">
                <label for="desired_future_state">目指す未来のカタチ</label>
                <textarea id="desired_future_state" name="desired_future_state" rows="5">{{ old('desired_future_state') }}</textarea>
                <div class="meta">プロジェクト完了後に実現したい状態を記録します。</div>
                @error('desired_future_state') <div class="error">{{ $message }}</div> @enderror
            </div>

            <fieldset class="field">
                <legend>Projectの始め方</legend>
                <div class="project-start-options">
                    <label class="project-start-option">
                        <input type="radio" name="starter_mode" value="blank" @checked(old('starter_mode', 'blank') === 'blank')>
                        <strong>自分で設計する・AIと作る</strong>
                        <span>Projectだけを作成します。ロードマップ・取り組み・タスクは、自分またはAIと一緒に組み立てます。</span>
                    </label>
                    <label class="project-start-option">
                        <input type="radio" name="starter_mode" value="starter" @checked(old('starter_mode') === 'starter')>
                        <strong>タスクからこつこつ始める</strong>
                        <span>「プロジェクトを前に進める」と「進めるための具体的な動き」を最初から用意します。</span>
                    </label>
                </div>
                @error('starter_mode') <div class="error">{{ $message }}</div> @enderror
            </fieldset>

            <div class="grid">
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', 'draft') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        @foreach ($priorities as $value => $label)
                            <option value="{{ $value }}" @selected(old('priority', 'normal') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('priority') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div></div>
            </div>

            <div class="grid">
                <div class="field">
                    <label for="start_date">Start date</label>
                    <input id="start_date" name="start_date" type="date" value="{{ old('start_date') }}">
                    @error('start_date') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="due_date">Due date</label>
                    <input id="due_date" name="due_date" type="date" value="{{ old('due_date') }}">
                    @error('due_date') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div></div>
            </div>

            <div class="actions">
                <button type="submit">Create Project</button>
                <a href="{{ route('projects.index') }}">Cancel</a>
            </div>
        </form>
    </section>
@endsection
