@extends('layouts.app', ['title' => 'Projectを編集 - '.$project->name])

@section('content')
    <style>
        .future-field { padding:16px; border:1px solid #b7dbc9; border-radius:10px; background:linear-gradient(145deg,#f8fffb,#eaf7f0); }
        .future-field label::before { content:"✦"; margin-right:7px; color:#3d966d; }
        .ai-enabled-badge { display:inline-flex; margin-left:8px; padding:3px 7px; border-radius:999px; background:#e8f4f2; color:var(--accent-dark); font-size:11px; font-weight:800; vertical-align:middle; }
        .local-path-control { display:flex; gap:8px; align-items:stretch; }
        .local-path-control input { flex:1 1 auto; min-width:0; }
        .local-path-control button { flex:0 0 auto; }
    </style>
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
                <label for="summary">概要 <span class="ai-enabled-badge">AI連携対応</span></label>
                <textarea id="summary" name="summary" rows="5">{{ old('summary', $project->summary) }}</textarea>
                <div class="meta">このプロジェクトが依頼に至った経緯や背景を記録します。</div>
                @error('summary') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="current_state">現状 <span class="ai-enabled-badge">AI連携対応</span></label>
                <textarea id="current_state" name="current_state" rows="5">{{ old('current_state', $project->current_state) }}</textarea>
                <div class="meta">現在の業務、運用方法、困りごとや課題を記録します。</div>
                @error('current_state') <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field future-field">
                <label for="desired_future_state">目指す未来のカタチ <span class="ai-enabled-badge">AI連携対応</span></label>
                <textarea id="desired_future_state" name="desired_future_state" rows="5">{{ old('desired_future_state', $project->desired_future_state) }}</textarea>
                <div class="meta">プロジェクト完了後に実現したい状態を記録します。</div>
                @error('desired_future_state') <div class="error">{{ $message }}</div> @enderror
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
                    <label for="duration_days">期間（日）</label>
                    <input id="duration_days" name="duration_days" type="number" min="1" max="3650" value="{{ old('duration_days', $project->duration_days) }}" placeholder="例：29">
                    <div class="meta">休日を含む暦日です。開始日が未確定の場合は、この期間で計画します。</div>
                    @error('duration_days') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="start_date">開始日（決定後）</label>
                    <input id="start_date" name="start_date" type="date" value="{{ old('start_date', $project->start_date?->format('Y-m-d')) }}">
                    @error('start_date') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div></div>
            </div>

            <div class="actions">
                <button type="submit">更新する</button>
                <a href="{{ route('projects.show', $project) }}">キャンセル</a>
            </div>
        </form>
    </section>

    <section class="panel stack" style="margin-top:24px;">
        <div>
            <h2>このPCのローカルフォルダ</h2>
            <p>この設定は、同じProjectを使う他のメンバーとは共有されません。PCごと・利用者ごとに設定します。</p>
        </div>
        <form class="stack" method="POST" action="{{ route('projects.local-connection.store', $project) }}">
            @csrf
            <div class="stack" data-local-folder-setting data-handle-key="project-{{ $project->id }}-user-{{ auth()->id() }}">
                <div class="field">
                    <label>ローカルフォルダ</label>
                    <div class="local-path-control"><button class="secondary" type="button" data-folder-browse>BROWSE</button><span class="meta" data-folder-browse-status>{{ $localConnection ? '登録済みのフォルダを再選択できます。' : 'フォルダを選択してください。' }}</span></div>
                </div>
                <div class="field">
                    <label for="directory_name">表示名</label>
                    <input id="directory_name" name="directory_name" value="{{ old('directory_name', $localConnection?->directory_name) }}" placeholder="ローカルパスから自動取得" required data-directory-name>
                    <div class="meta">自動取得後も自由に変更できます。</div>
                    @error('directory_name') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="local_site_url">ローカルサイトURL</label>
                    <input id="local_site_url" name="local_site_url" type="url" value="{{ old('local_site_url', $localConnection?->local_site_url) }}" placeholder="http://localhost/prohit-okinawa/public_html/">
                    <div class="meta">XAMPPなどで表示できるURLを入力します。末尾の / は自動で補います。</div>
                    @error('local_site_url') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="meta">フォルダへのアクセス権はこのブラウザ内だけに保存され、サーバーや他の利用者には共有されません。Chrome・Edgeに対応しています。</div>
            <div class="actions">
                <button type="submit">ローカルフォルダ設定を保存</button>
                <span class="meta">状態：{{ $localConnection ? 'フォルダ登録済み' : '未設定' }}</span>
            </div>
        </form>
        @if($localConnection)
            <form method="POST" action="{{ route('projects.local-connection.destroy', $project) }}">
                @csrf @method('DELETE')
                <button class="secondary" type="submit">設定を解除</button>
            </form>
        @endif
    </section>
    <script>
        (() => {
            const setting = document.querySelector('[data-local-folder-setting]');
            const name = document.querySelector('[data-directory-name]');
            const siteUrl = document.querySelector('[name="local_site_url"]');
            const browse = document.querySelector('[data-folder-browse]');
            const status = document.querySelector('[data-folder-browse-status]');
            if (!setting || !name || !browse) return;

            const saveHandle = handle => new Promise((resolve, reject) => {
                const request = indexedDB.open('rise-gate-local-folders', 1);
                request.onupgradeneeded = () => request.result.createObjectStore('handles');
                request.onerror = () => reject(request.error);
                request.onsuccess = () => {
                    const transaction = request.result.transaction('handles', 'readwrite');
                    transaction.objectStore('handles').put(handle, setting.dataset.handleKey);
                    transaction.oncomplete = resolve;
                    transaction.onerror = () => reject(transaction.error);
                };
            });

            const applyFolderName = folderName => {
                if (!folderName) return;
                name.value = folderName;
                name.dataset.manuallyEdited = '';
                if (siteUrl && !siteUrl.value.trim()) siteUrl.value = `http://localhost/${encodeURIComponent(folderName)}/public_html/`;
            };
            name.addEventListener('input', () => { name.dataset.manuallyEdited = 'true'; });
            browse.addEventListener('click', async () => {
                if (!('showDirectoryPicker' in window)) { status.textContent = 'このブラウザは未対応です。ChromeまたはEdgeをご利用ください。'; return; }
                try {
                    const handle = await window.showDirectoryPicker({mode:'read', id:setting.dataset.handleKey});
                    await saveHandle(handle);
                    applyFolderName(handle.name);
                    status.textContent = `「${handle.name}」へのアクセス権を保存しました。`;
                } catch (error) {
                    if (error.name !== 'AbortError') status.textContent = 'フォルダを選択できませんでした。';
                }
            });
        })();
    </script>

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
