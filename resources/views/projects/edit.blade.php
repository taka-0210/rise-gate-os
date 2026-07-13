@extends('layouts.app', ['title' => 'Projectを編集 - '.$project->name])

@section('content')
    <section class="panel stack">
        <div>
            <h1>Projectを編集</h1>
            <p>Projectの概要、Company、期限など、運用中に変わる情報を更新します。</p>
        </div>

        <form class="stack" method="POST" action="{{ route('projects.update', $project) }}">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="client_id">Company</label>
                <select id="client_id" name="client_id">
                    <option value="">社内Project</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) old('client_id', $project->client_id) === (string) $client->id)>{{ $client->name }}</option>
                    @endforeach
                </select>
                @error('client_id') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="name">Project名</label>
                <input id="name" name="name" value="{{ old('name', $project->name) }}" required autofocus>
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="code">Projectコード</label>
                <input id="code" name="code" value="{{ old('code', $project->code) }}">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="summary">概要</label>
                <textarea id="summary" name="summary" rows="5">{{ old('summary', $project->summary) }}</textarea>
                @error('summary') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="grid">
                <div class="field">
                    <label for="status">進行状況</label>
                    <select id="status" name="status">
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $project->status) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="priority">優先度</label>
                    <select id="priority" name="priority">
                        @foreach ($priorities as $value => $label)
                            <option value="{{ $value }}" @selected(old('priority', $project->priority) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('priority') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div></div>
            </div>

            <div class="grid">
                <div class="field">
                    <label for="start_date">開始日</label>
                    <input id="start_date" name="start_date" type="date" value="{{ old('start_date', $project->start_date?->format('Y-m-d')) }}">
                    @error('start_date') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="due_date">期限</label>
                    <input id="due_date" name="due_date" type="date" value="{{ old('due_date', $project->due_date?->format('Y-m-d')) }}">
                    @error('due_date') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div></div>
            </div>

            <div class="actions">
                <button type="submit">更新する</button>
                <a href="{{ route('projects.show', $project) }}">キャンセル</a>
            </div>
        </form>
    </section>
@endsection
