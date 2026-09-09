<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $app->name }} | アカウント管理</title>
<style>body{margin:0;background:#f2f7f8;font:15px system-ui;color:#163e48}main{max-width:780px;margin:24px auto;padding:20px}form{padding:20px;margin:16px 0;background:white;border-radius:12px;display:flex;gap:12px;align-items:end;flex-wrap:wrap}label{display:flex;flex-direction:column;gap:5px}input,select,button{padding:10px;border:1px solid #b9cfd5;border-radius:6px}button{background:#176779;color:white}a{color:#176779}.error{color:#ae3333}</style></head><body><main>
<a href="{{ route('apps.run', $app) }}">← {{ $app->name }} に戻る</a><h1>アカウント管理</h1>
<p>管理者はスタッフのアカウントとデータを管理できます。スタッフは自分のデータだけを利用できます。</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
<h2>アカウントを追加</h2>
<form method="post" action="{{ route('apps.accounts.store', $app) }}">@csrf
<label>ログインID<input name="login" pattern="[a-z0-9_.-]+" maxlength="80" required placeholder="staff1" autocomplete="off"></label>
<label>表示名<input name="name" maxlength="120" required></label>
<label>パスワード<input name="password" type="password" minlength="8" maxlength="200" autocomplete="new-password" required></label>
<label>権限<select name="role"><option value="staff">スタッフ</option><option value="admin">管理者</option></select></label><button>追加</button></form>
<h2>登録済みアカウント</h2>
@foreach($accounts as $item)
<form method="post" action="{{ route('apps.accounts.update', [$app, $item]) }}">@csrf @method('PUT')
<strong>{{ $item->login }}</strong><label>表示名<input name="name" value="{{ $item->name }}" maxlength="120" required></label>
<label>新しいパスワード（変更時のみ）<input name="password" type="password" minlength="8" maxlength="200" autocomplete="new-password"></label>
<label>権限<select name="role"><option value="staff" @selected($item->role === 'staff')>スタッフ</option><option value="admin" @selected($item->role === 'admin')>管理者</option></select></label>
<label>利用<select name="enabled"><option value="1" @selected($item->enabled)>有効</option><option value="0" @selected(!$item->enabled)>停止</option></select></label><button>更新</button></form>
@endforeach
</main></body></html>
