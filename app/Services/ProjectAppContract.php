<?php

namespace App\Services;

class ProjectAppContract
{
    public static function instructions(): string
    {
        return <<<'CONTRACT'
3ペインには「保存済みアプリ」機能があります。アプリ本体をサーバーへ登録し、専用URL・専用ログイン・DB保存で利用できます。Company OSのログインとは独立しています。外部DBやSupabaseの契約・APIキーは不要です。
管理者はアプリ登録時に設定し、スタッフはアプリ内「アカウント管理」から追加します。パスワードをHTML・JavaScriptへ埋め込まず、独自のローカル認証を実装しないでください。
データはアプリIDとアプリ専用アカウントIDで分離され、管理者は画面上部でスタッフを選べます。データのID指定や認証処理はホストに任せてください。
ユーザーが端末間共有、サーバー保存、ログイン別TODO等を依頼した場合、HTMLを1ファイルで生成・修正してください。CSS/JSはインラインで含め、外部CDN・fetch・localStorage・IndexedDB・別のiframeに依存しないでください。画像はdata URLです。PHPや任意のバックエンドコードを実行する機能ではありません。
アプリ内で利用できるAPI:
const result = await window.riseGateApp.load(); // {data:null または前回保存したJSON, revision:number, user:{id,name,role}}
await window.riseGateApp.save({todos:[...]}); // {revision:number, saved_at:JST ISO8601}
最初にloadし、データがnullの場合だけ初期値を使います。読込失敗を空データ扱いにせず、保存失敗・同時編集競合は画面へ表示して再読込を案内してください。saveの完了を待ってから保存成功を表示します。日付表示はAsia/Tokyoにします。認証トークンやURLをアプリに書く必要はありません。
window.riseGateAppがなければ「3ペインでアプリ登録後、専用URLで開いてください」と表示してください。ローカルブラウザ保存へ自動で切り替えないでください。
既存アプリのデータ形式は維持します。旧ブラウザデータは自動移行されません。必要なら旧アプリでJSON書き出し、新アプリへ明示的に取り込むUIを用意し、サーバーの既存データを無条件で上書きしないでください。
file_changeにHTML全体を返すと「アプリとして登録」ボタンから登録・更新できます。ソースを開いて変更した場合は同じアプリを更新すれば既存データが保たれます。登録操作前に公開・保存済みとは述べないでください。アプリの登録にはプロジェクト編集権限が必要です。
CONTRACT;
    }
}
