<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LocalDevelopmentTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private mixed $helper = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('app/local-dev-test-'.bin2hex(random_bytes(6)));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->helper)) {
            // Ask the helper to stop children before ending the helper process.
            try {
                $this->api('stop');
            } catch (\Throwable) {
            }
            proc_terminate($this->helper);
            proc_close($this->helper);
        }
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private string $base;

    private string $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function request(string $url, string $method = 'GET', array $headers = [], string $body = ''): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body,
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
        ]]);
        $result = file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header;
        preg_match('/\s(\d{3})\s/', $responseHeaders[0], $status);

        return [(int) $status[1], $result, $responseHeaders];
    }

    private function api(string $action, array $args = [], ?string $origin = null, ?string $token = null): array
    {
        [$status, $body, $headers] = $this->request($this->base.'/api', 'POST', [
            'Origin: '.($origin ?? 'http://localhost'),
            'X-RiseGate-Token: '.($token ?? $this->token), 'Content-Type: application/json',
        ], json_encode(['action' => $action, 'project' => 'test-project', 'workspace' => hash('sha256', realpath($this->directory.'/project') ?: ''), ...$args]));

        return [$status, json_decode($body, true), $headers];
    }

    private function bootHelper(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr(strrchr($address, ':'), 1);
        $this->base = 'http://'.$address;
        mkdir($this->directory.'/project');
        file_put_contents($this->directory.'/config.json', json_encode([
            'port' => $port, 'token' => $this->token, 'origins' => ['http://localhost'],
        ]));
        file_put_contents($this->directory.'/projects.json', json_encode([
            hash('sha256', 'http://localhost/test-project') => realpath($this->directory.'/project'),
        ]));
        $this->helper = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tools/local-dev/helper.php'), $this->directory.'/config.json'], [
            0 => ['pipe', 'r'], 1 => ['file', $this->directory.'/stdout.log', 'a'], 2 => ['file', $this->directory.'/stderr.log', 'a'],
        ], $pipes, base_path(), null, ['bypass_shell' => true]);
        fclose($pipes[0]);
        for ($i = 0; $i < 50; $i++) {
            $connection = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
            if ($connection) {
                fclose($connection);

                return;
            }
            usleep(50000);
        }
        $this->fail('Helper did not start: '.file_get_contents($this->directory.'/stderr.log'));
    }

    public function test_helper_authorizes_requests_preserves_files_and_exports_without_private_data(): void
    {
        $this->bootHelper();
        $this->assertSame(403, $this->api('status', [], 'https://untrusted.example')[0]);
        $this->assertSame(403, $this->api('status', [], null, 'wrong')[0]);
        [$status, $state] = $this->api('status');
        $this->assertSame(200, $status);
        $this->assertTrue($state['sqlite']);
        $this->assertStringEndsWith('+09:00', $state['time']);
        $this->assertSame(403, $this->request($this->base.'/', 'GET', ['Origin: http://localhost'])[0]);
        foreach (['../outside.txt', '/absolute', 'C:/outside', '.env', 'data/todo.sqlite', 'public/../../outside', 'CON', 'folder./x'] as $path) {
            $this->assertSame(422, $this->api('create', ['path' => $path, 'kind' => 'file'])[0], $path);
        }
        $this->assertSame(200, $this->api('create', ['path' => 'public', 'kind' => 'directory'])[0]);
        $this->assertSame(200, $this->api('create', ['path' => 'public/index.html', 'kind' => 'file'])[0]);
        $contents = '<h1>日本時間で保存</h1>';
        $this->assertSame(200, $this->api('write', ['path' => 'public/index.html', 'content' => base64_encode($contents), 'expected' => hash('sha256', '')])[0]);
        $this->assertSame(409, $this->api('write', ['path' => 'public/index.html', 'content' => base64_encode('stale'), 'expected' => hash('sha256', '')])[0]);
        $this->assertSame($contents, file_get_contents($this->directory.'/project/public/index.html'));
        $this->assertSame(200, $this->api('read', ['path' => 'public/index.html'])[0]);
        $this->assertSame(409, $this->api('write', ['path' => 'public/index.html', 'workspace' => str_repeat('0', 64), 'content' => base64_encode('wrong folder'), 'expected' => hash('sha256', $contents)])[0]);
        mkdir($this->directory.'/project/data');
        file_put_contents($this->directory.'/project/data/todo.sqlite', 'private-data');
        mkdir($this->directory.'/project/.rise-gate');
        file_put_contents($this->directory.'/project/.rise-gate/history.json', 'private-history');
        [$status, $export] = $this->api('export');
        $this->assertSame(200, $status);
        file_put_contents($this->directory.'/export.zip', base64_decode($export['content']));
        $zip = new \ZipArchive;
        $zip->open($this->directory.'/export.zip');
        $this->assertSame($contents, $zip->getFromName('public/index.html'));
        $this->assertFalse($zip->getFromName('data/todo.sqlite'));
        $this->assertFalse($zip->getFromName('.rise-gate/history.json'));
        $zip->close();
    }

    private function appForm(string $url, array $values, string $cookie = ''): array
    {
        [$status, $page, $headers] = $this->request($url, 'GET', $cookie ? ['Cookie: '.$cookie] : []);
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $m)) {
                $cookie = $m[1];
            }
        }
        preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
        $this->assertNotEmpty($m[1] ?? null);
        [$status, $result, $headers] = $this->request($url, 'POST', ['Cookie: '.$cookie, 'Content-Type: application/x-www-form-urlencoded'], http_build_query(['csrf' => $m[1], ...$values]));
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $m)) {
                $cookie = $m[1];
            }
        }

        return [$status, $result, $cookie];
    }

    public function test_independent_todo_runs_with_login_isolation_jst_and_survives_restart(): void
    {
        $this->bootHelper();
        [$status, $result] = $this->api('seed', ['login' => 'owner', 'password' => 'local-test-password']);
        $this->assertSame(200, $status, json_encode($result));
        $this->assertSame(422, $this->api('seed', ['login' => 'other', 'password' => 'local-test-password'])[0]);
        [$status, $run] = $this->api('start');
        $this->assertSame(200, $status, json_encode($run));
        $url = $run['url'];
        $this->assertSame(404, $this->request($url.'../data/todo.sqlite')[0]);
        [$status, , $admin] = $this->appForm($url, ['action' => 'login', 'login' => 'owner', 'password' => 'local-test-password']);
        $this->assertSame(303, $status);
        [$status] = $this->appForm($url, ['action' => 'account', 'login' => 'staff1', 'password' => 'staff-test-password', 'role' => 'staff'], $admin);
        $this->assertSame(303, $status);
        [$status] = $this->appForm($url, ['action' => 'add', 'title' => '<script>example</script>'], $admin);
        $this->assertSame(303, $status);
        [$status, $page] = $this->request($url, 'GET', ['Cookie: '.$admin]);
        $this->assertStringContainsString('&lt;script&gt;example&lt;/script&gt;', $page);
        $this->assertStringContainsString('JST', $page);
        [$status, , $staff] = $this->appForm($url, ['action' => 'login', 'login' => 'staff1', 'password' => 'staff-test-password']);
        $this->assertSame(303, $status);
        [, $page] = $this->request($url, 'GET', ['Cookie: '.$staff]);
        $this->assertStringNotContainsString('example', $page);
        [, $page] = $this->appForm($url, ['action' => 'add', 'account_id' => 1, 'title' => 'unauthorized'], $staff);
        $this->assertStringContainsString('他のスタッフのデータは変更できません', $page);
        $this->appForm($url, ['action' => 'add', 'title' => 'staff work'], $staff);
        [, $page] = $this->request($url.'?account=2', 'GET', ['Cookie: '.$admin]);
        $this->assertStringContainsString('staff work', $page);
        $db = new \PDO('sqlite:'.$this->directory.'/project/data/todo.sqlite');
        $row = $db->query('SELECT * FROM accounts WHERE login="owner"')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotSame('local-test-password', $row['password']);
        $this->assertTrue(password_verify('local-test-password', $row['password']));
        $this->assertStringEndsWith('+09:00', $db->query('SELECT created_at FROM todos LIMIT 1')->fetchColumn());
        $db = null;
        $this->assertSame(200, $this->api('stop')[0]);
        [, $run] = $this->api('start');
        [$status, , $newSession] = $this->appForm($run['url'], ['action' => 'login', 'login' => 'staff1', 'password' => 'staff-test-password']);
        $this->assertSame(303, $status);
        [, $page] = $this->request($run['url'], 'GET', ['Cookie: '.$newSession]);
        $this->assertStringContainsString('staff work', $page);
    }

    public function test_setup_download_requires_os_login_and_contains_no_runtime_credentials(): void
    {
        $this->get(route('development.download'))->assertRedirect(route('login'));
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('development.setup'))->assertOk()->assertSee('初回');
        $response = $this->actingAs($user)->get(route('development.download'));
        $response->assertOk()->assertDownload('RiseGateDev-Windows.zip');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($path);
        $this->assertNotFalse($zip->getFromName('RiseGateDev/helper.php'));
        $this->assertFalse($zip->getFromName('RiseGateDev/config.json'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $zip->getFromName('RiseGateDev/install.ps1'));
        $zip->close();
        unlink($path);
    }
}
