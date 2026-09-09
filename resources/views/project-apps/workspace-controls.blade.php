<section style="padding:12px;border-bottom:1px solid #d5dde3" aria-label="保存済みアプリ">
<strong>保存済みアプリ</strong>
<p class="file-note">専用ログイン・サーバー保存。別端末でも利用できます。</p>
<div data-project-app-list></div>
@can('update', $project)
<button type="button" data-create-todo-app>TODOのひな形から作成</button>
@endcan
<p class="file-note" data-project-app-status role="status"></p>
</section>
@can('update', $project)
<dialog data-project-app-dialog style="width:min(520px,95vw);border:1px solid #c4d5db;border-radius:12px;padding:24px">
<form data-project-app-form class="stack">
<h2>アプリを登録・更新</h2>
<p>アプリ本体をサーバーに保存します。利用者は専用URLで、このアプリ用のID・パスワードでログインします。</p>
<label>登録先<select name="target"><option value="">新しいアプリ</option></select></label>
<label>アプリ名<input name="name" maxlength="120" required></label>
<div data-app-admin-fields class="stack">
<label>管理者のログインID<input name="admin_login" pattern="[a-zA-Z0-9_.-]+" maxlength="80" autocomplete="off" value="admin"></label>
<label>管理者のパスワード（8文字以上）<input name="admin_password" type="password" minlength="8" maxlength="200" autocomplete="new-password"></label>
<span class="file-note">スタッフは登録後、アプリ内の「アカウント管理」から追加できます。</span>
</div>
<p data-app-register-status role="status"></p>
<div class="actions"><button type="button" data-cancel-app-register>閉じる</button><button type="submit">登録・更新する</button></div>
</form></dialog>
@endcan
