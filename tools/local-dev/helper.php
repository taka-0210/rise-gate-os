<?php
declare(strict_types=1);
require __DIR__.'/Workspace.php';
require __DIR__.'/CodexSession.php';
use RiseGate\LocalDev\CodexSession;
use RiseGate\LocalDev\Workspace;

date_default_timezone_set('Asia/Tokyo');
$configPath = $argv[1] ?? '';
$config = json_decode((string) @file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
if (!preg_match('/^[a-f0-9]{64}$/', $config['token'] ?? '')) throw new RuntimeException('Invalid setup');
// Accept the exact legacy PowerShell array wrapper, without broadening the allowlist.
if (is_array($config['origins']['value'] ?? null)) $config['origins'] = $config['origins']['value'];
if (!is_array($config['origins'] ?? null) || !array_is_list($config['origins'])
    || count(array_filter($config['origins'], 'is_string')) !== count($config['origins'])) {
    throw new RuntimeException('Invalid origin configuration. Run setup again.');
}
$stateDir = dirname($configPath);
$projectsPath = $stateDir.'/projects.json';
$projects = is_file($projectsPath) ? json_decode(file_get_contents($projectsPath), true) : [];
$runners = [];
$codexSessions = [];
$port = (int) ($config['port'] ?? 41739);
if ($port < 1024 || $port > 65535) throw new RuntimeException('Invalid port');
$socket = stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $error);
if (!$socket) throw new RuntimeException('開発用ツールを起動できません: '.$error);
register_shutdown_function(function () use (&$runners, &$codexSessions) {
    foreach ($codexSessions as $session) $session->close();
    foreach ($runners as $runner) {
        if (is_resource($runner['process'])) { proc_terminate($runner['process']); proc_close($runner['process']); }
    }
});
function reply($client, int $status, string $body, array $headers = []): void {
    $headers += ['Content-Type'=>'application/json; charset=utf-8', 'Cache-Control'=>'no-store',
        'X-Content-Type-Options'=>'nosniff', 'Connection'=>'close'];
    $wire = "HTTP/1.1 ".$status." Result\r\n";
    foreach ($headers as $key=>$value) $wire .= $key.': '.$value."\r\n";
    $wire .= 'Content-Length: '.strlen($body)."\r\n\r\n".$body;
    while ($wire !== '') { $written = @fwrite($client, $wire); if (!$written) break; $wire = substr($wire, $written); }
}
function runCommand(array $args, string $cwd): string {
    $p = proc_open($args, [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $cwd, null, ['bypass_shell'=>true, 'create_process_group'=>true]);
    if (!is_resource($p)) throw new RuntimeException('処理を開始できません。');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($p) !== 0) throw new RuntimeException(mb_substr($err ?: $out, 0, 2000));
    return trim($out);
}
while (true) {
    foreach ($codexSessions as $session) $session->tick();
    $client = @stream_socket_accept($socket, 1);
    foreach ($runners as $key => $runner) {
        if (!proc_get_status($runner['process'])['running']) { proc_close($runner['process']); unset($runners[$key]); }
    }
    if (!$client) continue;
    stream_set_timeout($client, 5);
    $cors = [];
    try {
        $line = fgets($client, 4096);
        if (!$line || !preg_match('~^(GET|POST|OPTIONS) ([^ ]+) HTTP/1\.[01]\r\n$~', $line, $match)) throw new RuntimeException('Invalid request', 400);
        [, $method, $url] = $match;
        $headers = [];
        $bytes = 0;
        while (($line = fgets($client, 8192)) !== false && $line !== "\r\n") {
            $bytes += strlen($line);
            if ($bytes > 16000 || !str_contains($line, ':')) throw new RuntimeException('Invalid headers', 400);
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            if (isset($headers[$name])) throw new RuntimeException('Duplicate header', 400);
            $headers[$name] = trim($value);
        }
        if (($headers['host'] ?? '') !== '127.0.0.1:'.$port || isset($headers['transfer-encoding'])) throw new RuntimeException('Invalid host', 403);
        $origin = $headers['origin'] ?? '';
        // Pairing is visible only in a top-level local page, never readable through CORS.
        if ($method === 'GET' && $url === '/' && $origin === '' && in_array($headers['sec-fetch-site'] ?? 'none', ['none','same-origin'], true)) {
            $code = htmlspecialchars($config['token'], ENT_QUOTES, 'UTF-8');
            reply($client, 200, '<!doctype html><html lang="ja"><meta charset="utf-8"><title>RISE GATE 開発用ツール</title><h1>開発用ツールを起動しました</h1><p>3ペインの「開発用ツールに接続」に、この接続コードを貼り付けてください。</p><input aria-label="接続コード" readonly size="68" value="'.$code.'"><p>このPCで選択したフォルダのPHPを実行します。日時はJSTです。この画面は閉じても動作します。</p></html>', ['Content-Type'=>'text/html; charset=utf-8', 'Content-Security-Policy'=>"default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'", 'X-Frame-Options'=>'DENY', 'Referrer-Policy'=>'no-referrer']);
            fclose($client); continue;
        }
        if (!in_array($origin, $config['origins'], true)) throw new RuntimeException('許可されていない接続元です。', 403);
        $cors = ['Access-Control-Allow-Origin'=>$origin, 'Vary'=>'Origin',
            'Access-Control-Allow-Methods'=>'POST, OPTIONS', 'Access-Control-Allow-Headers'=>'Content-Type, X-RiseGate-Token',
            'Access-Control-Allow-Private-Network'=>'true'];
        if ($method === 'OPTIONS') { reply($client, 204, '', $cors); fclose($client); continue; }
        if ($method !== 'POST' || $url !== '/api' || !hash_equals($config['token'], $headers['x-risegate-token'] ?? '')) throw new RuntimeException('接続コードを確認してください。', 403);
        $length = $headers['content-length'] ?? '';
        if (!ctype_digit($length) || (int)$length > 23_000_000 || (int)$length < 2) throw new RuntimeException('サイズが不正です。', 413);
        $body = '';
        while (strlen($body) < (int)$length) {
            $chunk = fread($client, min(65536, (int)$length - strlen($body)));
            if ($chunk === false || $chunk === '') throw new RuntimeException('受信できませんでした。', 400);
            $body .= $chunk;
        }
        $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $project = $input['project'] ?? '';
        if (!is_string($project) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $project)) throw new RuntimeException('プロジェクトが不正です。', 400);
        $key = hash('sha256', $origin.'/'.$project);
        $action = $input['action'] ?? '';
        if ($action === 'status') {
            $result = ['version'=>'2.1.0', 'codexImages'=>true, 'codexAvailable'=>CodexSession::executable($config) !== null, 'php'=>PHP_VERSION, 'sqlite'=>extension_loaded('pdo_sqlite'),
                'folder'=>isset($projects[$key]) ? basename($projects[$key]) : null,
                'workspace'=>isset($projects[$key]) ? hash('sha256', $projects[$key]) : null,
                'url'=>$runners[$key]['url'] ?? null, 'time'=>date(DATE_ATOM)];
        } elseif ($action === 'select') {
            if (isset($codexSessions[$key]) && $codexSessions[$key]->busy()) throw new RuntimeException('Codexの作業を停止してからフォルダを変更してください。');
            if (isset($runners[$key])) throw new RuntimeException('停止してからフォルダを変更してください。');
            if (PHP_OS_FAMILY !== 'Windows') throw new RuntimeException('Windows版を利用してください。');
            $path = runCommand(['powershell.exe','-NoProfile','-STA','-ExecutionPolicy','Bypass','-File',__DIR__.'/choose-folder.ps1'], __DIR__);
            if ($path === '') throw new RuntimeException('フォルダ選択を取り消しました。');
            $root = realpath($path);
            if (!$root || dirname($root) === $root) throw new RuntimeException('プロジェクト用のフォルダを選択してください。');
            // Never select the helper installation or a parent of it.
            if (str_starts_with(strtolower(realpath(__DIR__).DIRECTORY_SEPARATOR), strtolower($root.DIRECTORY_SEPARATOR))) throw new RuntimeException('開発ツールとは別のフォルダを選択してください。');
            new Workspace($root);
            if (isset($codexSessions[$key])) { $codexSessions[$key]->close(); unset($codexSessions[$key]); }
            $projects[$key] = $root;
            file_put_contents($projectsPath, json_encode($projects, JSON_UNESCAPED_UNICODE), LOCK_EX);
            $result = ['folder'=>basename($root)];
        } else {
            if (!isset($projects[$key])) throw new RuntimeException('先に保存フォルダを選択してください。', 404);
            if (!hash_equals(hash('sha256', $projects[$key]), $input['workspace'] ?? '')) throw new RuntimeException('接続フォルダが変わりました。再接続してください。', 409);
            $workspace = new Workspace($projects[$key]);
            $path = $input['path'] ?? '';
            if (!is_string($path)) throw new RuntimeException('Invalid path', 400);
            $result = match ($action) {
                'disconnect' => (function () use (&$runners, &$codexSessions, $key) {
                    if (isset($codexSessions[$key])) { $codexSessions[$key]->close(); unset($codexSessions[$key]); }
                    if (isset($runners[$key])) { proc_terminate($runners[$key]['process']); proc_close($runners[$key]['process']); unset($runners[$key]); }
                    return ['disconnected'=>true];
                })(),
                'codex_connect' => (function () use (&$codexSessions, $key, $workspace, $stateDir, $config) {
                    if (isset($codexSessions[$key]) && $codexSessions[$key]->state()['phase'] === 'failed') {
                        $codexSessions[$key]->close(); unset($codexSessions[$key]);
                    }
                    if (!isset($codexSessions[$key])) {
                        $exe = CodexSession::executable($config);
                        if (!$exe) throw new RuntimeException('Codexが見つかりません。初回セットアップのCodex導入手順を確認してください。');
                        $command = [$exe,'app-server','-c','sandbox_workspace_write.network_access=false','-c','sandbox_workspace_write.writable_roots=[]'];
                        if (PHP_OS_FAMILY === 'Windows') array_push($command, '-c', 'windows.sandbox="unelevated"');
                        $codexSessions[$key] = new CodexSession($workspace->root, $stateDir, hash('sha256',$key.'/'.$workspace->root), $command);
                    }
                    return $codexSessions[$key]->state();
                })(),
                'codex_poll', 'codex_send', 'codex_login', 'codex_approve', 'codex_interrupt', 'codex_disconnect' => (function () use (&$codexSessions, $key, $action, $input) {
                    $session = $codexSessions[$key] ?? null;
                    if (!$session) throw new RuntimeException('先にCodexへ接続してください。', 409);
                    $session->tick();
                    if ($action === 'codex_send') $session->start($input['prompt'] ?? '', $input['requestId'] ?? '', $input['images'] ?? []);
                    elseif ($action === 'codex_login') $session->login($input);
                    elseif ($action === 'codex_approve') $session->approve($input);
                    elseif ($action === 'codex_interrupt') $session->interrupt();
                    elseif ($action === 'codex_disconnect') { $session->close(); unset($codexSessions[$key]); return ['disconnected'=>true]; }
                    return $session->state();
                })(),
                'list' => ['entries'=>$workspace->listing($path)],
                'read' => $workspace->read($path),
                'create' => $workspace->create($path, $input['kind'] === 'directory' ? 'directory' : 'file'),
                'stat' => (function () use ($workspace, $path) {
                    $p = $workspace->path($path);
                    if (!file_exists($p)) throw new RuntimeException('ファイルがありません。', 404);
                    return ['kind'=>is_dir($p) ? 'directory' : 'file'];
                })(),
                'write' => $workspace->write($path, $input['content'] ?? '', $input['expected'] ?? null),
                'stop' => (function () use (&$runners, $key) {
                    if (isset($runners[$key])) { proc_terminate($runners[$key]['process']); proc_close($runners[$key]['process']); unset($runners[$key]); }
                    return ['stopped'=>true];
                })(),
                'start' => (function () use (&$runners, $key, $workspace, $stateDir) {
                    if (isset($runners[$key])) return ['url'=>$runners[$key]['url']];
                    $docroot = is_dir($workspace->path('public')) ? $workspace->path('public') : $workspace->root;
                    if (!is_file($docroot.'/index.php') && !is_file($docroot.'/index.html')) throw new RuntimeException('まずindex.phpまたはindex.htmlを作成してください。');
                    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
                    if (!$probe) throw new RuntimeException('空きポートを取得できません。');
                    $address = stream_socket_get_name($probe, false); fclose($probe);
                    $log = $stateDir.'/'.$key.'.log';
                    file_put_contents($log, '['.date(DATE_ATOM)."] 起動\n");
                    $command = [PHP_BINARY, '-c', php_ini_loaded_file() ?: __DIR__.'/php.ini',
                        '-d','date.timezone=Asia/Tokyo', '-d','display_errors=0', '-d','log_errors=1',
                        '-d','error_log='.$log, '-S',$address,'-t',$docroot,__DIR__.'/app-router.php'];
                    $p = proc_open($command, [0=>['file',PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes, $docroot, null, ['bypass_shell'=>true, 'create_process_group'=>true]);
                    if (!is_resource($p)) throw new RuntimeException('PHPを起動できません。');
                    $ready = false;
                    for ($i=0; $i<30; $i++) {
                        $connection = @stream_socket_client('tcp://'.$address, $e, $s, 0.1);
                        if ($connection) { fclose($connection); $ready = true; break; }
                        if (!proc_get_status($p)['running']) break;
                        usleep(100000);
                    }
                    if (!$ready) { proc_terminate($p); proc_close($p); throw new RuntimeException('起動に失敗しました。エラーを確認してください。'); }
                    $runners[$key] = ['process'=>$p, 'url'=>'http://'.$address.'/'];
                    return ['url'=>$runners[$key]['url'], 'time'=>date(DATE_ATOM)];
                })(),
                'logs' => ['text'=>is_file($stateDir.'/'.$key.'.log') ? mb_strcut((string)file_get_contents($stateDir.'/'.$key.'.log'), -12000, null, 'UTF-8') : '実行ログはまだありません。'],
                'seed' => (function () use ($workspace, $input) {
                    $login = $input['login'] ?? '';
                    $password = $input['password'] ?? '';
                    if (!preg_match('/^[a-zA-Z0-9_-]{3,40}$/', $login) || strlen($password)<8 || strlen($password)>128) throw new RuntimeException('管理者IDは英数字3〜40文字、パスワードは8〜128文字にしてください。');
                    $source = __DIR__.'/templates/todo';
                    $files = ['public/index.php','database.php','setup.php','SETUP.md'];
                    foreach ($files as $relative) if (file_exists($workspace->path($relative))) throw new RuntimeException('同名ファイルがあります。空のフォルダでひな形を作成してください。');
                    if (file_exists($workspace->path('data', true))) throw new RuntimeException('既存データがあります。空のフォルダを選択してください。');
                    $workspace->create('public', 'directory');
                    foreach ($files as $relative) {
                        if (!copy($source.'/'.$relative, $workspace->path($relative))) throw new RuntimeException('ひな形の保存に失敗しました。');
                    }
                    require_once $source.'/database.php';
                    $db = todoDatabase($workspace->root);
                    $stmt = $db->prepare('INSERT INTO accounts(login,password,role) VALUES(?,?,?)');
                    $stmt->execute([$login,password_hash($password,PASSWORD_DEFAULT),'admin']);
                    return ['created'=>true];
                })(),
                'export' => (function () use ($workspace, $stateDir, $key) {
                    $target = $stateDir.'/'.$key.'.zip';
                    $workspace->export($target);
                    return ['name'=>'project-'.date('Ymd-His').'.zip','content'=>base64_encode(file_get_contents($target))];
                })(),
                default => throw new RuntimeException('未対応の操作です。', 400),
            };
        }
        reply($client, 200, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), $cors);
    } catch (Throwable $e) {
        $status = in_array($e->getCode(), [400,403,404,409,413], true) ? $e->getCode() : 422;
        reply($client, $status, json_encode(['message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $cors);
    }
    fclose($client);
}
