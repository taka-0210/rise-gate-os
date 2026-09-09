<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/database.php';
$db = todoDatabase(__DIR__);
if ($db->query('SELECT COUNT(*) FROM accounts')->fetchColumn()) exit("設定済みです。既存データを保持しました。\n");
echo "管理者ログインID（英数字3〜40文字）: ";
$login = trim(fgets(STDIN));
echo "管理者パスワード（8〜128文字。この端末上では入力が表示されます）: ";
$password = rtrim(fgets(STDIN), "\r\n");
if (!preg_match('/^[a-zA-Z0-9_-]{3,40}$/', $login) || strlen($password)<8 || strlen($password)>128) exit("入力を確認して再実行してください。\n");
$db->prepare('INSERT INTO accounts(login,password,role) VALUES(?,?,?)')->execute([$login,password_hash($password,PASSWORD_DEFAULT),'admin']);
echo "管理者を作成しました。public/を公開ディレクトリに設定してください。\n";
