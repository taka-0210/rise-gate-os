@extends('layouts.app')
@section('content')
<div style="max-width:800px;margin:32px auto;padding:24px">
<h1>Windows 開発用セットアップ</h1>
<p>一度セットアップすると、3ペインからフォルダを選び、PHP・SQLiteのアプリをCodexで作成・起動できます。ブラウザ拡張機能は不要です。</p>
<ol>
<li><a href="{{ route('development.download') }}">開発用セットアップをダウンロード</a>し、ZIPを右クリック →「すべて展開」で通常のフォルダへ展開します。</li>
<li>展開先の通常のフォルダを開き、「セットアップ.cmd」を実行します。同じフォルダにsetup.ps1とinstall.ps1があることを確認してください。PHPを公式配布元から取得して、整合性を確認して導入します。</li>
<li>表示された接続コードをコピーし、3ペインのFILESで「開発ツール」を開き、「開発用ツールに接続」に貼り付けます。</li>
<li>ブラウザからローカルネットワークへの接続許可を求められたら許可し、「保存フォルダを選択」を押します。</li>
<li>「Codexで開発」を押し、右側で「Codexに接続」を押します。ログインが必要な場合はChatGPTまたはOpenAI APIキーで認証します。作りたいものを依頼し、作業完了後に「起動」で確認します。</li>
</ol>
<p>セットアップ画面は完了・失敗時にそのまま残ります。エラーが出た場合は画面の内容を確認してください。ログは <code>%LOCALAPPDATA%\RiseGateDev\logs</code> に保存されます。</p>
<p>次回からは3ペインの「開発用ツールを起動」、またはデスクトップの「RISE GATE 開発用ツール」で起動できます。接続コードはこのPCとOSをつなぐためのものです。AIの会話に貼り付けないでください。</p>
<p>セットアップは、既存のCodex CLIまたはVS Code版Codexがあれば利用します。ない場合は公式のCodex 0.153.0を整合性確認付きで導入します。Codexの利用料金・制限は接続したChatGPTアカウントまたはOpenAI API側で管理され、OSのAIポイントとは別です。開発ファイルと開発会話はPC側で扱い、通常の会話・資料作成は従来のAIパートナーを使います。</p>
<p>対応範囲：Windows 64bit、最新のEdgeまたはChrome、PHP 8.4・SQLite。初回はインターネット接続が必要です。アプリのPHPはこのPCのユーザー権限で実行されます。</p>
<p>必要なMicrosoftランタイムもセットアップで導入します。導入に失敗した場合は、<a href="https://learn.microsoft.com/ja-jp/cpp/windows/latest-supported-vc-redist" target="_blank" rel="noopener noreferrer">Microsoft公式のVisual C++再頒布可能パッケージ（x64）</a>をインストールして再実行してください。会社のPCでソフトウェアの導入が制限されている場合は管理者による導入が必要です。</p>
<p>完成したアプリは自社サーバーへ移せます。公開後の利用者には開発用ツールもRISE GATE OSのログインも必要ありません。公開用ZIPはプログラムを出力し、テスト用DBやパスワードは含めません。</p>
<p>開発用ツールは <code>%LOCALAPPDATA%\RiseGateDev</code> に保存されます。アンインストール時は <code>tool/unregister.ps1</code> をPowerShellで実行して起動用の登録を解除し、Windowsの再起動後にこのフォルダとショートカットを削除します。選択した開発フォルダのファイルはそのまま残ります。</p>
</div>
@endsection
