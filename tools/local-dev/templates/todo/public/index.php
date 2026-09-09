<?php
declare(strict_types=1);
require dirname(__DIR__).'/database.php';
date_default_timezone_set('Asia/Tokyo');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");
$db = todoDatabase(dirname(__DIR__));
if (!(int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn()) {
    http_response_code(503);
    exit('初期設定が必要です。サーバー管理者がSETUP.mdの手順で管理者を作成してください。');
}
$sessionDir = dirname(__DIR__).'/data/sessions';
if (!is_dir($sessionDir)) mkdir($sessionDir, 0700);
session_save_path($sessionDir);
session_name('todo_'.substr(hash('sha256', dirname(__DIR__)), 0, 16));
session_start(['cookie_httponly'=>true, 'cookie_samesite'=>'Lax', 'cookie_secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'use_strict_mode'=>true]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirectHome(): never { header('Location: ./', true, 303); exit; }
$user = null;
if (isset($_SESSION['account'])) {
    $stmt = $db->prepare('SELECT id,login,role FROM accounts WHERE id=?');
    $stmt->execute([$_SESSION['account']]);
    $user = $stmt->fetch() ?: null;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) throw new RuntimeException('画面を再読み込みしてください。');
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            $login = is_string($_POST['login'] ?? null) ? $_POST['login'] : '';
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $bucket = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '').'/'.$login);
            $db->exec('BEGIN IMMEDIATE');
            try {
                $db->prepare('DELETE FROM login_attempts WHERE until_at < ?')->execute([time()]);
                $q = $db->prepare('SELECT attempts FROM login_attempts WHERE bucket=?'); $q->execute([$bucket]);
                if ((int)$q->fetchColumn() >= 5) throw new RuntimeException('試行回数を超えました。5分後にお試しください。');
                $db->prepare('INSERT INTO login_attempts VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET attempts=attempts+1')->execute([$bucket,time()+300]);
                $db->exec('COMMIT');
            } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
            $q = $db->prepare('SELECT * FROM accounts WHERE login=?'); $q->execute([$login]); $account = $q->fetch();
            if (!$account || strlen($password)>128 || !password_verify($password, $account['password'])) throw new RuntimeException('ログインIDまたはパスワードが違います。');
            $db->prepare('DELETE FROM login_attempts WHERE bucket=?')->execute([$bucket]);
            session_regenerate_id(true);
            $_SESSION['account'] = $account['id']; $_SESSION['csrf'] = bin2hex(random_bytes(32));
            redirectHome();
        }
        if (!$user) throw new RuntimeException('ログインしてください。');
        if ($action === 'logout') { $_SESSION = []; session_destroy(); redirectHome(); }
        if ($action === 'account') {
            if ($user['role'] !== 'admin') throw new RuntimeException('管理者権限が必要です。');
            $login = $_POST['login'] ?? ''; $password = $_POST['password'] ?? ''; $role = $_POST['role'] ?? '';
            if (!is_string($login) || !preg_match('/^[a-zA-Z0-9_-]{3,40}$/', $login)
                || !is_string($password) || strlen($password)<8 || strlen($password)>128 || !in_array($role,['admin','staff'], true)) throw new RuntimeException('IDは英数字3〜40文字、パスワードは8〜128文字で入力してください。');
            $q = $db->prepare('SELECT COUNT(*) FROM accounts WHERE login=?'); $q->execute([$login]);
            if ($q->fetchColumn()) throw new RuntimeException('そのIDは登録済みです。');
            $db->prepare('INSERT INTO accounts(login,password,role) VALUES(?,?,?)')->execute([$login,password_hash($password,PASSWORD_DEFAULT),$role]);
            redirectHome();
        }
        $target = (int)($_POST['account_id'] ?? $user['id']);
        if ($target !== (int)$user['id'] && $user['role'] !== 'admin') throw new RuntimeException('他のスタッフのデータは変更できません。');
        $q = $db->prepare('SELECT id FROM accounts WHERE id=?'); $q->execute([$target]);
        if (!$q->fetchColumn()) throw new RuntimeException('スタッフが見つかりません。');
        if ($action === 'add') {
            $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
            if ($title === '' || mb_strlen($title)>300) throw new RuntimeException('TODOは1〜300文字で入力してください。');
            $db->prepare('INSERT INTO todos(account_id,title,created_at) VALUES(?,?,?)')->execute([$target,$title,date(DATE_ATOM)]);
        } elseif ($action === 'toggle' || $action === 'delete') {
            $sql = $action === 'toggle' ? 'UPDATE todos SET done=1-done,version=version+1 WHERE id=? AND account_id=? AND version=?' : 'DELETE FROM todos WHERE id=? AND account_id=? AND version=?';
            $q = $db->prepare($sql);
            $q->execute([(int)($_POST['id'] ?? 0),$target,(int)($_POST['version'] ?? 0)]);
            if ($q->rowCount() !== 1) throw new RuntimeException('別の画面で変更されました。再読み込みして確認してください。');
        } else { throw new RuntimeException('未対応の操作です。'); }
        header('Location: ./?account='.$target, true, 303); exit;
    } catch (RuntimeException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) { error_log((string)$e); $error = '保存できませんでした。再読み込みして確認してください。'; }
}
$accounts = $user && $user['role']==='admin' ? $db->query('SELECT id,login,role FROM accounts ORDER BY id')->fetchAll() : [];
$target = $user ? (int)$user['id'] : 0;
if ($user && $user['role']==='admin' && isset($_GET['account'])) {
    foreach ($accounts as $account) if ((int)$account['id'] === (int)$_GET['account']) $target = (int)$account['id'];
}
$q = $db->prepare('SELECT * FROM todos WHERE account_id=? ORDER BY done,id DESC'); $q->execute([$target]); $todos = $q->fetchAll();
?>
<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TODO管理</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fa;color:#183342;font:16px/1.6 system-ui,sans-serif}main{max-width:850px;margin:40px auto;padding:24px}header{display:flex;justify-content:space-between;align-items:center;gap:12px}section,article{background:white;padding:22px;border-radius:14px;margin:16px 0;box-shadow:0 3px 16px #1833420c}input,select,button{font:inherit;padding:10px;border:1px solid #c9d6df;border-radius:7px}button{background:#146779;color:white;cursor:pointer}label{display:block;margin:12px 0}label input{display:block;width:100%}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.row input[name=title]{flex:1;min-width:150px}.title{flex:1;overflow-wrap:anywhere}.done{text-decoration:line-through;color:#789}small{color:#677d88}.error{background:#fff0f0;color:#921e32;padding:14px;border-radius:8px}a{color:#146779}
</style>
<main><header><h1>TODO管理</h1><?php if($user): ?><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><button name="action" value="logout">ログアウト</button></form><?php endif ?></header>
<?php if($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif ?>
<?php if(!$user): ?>
<section><h2>ログイン</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><label>ログインID<input name="login" autocomplete="username" required maxlength="40"></label><label>パスワード<input type="password" name="password" autocomplete="current-password" required maxlength="128"></label><button name="action" value="login">ログイン</button></form></section>
<?php else: ?>
<p><?=h($user['login'])?> / <?=$user['role']==='admin'?'管理者':'スタッフ'?></p>
<?php if($accounts): ?><form method="get" class="row"><label>表示するスタッフ<select name="account"><?php foreach($accounts as $a): ?><option value="<?=(int)$a['id']?>" <?=$target===(int)$a['id']?'selected':''?>><?=h($a['login'])?></option><?php endforeach ?></select></label><button>表示</button></form><?php endif ?>
<section><form method="post" class="row"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="account_id" value="<?=$target?>"><input name="title" aria-label="新しいTODO" placeholder="やることを入力" required maxlength="300"><button name="action" value="add">追加</button></form></section>
<?php foreach($todos as $todo): ?><article><form method="post" class="row"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="account_id" value="<?=$target?>"><input type="hidden" name="id" value="<?=(int)$todo['id']?>"><input type="hidden" name="version" value="<?=(int)$todo['version']?>"><span class="title <?=$todo['done']?'done':''?>"><?=h($todo['title'])?></span><button name="action" value="toggle"><?=$todo['done']?'戻す':'完了'?></button><button name="action" value="delete">削除</button></form><small><?=h((new DateTimeImmutable($todo['created_at']))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i'))?> JST</small></article><?php endforeach ?>
<?php if(!$todos): ?><p>TODOはまだありません。</p><?php endif ?>
<?php if($user['role']==='admin'): ?><section><details><summary>スタッフ・管理者を追加</summary><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><label>ログインID<input name="login" required pattern="[a-zA-Z0-9_-]{3,40}" autocomplete="off"></label><label>パスワード（8文字以上）<input type="password" name="password" required minlength="8" maxlength="128" autocomplete="new-password"></label><label>権限<select name="role"><option value="staff">スタッフ</option><option value="admin">管理者</option></select></label><button name="action" value="account">追加</button></form></details></section><?php endif ?>
<?php endif ?><small>データはこのアプリのDBに保存されます。時刻：日本標準時（JST）</small></main></html>
