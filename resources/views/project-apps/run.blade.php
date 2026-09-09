<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $app->name }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;font:14px system-ui,sans-serif;color:#183e4b;background:#f5f8fa}
        header{display:flex;align-items:center;flex-wrap:wrap;gap:12px;padding:12px 16px;border-bottom:1px solid #d3e0e6}
        header small{margin-left:auto;color:#54717c}a{color:#165e73}iframe{display:block;border:0;width:100%;height:calc(100dvh - 80px);background:white}
        #status{padding:5px 16px;font-size:12px;color:#54717c}
    </style>
</head>
<body>
<header><strong>{{ $app->name }}</strong><span>{{ $account->name }} さん</span>
@if($account->role === 'admin')
<a href="{{ route('apps.accounts', $app) }}">アカウント管理</a>
<form method="get" action="{{ route('apps.run', $app) }}"><label>表示するデータ <select name="account" onchange="this.form.submit()">@foreach($accounts as $item)<option value="{{ $item->id }}" @selected($targetAccount->id === $item->id)>{{ $item->name }}</option>@endforeach</select></label></form>
@endif
<form method="post" action="{{ route('apps.logout', $app) }}">@csrf<button>ログアウト</button></form>
<small>{{ $targetAccount->name }} さんのデータ・サーバー保存</small></header>
<div id="status" role="status">アプリを読み込み中…</div>
<iframe id="app-frame" title="{{ $app->name }}" sandbox="allow-scripts allow-downloads" referrerpolicy="no-referrer"></iframe>
<script type="module">
import {installAppBridge, mountProjectApp} from @json(asset('js/project-app-runtime.js'));
const frame = document.getElementById('app-frame');
installAppBridge({
    frame, endpoint:@json(route('apps.data.read', ['projectApp' => $app, 'account' => $targetAccount->id])),
    csrf:@json(csrf_token()), status:document.getElementById('status'),
});
mountProjectApp(frame, {{ Illuminate\Support\Js::from($app->html) }});
</script>
</body>
</html>
