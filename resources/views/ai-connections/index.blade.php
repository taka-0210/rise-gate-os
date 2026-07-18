@extends('layouts.app', ['title' => 'AI接続 | RISE GATE OS'])

@section('content')
    <section class="panel stack">
        <div>
            <div class="eyebrow">AI CONNECTION・自分のAIをつなぐ</div>
            <h1>AI接続</h1>
            <p>自分のCodexから、参加中Projectの計画を読み取り、承認待ち提案を送れるようにします。</p>
        </div>

        @if (! $aiEnabled)
            <div class="card stack">
                <h2>先にAI機能の利用同意が必要です</h2>
                <p>Workspace管理者が送信内容を確認し、AI機能を有効にすると接続を発行できます。</p>
                <div class="actions"><a class="button" href="{{ route('ai-settings.edit') }}">AI設定を確認する</a></div>
            </div>
        @endif

        @if ($newToken)
            <div class="card origin-panel stack">
                <div>
                    <span class="badge">今回だけ表示</span>
                    <h2>Codex接続情報</h2>
                    <p>接続キーは再表示できません。Codexへの設定が終わるまで、この画面を閉じないでください。</p>
                </div>
                <div class="field">
                    <label>MCP URL</label>
                    <input value="{{ $mcpUrl }}" readonly onclick="this.select()">
                </div>
                <div class="field">
                    <label>接続キー</label>
                    <textarea rows="3" readonly onclick="this.select()">{{ $newToken }}</textarea>
                </div>
                <p class="meta">このキーはパスワードと同じ扱いです。チャット、メール、共有資料には貼り付けないでください。</p>
            </div>
        @endif

        @if ($aiEnabled)
        <div class="card stack">
            <div>
                <h2>新しいCodex接続を発行</h2>
                <p>接続は現在のWorkspaceとあなたのアカウントに限定されます。</p>
            </div>
            <form class="stack" method="POST" action="{{ route('ai-connections.store') }}">
                @csrf
                <div class="grid">
                    <div class="field">
                        <label for="connection_name">接続名</label>
                        <input id="connection_name" name="name" value="{{ old('name', '自分のCodex') }}" required>
                        @error('name')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="connection_days">有効期間</label>
                        <select id="connection_days" name="days">
                            <option value="30">30日</option>
                            <option value="90" selected>90日</option>
                            <option value="180">180日</option>
                        </select>
                    </div>
                </div>
                <div class="actions"><button type="submit">Codex接続を発行</button></div>
            </form>
        </div>
        @endif

        <div class="card stack">
            <div>
                <h2>自分の接続</h2>
                <p>使わなくなった接続や心当たりのない接続は停止してください。</p>
            </div>
            @forelse ($keys as $key)
                <article style="border-top:1px solid var(--line); padding-top:14px;">
                    <div class="actions" style="justify-content:space-between; align-items:flex-start;">
                        <div>
                            <h2>{{ $key->name }}</h2>
                            <p class="meta">
                                {{ $key->revoked_at ? '停止済み' : ($key->isUsable() ? '有効' : '期限切れ') }} /
                                有効期限 {{ $key->expires_at?->format('Y-m-d') }} /
                                最終利用 {{ $key->last_used_at?->format('Y-m-d H:i') ?? '未使用' }}
                            </p>
                        </div>
                        @if (! $key->revoked_at)
                            <form method="POST" action="{{ route('ai-connections.destroy', $key) }}">
                                @csrf
                                @method('DELETE')
                                <button class="danger" type="submit">停止</button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <p>まだAI接続はありません。</p>
            @endforelse
        </div>
    </section>
@endsection
