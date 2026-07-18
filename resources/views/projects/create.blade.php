@extends('layouts.app', ['title' => 'Create Project - Rise Gate OS'])

@section('content')
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
                <label for="summary">Summary</label>
                <textarea id="summary" name="summary" rows="5">{{ old('summary') }}</textarea>
                @error('summary') <div class="error">{{ $message }}</div> @enderror
            </div>

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
