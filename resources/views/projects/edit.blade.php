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
                <label for="client_id">クライアント</label>
                <select id="client_id" name="client_id" required>
                    <option value="">クライアントを選択</option>
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

    @if ($canMoveProject)
        <section class="panel stack" style="margin-top:24px;">
            <div>
                <h2>Projectを別のWorkspaceへ移動</h2>
                <p>Projectと配下の改善・タスク・ロードマップを一括で移動します。移動先Workspaceのクライアントも選択してください。</p>
            </div>
            @if ($movableWorkspaces->isEmpty())
                <p class="meta">移動できるWorkspaceがありません。移動先ではOwnerまたはAdmin権限が必要です。</p>
            @else
                <form class="actions" method="POST" action="{{ route('projects.move', $project) }}">
                    @csrf
                    <select id="destination_workspace_id" style="width:auto" name="destination_workspace_id" required>
                        <option value="">移動先Workspaceを選択</option>
                        @foreach ($movableWorkspaces as $workspace)
                            <option value="{{ $workspace->id }}">{{ $workspace->organization->name }} / {{ $workspace->name }}（{{ $workspace->pivot->role }}）</option>
                        @endforeach
                    </select>
                    <select id="destination_client_id" style="width:auto" name="destination_client_id" required disabled>
                        <option value="">移動先クライアントを選択</option>
                        @foreach ($movableWorkspaces as $workspace)
                            @foreach ($workspace->clients as $client)
                                <option value="{{ $client->id }}" data-workspace-id="{{ $workspace->id }}">{{ $workspace->name }} / {{ $client->name }}</option>
                            @endforeach
                        @endforeach
                    </select>
                    <button type="submit">Projectを移動</button>
                </form>
            @endif
        </section>
        <script>
            (() => {
                const workspaceSelect = document.getElementById('destination_workspace_id');
                const clientSelect = document.getElementById('destination_client_id');
                if (!workspaceSelect || !clientSelect) return;

                const updateClients = () => {
                    const workspaceId = workspaceSelect.value;
                    let available = false;
                    clientSelect.querySelectorAll('option[data-workspace-id]').forEach((option) => {
                        const visible = option.dataset.workspaceId === workspaceId;
                        option.hidden = !visible;
                        option.disabled = !visible;
                        if (visible) available = true;
                    });
                    clientSelect.value = '';
                    clientSelect.disabled = !workspaceId || !available;
                };

                workspaceSelect.addEventListener('change', updateClients);
                updateClients();
            })();
        </script>
    @endif

    @can('delete', $project)
        <section class="panel stack" style="margin-top:24px; border-color:#e1bcbc;">
            <div>
                <h2>Projectを削除</h2>
                <p>通常の一覧からProjectを非表示にします。改善・タスク・ロードマップの履歴は保持されます。確認のため、ログインパスワードを入力してください。</p>
            </div>
            <form class="stack" method="POST" action="{{ route('projects.destroy', $project) }}">
                @csrf @method('DELETE')
                <div class="field">
                    <label for="delete_password">削除パスワード</label>
                    <input id="delete_password" name="delete_password" type="password" required autocomplete="current-password">
                    @error('delete_password') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div><button class="danger" type="submit">Projectを削除</button></div>
            </form>
        </section>
    @endcan
@endsection
