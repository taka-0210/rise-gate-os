@extends('layouts.app', ['title' => 'AI設定 | RISE GATE OS'])

@section('content')
    <section class="panel stack">
        <div>
            <div class="eyebrow">WORKSPACE AI・利用同意と管理</div>
            <h1>AI設定</h1>
            <p>現在のWorkspaceで、外部AIとの連携を利用するか管理します。</p>
        </div>

        <div class="card stack {{ $setting->enabled ? 'origin-panel' : '' }}">
            <div>
                <span class="badge">{{ $setting->enabled ? 'AI機能 有効' : 'AI機能 無効' }}</span>
                <h2>{{ $currentWorkspace->name }}</h2>
                @if ($setting->enabled)
                    <p>有効化日時: {{ $setting->enabled_at?->format('Y-m-d H:i') }} / 同意バージョン: {{ $setting->terms_version }}</p>
                @else
                    <p>AI接続キーが存在していても、AIはこのWorkspaceの情報へアクセスできません。</p>
                @endif
            </div>
        </div>

        <div class="card stack">
            <div>
                <h2>AIへ送信される可能性がある情報</h2>
                <p>AIは、接続した本人が参加しているProjectのうち、作業に必要な情報だけを取得します。</p>
            </div>
            <ul style="line-height:2; color:var(--muted);">
                <li>Project名・概要・状態・優先度</li>
                <li>Roadmap、取り組み、Task</li>
                <li>担当、期限、進捗状況</li>
                <li>変更ファイルやテスト結果など、提案の根拠</li>
            </ul>
            <div>
                <h2>送信・保存しない情報</h2>
                <ul style="line-height:2; color:var(--muted);">
                    <li>パスワードや接続キーの平文</li>
                    <li>本人が参加していないProject</li>
                    <li>許可項目以外のデータ</li>
                </ul>
            </div>
            <p class="meta">AI事業者側でのデータ処理は、各メンバーが利用するAIサービスの契約と設定にも従います。</p>
        </div>

        @if ($canManage)
            <form class="card stack" method="POST" action="{{ route('ai-settings.update') }}">
                @csrf
                @method('PUT')
                @if (! $setting->enabled)
                    <input type="hidden" name="enabled" value="1">
                    <label style="display:flex; gap:10px; align-items:flex-start;">
                        <input style="width:auto; margin-top:5px;" type="checkbox" name="consent" value="1" required>
                        <span>上記の送信内容と、接続メンバーが契約する外部AIで情報が処理されることを確認し、WorkspaceのAI機能を有効にします。</span>
                    </label>
                    @error('consent')<div class="error">{{ $message }}</div>@enderror
                    <div class="actions"><button type="submit">同意してAI機能を有効にする</button></div>
                @else
                    <input type="hidden" name="enabled" value="0">
                    <p>停止すると、すべてのAI接続からこのWorkspaceへアクセスできなくなります。接続履歴と監査ログは残ります。</p>
                    <div class="actions"><button class="danger" type="submit">AI機能を停止する</button></div>
                @endif
            </form>
        @else
            <div class="card"><p>AI機能の有効化・停止は、Workspaceのオーナーまたは管理者が行います。</p></div>
        @endif
    </section>
@endsection
