# 3ペインの独立アプリ開発（Windows初版）

スタッフはOSの「FILES → 初回セットアップ」からZIPを取得し、展開してセットアップ.cmdを開きます。
PHP 8.4.25 x64を公式配布元から取得し、固定SHA-256で検証して専用フォルダへ導入します。
必要なMicrosoft VCランタイムは公式配布・署名検証後にインストールします。UACや組織の端末管理ポリシーによる承認は必要になる場合があります。
XAMPPの設定やPATHをシステム全体で変更しません。既存PHPのphp.iniも変更しません。
配布スクリプトは未署名です。組織が署名済みインストーラーを要求する場合の配布対応は別途必要です。

## 操作
1. 初回セットアップ後のローカル画面に表示される接続コードを、3ペインの接続欄へ貼り付けます。
2. 最新のEdge/Chromeでローカルネットワークへのアクセスを許可します。
3. 「保存フォルダを選択」でWindowsのフォルダを指定します。以前のブラウザ接続フォルダは勝手に変更しません。
4. 「Codexで開発」から開発専用の会話を開き、Codexへ接続して作りたいアプリやサイトを伝えます。車両管理・環境整備管理・CMSなど、用途に応じて空のフォルダから作成できます。ログインやDBが必要な場合は作成アプリに組み込みます。
5. 「起動」でPHPを起動し、中央のプレビューまたは「別タブで開く」で確認します。
6. 「実行ログ」でPHPのエラーを確認できます。Codexには開発専用の会話から修正を依頼します。
7. 「公開用ZIP」でコードを出力します。サーバーの準備・転送・初期設定は同梱SETUP.mdに従います。

ツールが停止しているときは、3ペインの「開発用ツールを起動」リンク（risegate-dev://launch）かデスクトップのショートカットで起動します。
ブラウザ拡張機能は不要です。接続コードはタブのsessionStorageに保持し、タブを閉じた後は再接続します。
接続コードや管理者パスワードをOSサーバーやAIへ送る実装はありません。

## 構成
- OS：通常のAI会話・資料作成と3ペインUI。開発はPC側のCodexを利用し、開発依頼やソースをOSのAIチャット経路へ自動送信しません。従来のブラウザ接続やOS内アプリは既存の履歴を維持します。
- PCツール：127.0.0.1:41739だけで待ち受けるHTTP API。Origin・Host・256bit接続コードを検証します。
- 実行アプリ：別の空きループバックポートのPHP。公開ディレクトリはpublic/があればpublic/、なければ選択フォルダです。開発用ルーターはCookieをSecure・SameSite=None・Partitionedに調整し、別サイトの中央プレビュー内でもログインできます。このルーターは公開用ZIPへ含めず、本番のCookie設定はアプリ自身が管理します。
- 独立TODO：public/index.php、database.php、setup.php、data/todo.sqlite。OSのアカウントやAPIには依存しません。
- PC内の保存先：%LOCALAPPDATA%/RiseGateDev（PHP・設定・ログ）。選択した開発フォルダは別管理です。

フォルダ選択はネイティブのダイアログに限定しています。APIから任意の絶対パスを登録できません。
ファイル操作には選択フォルダの識別子も添付し、切替前のハンドルで別フォルダへ保存する操作を拒否します。
親パス、絶対パス、秘密情報、DB、シンボリックリンク等への編集を拒否します。既存ファイルはハッシュを検証してからロックして保存します。
AIの変更は最大12ファイルです。全提案を検証後に履歴へ記録し、ブラウザが順番に保存します。
ファイル間のトランザクションではありません。途中で競合・失敗すると後続を止め、各提案の状態を表示します。
起動したPHPはWindowsユーザーの権限で動きます。生成コードのOSレベルのサンドボックスではありません。
ログは利用者が選んだ場合だけAI入力欄へ入ります。送信前に内容を確認できます。
保存日時、バックアップ名、PHPの日時、TODOの表示はJSTです。

