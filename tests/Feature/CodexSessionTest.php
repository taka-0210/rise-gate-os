<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use RiseGate\LocalDev\CodexSession;
use Tests\TestCase;

class CodexSessionTest extends TestCase
{
    private string $directory;

    private ?CodexSession $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('tools/local-dev/CodexSession.php');
        $this->directory = storage_path('app/codex-test-'.bin2hex(random_bytes(6)));
        mkdir($this->directory);
        file_put_contents($this->directory.'/server.php', <<<'PHP'
<?php
function emitMessage($message) { echo json_encode($message)."\n"; flush(); }
while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);
    file_put_contents(__DIR__.'/requests.jsonl', $line, FILE_APPEND);
    $id = $message['id'] ?? null;
    $method = $message['method'] ?? '';
    if ($method === 'initialize') emitMessage(['id'=>$id,'result'=>['userAgent'=>'test']]);
    elseif ($method === 'account/read') emitMessage(['id'=>$id,'result'=>['account'=>['type'=>'chatgpt']]]);
    elseif (in_array($method, ['thread/start','thread/resume'], true)) emitMessage(['id'=>$id,'result'=>['thread'=>['id'=>'thread-test']]]);
    elseif ($method === 'turn/start') {
        emitMessage(['id'=>$id,'result'=>['turn'=>['id'=>'turn-test']]]);
        emitMessage(['method'=>'turn/started','params'=>['turn'=>['id'=>'turn-test']]]);
        emitMessage(['id'=>9000,'method'=>'item/commandExecution/requestApproval','params'=>['command'=>'php -l public/index.php','reason'=>'PHPの構文確認']]);
    } elseif ($method === 'turn/interrupt') {
        emitMessage(['id'=>$id,'result'=>[]]);
        emitMessage(['method'=>'turn/completed','params'=>['turn'=>['id'=>'turn-test','status'=>'interrupted']]]);
    } elseif ($id === 9000 && isset($message['result'])) {
        emitMessage(['method'=>'item/completed','params'=>['item'=>['id'=>'answer','type'=>'agentMessage','text'=>'確認が完了しました。']]]);
        emitMessage(['method'=>'turn/completed','params'=>['turn'=>['id'=>'turn-test','status'=>'completed']]]);
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->session?->close();
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function connect(): void
    {
        $this->session = new CodexSession($this->directory, $this->directory, 'test', [PHP_BINARY, $this->directory.'/server.php']);
        $this->await(fn ($state) => $state['phase'] === 'ready');
    }

    private function await(callable $predicate): array
    {
        for ($i = 0; $i < 100; $i++) {
            $state = $this->session->state();
            if ($predicate($state)) {
                return $state;
            }
            usleep(20000);
        }
        $this->fail('Codex protocol state timed out: '.json_encode($state));
    }

    public function test_conversation_requires_approval_and_resumes_with_workspace_sandbox_and_jst(): void
    {
        $this->connect();
        $this->session->start('PHPアプリを作って', 'request-0000000001');
        $state = $this->await(fn ($state) => count($state['approvals']) === 1);
        $this->assertSame('running', $state['phase']);
        $this->assertSame('php -l public/index.php', $state['approvals'][0]['params']['command']);
        $this->session->start('duplicate request', 'request-0000000001');
        $this->assertCount(1, array_filter($state['history'], fn ($item) => $item['role'] === 'user'));
        $this->session->approve(['id' => 9000, 'decision' => 'accept']);
        $state = $this->await(fn ($state) => $state['phase'] === 'ready');
        $this->assertStringContainsString('確認が完了', json_encode($state['history'], JSON_UNESCAPED_UNICODE));
        $this->assertStringEndsWith('+09:00', $state['history'][0]['time']);
        $this->session->close();
        $this->connect();
        $this->session->start('続けてください', 'request-0000000002');
        $this->await(fn ($state) => count($state['approvals']) === 1);
        $messages = array_map(fn ($line) => json_decode($line, true), file($this->directory.'/requests.jsonl'));
        $threads = array_values(array_filter($messages, fn ($m) => in_array($m['method'] ?? '', ['thread/start', 'thread/resume'], true)));
        $this->assertSame('thread/resume', $threads[1]['method']);
        foreach ($threads as $thread) {
            $this->assertSame($this->directory, $thread['params']['cwd']);
            $this->assertSame('workspace-write', $thread['params']['sandbox']);
            $this->assertSame('on-request', $thread['params']['approvalPolicy']);
            $this->assertSame('user', $thread['params']['approvalsReviewer']);
        }
        $this->session->interrupt();
        $this->await(fn ($state) => $state['phase'] === 'ready');
    }

    public function test_stale_approval_cannot_execute_a_command(): void
    {
        $this->connect();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);
        $this->session->approve(['id' => 9000, 'decision' => 'accept']);
    }
}
