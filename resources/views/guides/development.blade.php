@extends('layouts.app', ['title' => '開発の進め方 - Rise Gate OS'])

@section('content')
    <section class="stack development-guide">
        <header class="guide-hero">
            <div>
                <div class="meta">RISE GATE DEVELOPMENT FLOW</div>
                <h1>新しい案件は、この順番で進めます。</h1>
                <p>難しいGit操作を覚えるためのページではありません。いま誰が何をするのか、デモサイトがいつ変わるのかを確認するための案内です。</p>
            </div>
            <div class="guide-hero__roles" aria-label="役割分担">
                <div><span>Codex</span><strong>制作して、GitHubへ保存</strong></div>
                <div><span>あなた</span><strong>確認して、サーバーへ反映</strong></div>
            </div>
        </header>

        <section class="panel stack">
            <div class="section-heading">
                <div>
                    <div class="meta">STANDARD FLOW</div>
                    <h2>いつもの6ステップ</h2>
                </div>
                <span class="rule-pill">Pushだけではサイトは変わりません</span>
            </div>

            <ol class="flow-list">
                <li class="flow-step">
                    <span class="step-number">1</span>
                    <div class="step-body">
                        <span class="step-owner owner-you">あなた</span>
                        <h3>案件と、やりたいことを伝える</h3>
                        <p>新規案件では「RISE GATEの新規案件として開始する」と伝えます。目的や確認日も一緒に決めます。</p>
                    </div>
                </li>
                <li class="flow-step">
                    <span class="step-number">2</span>
                    <div class="step-body">
                        <span class="step-owner owner-codex">Codex</span>
                        <h3>制作して、動作を確認する</h3>
                        <p>画面や機能を作り、エラーがないか確認します。関係のないファイルや、サーバーにある問い合わせデータは触りません。</p>
                    </div>
                </li>
                <li class="flow-step">
                    <span class="step-number">3</span>
                    <div class="step-body">
                        <span class="step-owner owner-codex">Codex</span>
                        <h3>GitHubへ保存する</h3>
                        <p>完成した変更をCommitしてPushします。ここはCodexが行います。この時点ではデモサイトも本番サイトも変わりません。</p>
                    </div>
                </li>
                <li class="flow-step emphasis-step">
                    <span class="step-number">4</span>
                    <div class="step-body">
                        <span class="step-owner owner-you">あなた</span>
                        <h3>見せたい時だけ、手動でデプロイする</h3>
                        <p>GitHubのActionsから「デモへデプロイ」または「本番へデプロイ」を選び、Run workflowを押します。</p>
                        <div class="simple-path" aria-label="デプロイ操作">
                            <span>GitHub</span><b>→</b><span>Actions</span><b>→</b><span>デプロイを選択</span><b>→</b><span>Run workflow</span>
                        </div>
                    </div>
                </li>
                <li class="flow-step">
                    <span class="step-number">5</span>
                    <div class="step-body">
                        <span class="step-owner owner-you">あなた</span>
                        <h3>デモを確認して、クライアントに見せる</h3>
                        <p>主要な画面やフォームを確認してから案内します。クライアント確認後は、デモをその状態で固定します。</p>
                    </div>
                </li>
                <li class="flow-step">
                    <span class="step-number">6</span>
                    <div class="step-body">
                        <span class="step-owner owner-both">一緒に</span>
                        <h3>裏側で磨いて、完成版をまとめて見せる</h3>
                        <p>Codexはローカルでブラッシュアップし、GitHubへの保存を続けます。作業途中はデプロイせず、完成した時にあなたがもう一度手動で反映します。</p>
                    </div>
                </li>
            </ol>
        </section>

        <div class="guide-columns">
            <section class="card stack safe-card">
                <div class="meta">覚えるのはここだけ</div>
                <h2>3つの状態は、意味が違います</h2>
                <dl class="status-list">
                    <div><dt>GitHubへPush済み</dt><dd>変更を安全に保存しました。サイトはまだ変わっていません。</dd></div>
                    <div><dt>デプロイ準備済み</dt><dd>いつでも手動反映できます。サイトはまだ変わっていません。</dd></div>
                    <div><dt>デプロイ完了</dt><dd>サーバーへ反映され、サイトが新しくなりました。</dd></div>
                </dl>
            </section>

            <aside class="card stack timing-card">
                <div class="meta">クライアント確認日の考え方</div>
                <h2>見せた後は、いったん止める</h2>
                <div class="timing-line">
                    <span>確認前<br><strong>必要に応じて更新</strong></span>
                    <b>→</b>
                    <span class="timing-stop">確認後<br><strong>デモを固定</strong></span>
                    <b>→</b>
                    <span>完成時<br><strong>まとめて更新</strong></span>
                </div>
                <p>作業途中の変化をお客様に見せず、最後に完成版を「ドーン」と提示するための運用です。</p>
            </aside>
        </div>

        <section class="start-callout">
            <div>
                <div class="meta">新しい案件を始める時の合言葉</div>
                <strong>「RISE GATEの新規案件として開始する。標準の流れでセットアップして」</strong>
            </div>
            <a class="button" href="{{ route('projects.create') }}">Projectを作成する</a>
        </section>
    </section>

    <style>
        .development-guide h3 { margin: 0; font-size: 19px; }
        .guide-hero { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(300px, .8fr); gap: 28px; align-items: center; padding: 38px; border-radius: 18px; color: #f4fbfc; background: linear-gradient(135deg, #0a2635, #0f4c5c); }
        .guide-hero h1 { margin-top: 8px; }
        .guide-hero p { margin-bottom: 0; color: #cde1e5; }
        .guide-hero .meta { color: #8fd0d4; }
        .guide-hero__roles { display: grid; gap: 10px; }
        .guide-hero__roles div { display: grid; gap: 3px; padding: 15px 17px; border: 1px solid rgba(255,255,255,.18); border-radius: 10px; background: rgba(255,255,255,.07); }
        .guide-hero__roles span { color: #92d2d5; font-size: 12px; font-weight: 800; letter-spacing: .08em; }
        .section-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; }
        .rule-pill { padding: 8px 12px; border-radius: 999px; color: #7a4b12; background: #fff4d8; font-size: 13px; font-weight: 800; }
        .flow-list { display: grid; gap: 0; margin: 0; padding: 0; list-style: none; counter-reset: guide-step; }
        .flow-step { position: relative; display: grid; grid-template-columns: 52px minmax(0, 1fr); gap: 18px; padding: 20px 0; border-top: 1px solid var(--line); }
        .flow-step::before { content: ''; position: absolute; left: 25px; top: 72px; bottom: -1px; width: 2px; background: #d5e5e8; }
        .flow-step:last-child::before { display: none; }
        .step-number { z-index: 1; display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 50%; color: #fff; background: var(--accent-dark); font-size: 20px; font-weight: 800; }
        .step-body { display: grid; gap: 8px; }
        .step-body p { margin: 0; }
        .step-owner { width: fit-content; padding: 4px 8px; border-radius: 999px; font-size: 11px; font-weight: 800; }
        .owner-you { color: #77500d; background: #fff0c6; }
        .owner-codex { color: var(--accent-dark); background: #e3f3f4; }
        .owner-both { color: #5b3f75; background: #f0e8f7; }
        .emphasis-step { margin: 6px 0; padding: 20px; border: 1px solid #a9d6cf; border-radius: 12px; background: #f2fbf9; }
        .emphasis-step::before { display: none; }
        .simple-path { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 5px; color: var(--accent-dark); font-size: 13px; }
        .simple-path span { padding: 7px 9px; border: 1px solid #bcd6d9; border-radius: 6px; background: #fff; font-weight: 700; }
        .guide-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .safe-card { border-color: #b7d7db; }
        .status-list { display: grid; gap: 0; margin: 0; }
        .status-list div { display: grid; gap: 4px; padding: 14px 0; border-top: 1px solid var(--line); }
        .status-list div:first-child { border-top: 0; }
        .status-list dt { font-weight: 800; color: var(--accent-dark); }
        .status-list dd { margin: 0; color: var(--muted); line-height: 1.65; }
        .timing-line { display: flex; align-items: center; gap: 8px; }
        .timing-line span { flex: 1; padding: 12px 8px; border-radius: 8px; text-align: center; background: #eef3f5; color: var(--muted); font-size: 12px; }
        .timing-line strong { color: var(--ink); }
        .timing-line .timing-stop { background: #fff0c6; }
        .timing-card p { margin-bottom: 0; }
        .start-callout { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 24px 28px; border: 1px solid var(--line); border-radius: 12px; background: #fff; }
        .start-callout strong { display: block; margin-top: 6px; font-size: 18px; }
        @media (max-width: 850px) {
            .guide-hero, .guide-columns { grid-template-columns: 1fr; }
            .section-heading, .start-callout { align-items: flex-start; flex-direction: column; }
        }
        @media (max-width: 560px) {
            .guide-hero { padding: 26px 22px; }
            .flow-step { grid-template-columns: 42px minmax(0, 1fr); gap: 12px; }
            .step-number { width: 42px; height: 42px; }
            .flow-step::before { left: 20px; top: 62px; }
            .emphasis-step { padding: 16px; }
            .timing-line { align-items: stretch; flex-direction: column; }
            .timing-line > b { transform: rotate(90deg); text-align: center; }
        }
    </style>
@endsection
