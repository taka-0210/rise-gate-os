<?php
declare(strict_types=1);
namespace RiseGate\LocalDev;

final class CodexSession
{
    private mixed $process;
    private mixed $input;
    private mixed $output;
    private array $pending = [];
    private array $approvals = [];
    private array $items = [];
    private array $history = [];
    private int $nextId = 1;
    private string $buffer = '';
    private string $phase = 'connecting';
    private ?string $thread = null;
    private ?string $turn = null;
    private ?string $queued = null;
    private array $queuedImages = [];
    private bool $authenticated = false;
    private ?string $authUrl = null;
    private string $accountType = '';
    private string $error = '';
    private bool $threadLoaded = false;
    private string $requestId = '';
    private string $snapshot;
    private array $logPaths;
    private float $lastActivity;

    public static function executable(array $config): ?string
    {
        $candidates = [$config['codex_path'] ?? '', dirname(__DIR__).'/codex/bin/codex.exe'];
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) $candidates[] = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'codex.exe' : 'codex');
        if (PHP_OS_FAMILY === 'Windows') {
            $extensions = glob((getenv('USERPROFILE') ?: '').'/.vscode/extensions/openai.chatgpt-*/bin/windows-x86_64/codex.exe') ?: [];
            usort($extensions, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            array_push($candidates, ...$extensions);
        }
        foreach ($candidates as $path) if ($path && is_file($path)) return realpath($path);
        return null;
    }

    public function __construct(private string $root, string $stateDir, string $key, array $command)
    {
        $this->lastActivity = microtime(true);
        $this->snapshot = $stateDir.'/codex-'.$key.'.json';
        if (is_file($this->snapshot)) {
            $saved = json_decode(file_get_contents($this->snapshot), true);
            $this->thread = $saved['thread'] ?? null;
            $this->history = array_slice($saved['history'] ?? [], -100);
            $this->requestId = $saved['requestId'] ?? '';
        }
        $prefix = $stateDir.'/codex-'.bin2hex(random_bytes(8));
        $this->logPaths = [$prefix.'.out', $prefix.'.err'];
        // Windows anonymous pipes do not reliably support nonblocking reads.
        $this->process = proc_open($command, [0=>['pipe','r'],1=>['file',$this->logPaths[0],'w'],2=>['file',$this->logPaths[1],'w']], $pipes, $root, null, ['bypass_shell'=>true,'create_process_group'=>true]);
        if (!is_resource($this->process)) throw new \RuntimeException('Codexを起動できません。初回セットアップを確認してください。');
        $this->input = $pipes[0];
        $this->output = fopen($this->logPaths[0], 'rb');
        $this->rpc('initialize', ['clientInfo'=>['name'=>'rise_gate_os','version'=>'1.0.0']], 'initialize');
    }

    private function send(array $message): void
    {
        $wire = json_encode($message, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
        while ($wire !== '') {
            $written = @fwrite($this->input, $wire);
            if (!$written) throw new \RuntimeException('Codexとの接続が切れました。再接続してください。');
            $wire = substr($wire, $written);
        }
        fflush($this->input);
    }

    private function rpc(string $method, array $params, string $purpose): void
    {
        $id = $this->nextId++;
        $this->pending[$id] = $purpose;
        $this->send(['id'=>$id,'method'=>$method,'params'=>(object)$params]);
    }

    private function record(string $role, string $text, ?string $id = null): void
    {
        if ($text === '') return;
        $text = mb_strcut($text, 0, 48000, 'UTF-8');
        if ($id) foreach ($this->history as &$entry) {
            if (($entry['id'] ?? null) === $id) { $entry['text'] = $text; return; }
        }
        $this->history[] = ['id'=>$id ?? bin2hex(random_bytes(8)), 'role'=>$role,'text'=>$text,'time'=>date(DATE_ATOM)];
        $this->history = array_slice($this->history, -100);
    }

    private function save(): void
    {
        file_put_contents($this->snapshot, json_encode(['thread'=>$this->thread,'history'=>$this->history,'requestId'=>$this->requestId], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    }

    private function beginTurn(): void
    {
        $prompt = $this->queued;
        $this->queued = null;
        $input = [['type'=>'text','text'=>$prompt,'text_elements'=>[]]];
        foreach ($this->queuedImages as $image) $input[] = ['type'=>'image','url'=>$image['url']];
        $this->queuedImages = [];
        $this->rpc('turn/start', ['threadId'=>$this->thread,'input'=>$input], 'turn');
    }

    public function tick(): void
    {
        if (!is_resource($this->process)) return;
        clearstatcache(true, $this->logPaths[0]);
        fseek($this->output, 0, SEEK_CUR); // Clear EOF after app-server appends to its output file.
        $this->buffer .= stream_get_contents($this->output, 262144);
        $changed = false;
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);
            $message = json_decode($line, true);
            if (!is_array($message)) continue;
            $this->lastActivity = microtime(true);
            $changed = true;
            $id = $message['id'] ?? null;
            if (isset($message['method'])) {
                $method = $message['method'];
                $params = $message['params'] ?? [];
                if ($method === 'item/started' && isset($params['item']['id'])) $this->items[$params['item']['id']] = $params['item'];
                if ($id !== null) {
                    if (in_array($method, ['item/commandExecution/requestApproval','item/fileChange/requestApproval','item/tool/requestUserInput'], true)) {
                        $this->approvals[(string)$id] = ['id'=>$id,'method'=>$method,'params'=>$params, 'preview'=>$this->items[$params['itemId'] ?? ''] ?? null];
                    } else {
                        // Unsupported capabilities never get automatically approved.
                        $this->send(['id'=>$id,'error'=>['code'=>-32601,'message'=>'This client does not support this request.']]);
                    }
                } elseif ($method === 'account/login/completed') {
                    $this->authUrl = null;
                    if (!($params['success'] ?? false)) $this->error = 'Codexへのログインが完了しませんでした。もう一度接続してください。';
                    $this->rpc('account/read', ['refreshToken'=>false], 'account');
                } elseif ($method === 'turn/started') {
                    $this->turn = $params['turn']['id'] ?? $this->turn;
                    $this->phase = 'running';
                } elseif ($method === 'turn/completed') {
                    $status = $params['turn']['status'] ?? 'failed';
                    $this->phase = 'ready'; $this->turn = null; $this->approvals = [];
                    if ($status === 'failed') $this->error = $params['turn']['error']['message'] ?? 'Codexの処理が失敗しました。';
                    $this->record('status', $status === 'completed' ? '作業が完了しました。' : ($status === 'interrupted' ? '作業を停止しました。' : '作業を完了できませんでした。'));
                } elseif ($method === 'item/agentMessage/delta') {
                    $itemId = $params['itemId'] ?? '';
                    $previous = '';
                    foreach ($this->history as $entry) if ($entry['id'] === $itemId) $previous = $entry['text'];
                    $this->record('assistant', $previous.($params['delta'] ?? ''), $itemId ?: null);
                } elseif ($method === 'item/started' && ($params['item']['type'] ?? '') === 'commandExecution') {
                    $this->record('status', '実行中：'.($params['item']['command'] ?? '動作確認'));
                } elseif ($method === 'item/completed') {
                    $item = $params['item'] ?? [];
                    if (($item['type'] ?? '') === 'agentMessage') $this->record('assistant', $item['text'] ?? '', $item['id'] ?? null);
                    elseif (($item['type'] ?? '') === 'commandExecution') $this->record('tool', ($item['command'] ?? '')."\n".($item['aggregatedOutput'] ?? ''), $item['id'] ?? null);
                    elseif (($item['type'] ?? '') === 'fileChange') $this->record('tool', 'ファイル変更：'.implode('、', array_column($item['changes'] ?? [], 'path')), $item['id'] ?? null);
                }
            } elseif ($id !== null && isset($this->pending[$id])) {
                $purpose = $this->pending[$id]; unset($this->pending[$id]);
                if (isset($message['error'])) {
                    $this->error = $message['error']['message'] ?? 'Codexからエラーが返りました。';
                    if (in_array($purpose, ['thread','turn'], true)) { $this->queued = null; $this->phase = 'ready'; }
                    elseif ($purpose === 'initialize') $this->phase = 'failed';
                    continue;
                }
                $result = $message['result'] ?? [];
                if ($purpose === 'initialize') {
                    $this->send(['method'=>'initialized','params'=>(object)[]]);
                    $this->rpc('account/read', ['refreshToken'=>false], 'account');
                } elseif ($purpose === 'account') {
                    $this->authenticated = !empty($result['account']);
                    $this->accountType = $result['account']['type'] ?? '';
                    $this->phase = $this->authenticated ? 'ready' : 'login';
                } elseif ($purpose === 'login') {
                    $this->authUrl = $result['authUrl'] ?? null;
                    if (!$this->authUrl) $this->rpc('account/read', ['refreshToken'=>false], 'account');
                } elseif ($purpose === 'thread') {
                    $this->thread = $result['thread']['id'] ?? null;
                    $this->threadLoaded = true;
                    if ($this->thread && $this->queued !== null) $this->beginTurn();
                } elseif ($purpose === 'turn') {
                    $this->turn = $result['turn']['id'] ?? $this->turn;
                }
            }
        }
        if (strlen($this->buffer) > 8000000) { $this->error = 'Codexの応答が大きすぎます。'; $this->close(); }
        if (is_resource($this->process) && !proc_get_status($this->process)['running']) {
            $this->phase = 'failed';
            $this->error = 'Codexが終了しました。再接続してください。';
        }
        if ($this->phase === 'connecting' && microtime(true)-$this->lastActivity > 30) {
            $this->error = 'Codexの初期化がタイムアウトしました。'; $this->close();
        }
        if ($changed) $this->save();
    }

    public function busy(): bool { return $this->phase === 'running'; }

    public function state(): array
    {
        $this->tick();
        return ['phase'=>$this->phase,'authenticated'=>$this->authenticated,'accountType'=>$this->accountType,'authUrl'=>$this->authUrl,
            'history'=>$this->history,'approvals'=>array_values($this->approvals),'error'=>$this->error,'requestId'=>$this->requestId];
    }

    public function login(array $input): void
    {
        if (!in_array($this->phase, ['login','ready'], true) || $this->busy()) throw new \RuntimeException('Codexの接続準備ができていません。');
        $this->error = '';
        if (($input['type'] ?? '') === 'apiKey') {
            $key = $input['apiKey'] ?? '';
            if (!is_string($key) || strlen($key)<20 || strlen($key)>1024) throw new \RuntimeException('APIキーを確認してください。');
            $this->rpc('account/login/start', ['type'=>'apiKey','apiKey'=>$key], 'login');
        } else $this->rpc('account/login/start', ['type'=>'chatgpt'], 'login');
    }

    public function start(string $prompt, string $requestId, array $images = []): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/', $requestId)) throw new \RuntimeException('依頼IDが不正です。');
        if ($this->requestId === $requestId) return;
        if (!$this->authenticated || $this->phase !== 'ready') throw new \RuntimeException('Codexへの接続を確認してください。実行中の場合は完了を待ってください。');
        if (count($images) > 3) throw new \RuntimeException('画像は3枚まで添付できます。');
        $validated = []; $total = 0;
        foreach ($images as $image) {
            $url = $image['url'] ?? null;
            $name = $image['name'] ?? 'スクリーンショット';
            if (!is_string($url) || strlen($url) > 7_000_000 || !preg_match('~^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=]+)$~D', $url, $match)) {
                throw new \RuntimeException('PNG・JPEG・WebPの画像を添付してください。');
            }
            $bytes = base64_decode($match[2], true);
            if ($bytes === false || base64_encode($bytes) !== $match[2] || strlen($bytes) > 5 * 1024 * 1024) throw new \RuntimeException('画像は1枚5MBまでです。');
            $total += strlen($bytes);
            if ($total > 10 * 1024 * 1024) throw new \RuntimeException('画像の合計は10MBまでです。');
            $info = @getimagesizefromstring($bytes);
            if (!$info || ($info['mime'] ?? '') !== $match[1] || $info[0] * $info[1] > 40_000_000) throw new \RuntimeException('画像を読み取れません。サイズや形式を確認してください。');
            if (!is_string($name) || mb_strlen($name) > 200 || preg_match('/[\x00-\x1f]/', $name)) throw new \RuntimeException('画像名を確認してください。');
            $validated[] = ['url'=>$url,'name'=>$name];
        }
        if (trim($prompt) === '' && $validated) $prompt = '添付したスクリーンショットを確認してください。';
        if (trim($prompt)==='' || mb_strlen($prompt)>16000) throw new \RuntimeException('依頼内容は16000文字以内で入力してください。');
        $this->requestId = $requestId; $this->phase = 'running'; $this->queued = $prompt; $this->error = '';
        $this->queuedImages = $validated;
        $this->record('user', $prompt.($validated ? "\n\n添付画像：".implode('、', array_column($validated, 'name')) : ''));
        $this->save();
        if ($this->threadLoaded) { $this->beginTurn(); return; }
        $params = ['cwd'=>$this->root,'sandbox'=>'workspace-write','approvalPolicy'=>'on-request','approvalsReviewer'=>'user',
            'developerInstructions'=>'あなたはRISE GATE OSから呼び出された開発担当です。ユーザーが選択した作業フォルダで、依頼に必要なファイルだけを調査し、編集・実行・テストしてください。日本語で簡潔に進捗を伝えてください。PHPとPDO SQLiteを使用できます。PHPアプリは公開ファイルをpublic/、DBをdata/へ分離します。初回設定はアプリ自身で案内してください。日時はAsia/Tokyo。OSのログインやAPIへ依存させない独立アプリを作ります。既存データと秘密情報を保護し、破壊的変更、公開、Git push、追加ソフトの導入はユーザーの明示した依頼の範囲だけで行います。実施していない保存・テストを完了したと報告しないでください。挨拶や説明では不要なファイルを読みません。'];
        if ($this->thread) $params['threadId'] = $this->thread;
        $this->rpc($this->thread ? 'thread/resume' : 'thread/start', $params, 'thread');
    }

    public function approve(array $input): void
    {
        $id = (string)($input['id'] ?? '');
        $approval = $this->approvals[$id] ?? null;
        if (!$approval) throw new \RuntimeException('この確認はすでに終了しています。', 409);
        if ($approval['method'] === 'item/tool/requestUserInput') {
            $answers = [];
            foreach ($approval['params']['questions'] ?? [] as $question) {
                $answer = $input['answers'][$question['id']] ?? '';
                if (!is_string($answer) || strlen($answer)>16000) throw new \RuntimeException('回答を確認してください。');
                $answers[$question['id']] = ['answers'=>[$answer]];
            }
            $result = ['answers'=>(object)$answers];
        } else {
            if (!in_array($input['decision'] ?? '', ['accept','decline'], true)) throw new \RuntimeException('承認の選択が不正です。');
            $result = ['decision'=>$input['decision']];
        }
        $this->send(['id'=>$approval['id'],'result'=>$result]);
        unset($this->approvals[$id]);
    }

    public function interrupt(): void
    {
        if ($this->turn) $this->rpc('turn/interrupt', ['threadId'=>$this->thread,'turnId'=>$this->turn], 'interrupt');
        elseif ($this->busy()) { $this->queued = null; $this->close(); }
    }

    public function close(): void
    {
        if (!is_resource($this->process)) return;
        $this->save();
        if ($this->turn) { try { $this->rpc('turn/interrupt', ['threadId'=>$this->thread,'turnId'=>$this->turn], 'interrupt'); } catch (\Throwable) {} }
        @fclose($this->input);
        // EOF lets app-server clean up owned commands before exiting.
        for ($i=0;$i<50;$i++) { if (!proc_get_status($this->process)['running']) break; usleep(20000); }
        if (proc_get_status($this->process)['running']) proc_terminate($this->process);
        proc_close($this->process); $this->process = null;
        fclose($this->output);
        foreach ($this->logPaths as $path) @unlink($path);
        $this->phase = 'failed';
    }
}