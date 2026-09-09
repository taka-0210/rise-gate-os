@can('update', $project)
<section class="ai-body" data-codex-panel hidden style="display:none">
    <div class="ai-context"><strong>開発担当 · Codex</strong><p>選択フォルダで調査・編集・実行します。会話履歴はこのPCに保存されます。</p></div>
    <button type="button" class="secondary" data-codex-back>会話・資料作成に戻る</button>
    <p role="status" data-codex-status>開発ツールで保存フォルダを選択してください。</p>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        <button type="button" data-codex-connect>Codexに接続</button>
        <button type="button" class="secondary" data-codex-stop disabled>作業を停止</button>
    </div>
    <div data-codex-auth hidden>
        <p>Codexの利用には別途認証が必要です。OSのAIポイントには合算されません。</p>
        <button type="button" data-codex-login>ChatGPTでログイン</button>
        <a data-codex-login-link hidden target="_blank" rel="noopener noreferrer">ログイン画面を開く</a>
        <details><summary>OpenAI APIキーで接続</summary>
            <form data-codex-key-form><label>APIキー<input type="password" name="apiKey" autocomplete="off" required maxlength="1024"></label><button type="submit">接続</button></form>
            <small>キーはこのPCのCodexへ渡します。OSサーバーや会話本文へは送りません。</small>
        </details>
    </div>
    <p class="ai-chat-error" role="alert" data-codex-error hidden></p>
    <section class="ai-chat-messages" data-codex-history aria-label="Codexとの会話" style="max-height:48vh;overflow:auto;overflow-wrap:anywhere"></section>
    <div class="codex-activity" data-codex-activity>
        <strong role="status" data-codex-activity-label>Codexへの接続待ち</strong>
        <small data-codex-activity-detail></small>
    </div>
    <div data-codex-approvals></div>
    <form data-codex-form>
        <label>開発の依頼<textarea name="prompt" rows="4" maxlength="16000" required placeholder="このフォルダにシンプルなTODOアプリを作って…"></textarea></label>
        <button type="submit" disabled>Codexに送信</button>
    </form>
    <small>Codexの利用枠・料金は、このPCのCodex認証に従います（OSのAIポイントとは別です）。</small>
    <small>ファイルはCodexが直接編集します。操作の承認を求められた場合は内容を確認してください。公開は自動では行いません。</small>
</section>
@endcan