## 公開
初版はPHP 8.2以降・PDO SQLite・mbstringが動き、public/だけを公開できるサーバーを対象にします。
公開用ZIPにはコードだけを含め、data/、秘密情報、開発履歴、依存パッケージ等を除外します（20MB・1万ファイルまで）。
初回はサーバー管理者がphp setup.phpを実行して本番の管理者を作成します。以後の更新でdata/を上書き・削除しません。
ローカルのアカウント・TODOを自動で本番へ移行する機能はありません。既存HTMLのlocalStorageデータも自動移行しません。
OneDriveなどのフォルダ同期でDBを共有せず、公開後は同じサーバーURLから利用します。
MySQL、Composer/npmパッケージのインストール、任意コマンドの実行、自動アップロード、完全自動のエラー修正は初版の対象外です。

## 検証
- php artisan test --filter=LocalDevelopmentTest：実際の補助ツール・PHPアプリ・SQLiteを使用して接続認証、パス保護、競合、ログイン・権限、再起動後の保持、ZIPを確認。
- php artisan test --filter=AiChatTest：複数ファイル生成の検証と履歴、危険なパス・重複の拒否。
- tests/Browser/local-development.html：テスト用設定でヘッドレスChromeから実HTTP APIへ接続してUTF-8保存、競合、フォルダ操作、PHPのiframe表示を確認。localhostの親画面と127.0.0.1のiframeを使い、Chrome DevTools経由でiframeの管理者ログイン・TODOのDB保存も確認しました。画面の検証後はwindow.testClient.call('stop')で停止します。
- Windowsセットアップ：一時ディレクトリで公式PHPを実際に取得して導入。検証時は-NoShortcut -NoRegistration -NoLaunchを使用。
- 本番OSのHTTPSからの端末接続許可・組織ポリシー・Windowsのフォルダ選択とカスタムURL起動は、手動デプロイ後に対象端末でも確認する。

本番workflowは引き続きworkflow_dispatchのみです。tools/local-devもリリースアーカイブへ含めます。Git pushではデプロイしません。
既存のOS内保存アプリはデータを残し、FILES内の折りたたみ欄から開けます。補助ツール接続中の新規開発は独立PHP方式を使用します。

参考：
- https://windows.php.net/download/
- https://developer.chrome.com/blog/local-network-access
- https://www.php.net/manual/en/features.commandline.webserver.php
- https://learn.microsoft.com/ja-jp/cpp/windows/latest-supported-vc-redist

## セットアップ画面が閉じる・完了しない場合
配布ZIPのセットアップ.cmdはWindows用の改行で出力し、成功・失敗にかかわらずキー入力を待ってから閉じます。setup.ps1が進行内容とエラーをJST付きで %LOCALAPPDATA%/RiseGateDev/logs/setup-日時-PID.log に保存します。書き込めなければ %TEMP%/RiseGateDev/logs に保存します。PowerShell自体が起動できない場合もCMD画面を残します。
旧ZIPで画面がすぐ消える場合は、展開フォルダのアドレスバーにcmdと入力してEnterを押し、そのコマンド画面でセットアップ.cmdを実行するとエラーを確認できます。

開発ツールはFILESの「開発ツール」を開いたときだけ表示します。保存済みコードでの再接続も開いたときに行い、通常のプロジェクト管理・資料作成ではPCの開発ツールへ自動接続しません。接続後に折りたたんでも実行は停止せず、「接続中」を表示します。

開発専用のCodex連携と認証・セットアップの詳細は [codex-development.md](codex-development.md) を参照してください。

管理画面の中央プレビュー：開発専用 app-router.php が X-Frame-Options を、セットアップ済み接続元だけを許可する CSP frame-ancestors に置き換える。複数のCSPヘッダーと他のディレクティブは保持する。接続元は開発ツールのconfig.jsonから読み、リクエストのOriginやRefererから許可しない。設定がないテスト起動では https://os.rise-gate.com のみ。アプリ内のadmin.phpや公開ZIPには変更を加えないため、本番の埋め込み禁止は維持される。