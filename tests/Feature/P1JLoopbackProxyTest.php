<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

class P1JLoopbackProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_loopback_https_proxy_generates_https_login_targets(): void
    {
        TrustProxies::at(['127.0.0.1']);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'Host' => 'localhost:8443',
                'X-Forwarded-Host' => 'localhost:8443',
                'X-Forwarded-Port' => '8443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/login');

        $response->assertOk();
        $response->assertSee('https://localhost:8443/login', false);
        $response->assertSee('https://localhost:8443', false);
    }
}
