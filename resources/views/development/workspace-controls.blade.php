@can('update', $project)
<style>
.development-panel { margin:8px; padding:12px; border:1px solid #d6e1e6; border-radius:10px; background:#fff; font-size:12px; line-height:1.6; }
.development-panel > summary { cursor:pointer; font-size:12px; font-weight:600; color:#285360; }
.development-panel > summary:focus-visible { outline:2px solid #26879a; outline-offset:4px; border-radius:3px; }
.development-panel[open] > summary { margin-bottom:10px; }
.development-panel [data-dev-connection-label] { margin-left:6px; font-size:11px; font-weight:400; }
.development-panel p { margin:8px 0; }
.development-panel .dev-setup-links { display:flex; flex-wrap:wrap; gap:4px 12px; margin:3px 0 10px; font-size:11px; }
.development-panel button { box-sizing:border-box; min-height:32px; margin:0; padding:6px 9px; border:1px solid #cfdee3; border-radius:6px; background:#fff; color:#285360; font-family:inherit; font-size:12px; font-weight:500; line-height:1.4; cursor:pointer; }
.development-panel button:hover { background:#edf5f6; border-color:#8fb2bc; }
.development-panel button:focus-visible { outline:2px solid #26879a; outline-offset:2px; }
.development-panel button:disabled { opacity:.55; cursor:wait; }
.development-panel .dev-primary { background:#14586a; border-color:#14586a; color:#fff; }
.development-panel .dev-primary:hover { background:#104859; }
.development-panel .dev-connect { width:100%; }
.development-panel .dev-actions { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:6px; margin:8px 0; }
.development-panel .dev-actions button { min-width:0; overflow-wrap:anywhere; }
.development-panel .dev-run-actions { grid-template-columns:repeat(3,minmax(0,1fr)); }
.development-panel .dev-footer-actions { padding-top:8px; border-top:1px solid #e7eef1; }
.development-panel [data-dev-folder] { margin:10px 0 6px; padding:5px 8px; border-radius:5px; background:#f3f7f8; color:#476571; overflow-wrap:anywhere; }
.development-panel .dev-help { margin-top:10px; color:#617783; font-size:11px; line-height:1.7; }
.development-panel [data-dev-status] { margin:8px 0 0; color:#476571; font-size:11px; overflow-wrap:anywhere; }
.development-panel [data-dev-open] { font-size:12px; }
.development-panel dialog { max-width:calc(100vw - 40px); border:1px solid #c9d9df; border-radius:10px; padding:18px; }
.development-panel dialog h3 { margin:0 0 12px; font-size:14px; }
.development-panel dialog button + button { margin-left:6px; }
</style>
<details class="development-panel" data-development-controls>
<summary>開発ツール<span data-dev-connection-label></span></summary>
<p class="dev-setup-links"><a href="{{ route('development.setup') }}" target="_blank" rel="noopener">初回セットアップ</a><a href="risegate-dev://launch">開発用ツールを起動</a></p>
<button class="dev-connect" type="button" data-dev-action="connect">開発用ツールに接続</button>
<div data-dev-connected hidden>
<p data-dev-folder></p>
<div class="dev-actions">
<button type="button" data-dev-action="select">保存フォルダを選択</button>
<button class="dev-primary" type="button" data-dev-action="create">AIに作成を依頼</button>
</div><div class="dev-actions dev-run-actions">
<button type="button" data-dev-action="start">起動</button>
<button type="button" data-dev-action="stop">停止</button>
<button type="button" data-dev-action="logs" title="エラーや実行状況を確認">実行ログ</button>
</div><div class="dev-actions dev-footer-actions">
<button type="button" data-dev-action="export">公開用ZIP</button>
<button type="button" data-dev-action="disconnect">接続を解除</button>
</div>
<a data-dev-open hidden target="_blank" rel="noopener noreferrer">別タブで開く</a>
</div>
<p class="dev-help">AIに作りたいアプリやサイトを伝え、保存後に「起動」で確認できます。</p>
<p data-dev-status role="status">PHP・DBを使うアプリを、このPCで作成・確認できます。</p>
<details data-dev-logs-panel hidden><summary>実行ログ（JST）</summary><pre data-dev-logs style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:200px;overflow:auto"></pre></details>
<dialog data-dev-dialog>
<form method="dialog" data-dev-form>
<h3 data-dev-title>開発用ツールに接続</h3>
<div data-dev-code-label><label>起動時の画面に表示された接続コード（64文字）<input name="code" type="password" autocomplete="off" spellcheck="false" maxlength="128"></label><small data-dev-code-count>入力：0 / 64文字</small><label><input type="checkbox" data-dev-show-code> コードを表示する</label></div>
<p data-dev-dialog-progress role="status" aria-live="polite"></p>
<p data-dev-dialog-error role="alert"></p>
<button value="cancel" formnovalidate>キャンセル</button><button value="save">決定</button>
</form>
</dialog>
</details>
@endcan
