<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $app->name }} | ログイン</title>
<style>body{margin:0;background:#f2f7f8;font:16px system-ui;color:#163e48}main{max-width:360px;margin:12vh auto;padding:32px;background:white;border-radius:16px}label{display:block;margin:16px 0}input,button{box-sizing:border-box;width:100%;padding:12px;border:1px solid #b9cfd5;border-radius:7px;font:inherit}button{background:#176779;color:white}p{font-size:13px;color:#5a747c}.error{color:#ae3333}</style></head>
<body><main><h1>{{ $app->name }}</h1><p>このアプリ専用のアカウントでログインしてください。</p>
@if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
<form method="post" action="{{ route('apps.login', $app) }}">@csrf
<label>ログインID<input name="login" autocomplete="username" maxlength="80" required autofocus></label>
<label>パスワード<input name="password" type="password" autocomplete="current-password" maxlength="200" required></label>
<button>ログイン</button></form></main></body></html>
