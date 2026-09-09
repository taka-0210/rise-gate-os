@can('update', $project)
<section class="document-card" style="margin:10px;padding:12px" data-development-controls>
<strong>ローカルで開発</strong>
<p><a href="{{ route('development.setup') }}" target="_blank" rel="noopener">初回セットアップ</a> / <a href="risegate-dev://launch">開発用ツールを起動</a></p>
<button type="button" data-dev-action="connect">開発用ツールに接続</button>
<div data-dev-connected hidden>
<p data-dev-folder></p>
<button type="button" data-dev-action="select">保存フォルダを選択</button>
<button type="button" data-dev-action="seed">TODOのひな形</button>
<button type="button" data-dev-action="start">起動</button>
<button type="button" data-dev-action="stop">停止</button>
<button type="button" data-dev-action="logs">エラーを確認</button>
<button type="button" data-dev-action="export">公開用ZIP</button>
<button type="button" data-dev-action="disconnect">接続を解除</button>
<a data-dev-open hidden target="_blank" rel="noopener noreferrer">別タブで開く</a>
</div>
<p data-dev-status role="status">PHP・DBを使うアプリを、このPCで作成・確認できます。</p>
<details data-dev-logs-panel hidden><summary>実行ログ（JST）</summary><pre data-dev-logs style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:200px;overflow:auto"></pre><button type="button" data-dev-action="ask">このエラーの修正をAIに依頼</button></details>
<dialog data-dev-dialog>
<form method="dialog" data-dev-form>
<h3 data-dev-title>開発用ツールに接続</h3>
<label data-dev-code-label>起動時の画面に表示された接続コード<input name="code" type="password" autocomplete="off" maxlength="64"></label>
<div data-dev-admin hidden><p>新しいTODOの管理者を設定します。既存ファイルやDBがあるフォルダでは作成しません。</p><label>管理者ID<input name="login" autocomplete="off" pattern="[a-zA-Z0-9_-]{3,40}"></label><label>パスワード（8文字以上）<input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="128"></label></div>
<p data-dev-dialog-error role="alert"></p>
<button value="cancel" formnovalidate>キャンセル</button><button value="save">決定</button>
</form>
</dialog>
</section>
@endcan
