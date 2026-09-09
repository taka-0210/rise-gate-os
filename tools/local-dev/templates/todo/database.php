<?php
declare(strict_types=1);
function todoDatabase(string $root): PDO
{
    date_default_timezone_set('Asia/Tokyo');
    $directory = $root.'/data';
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('DBフォルダを作成できません。');
    $db = new PDO('sqlite:'.$directory.'/todo.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec("CREATE TABLE IF NOT EXISTS accounts(id INTEGER PRIMARY KEY, login TEXT UNIQUE NOT NULL, password TEXT NOT NULL, role TEXT NOT NULL CHECK(role IN ('admin','staff')))");
    $db->exec('CREATE TABLE IF NOT EXISTS todos(id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL REFERENCES accounts(id), title TEXT NOT NULL, done INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS login_attempts(bucket TEXT PRIMARY KEY, attempts INTEGER NOT NULL, until_at INTEGER NOT NULL)');
    return $db;
}
