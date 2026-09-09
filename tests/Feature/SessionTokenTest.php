<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_obtain_an_authenticated_session_token(): void
    {
        $this->getJson(route('session.token'))->assertUnauthorized();
    }

    public function test_refresh_returns_existing_token_and_identity_without_caching(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->withSession(['_token' => 'existing-token'])
            ->getJson(route('session.token'))
            ->assertOk()->assertJsonPath('token', 'existing-token')
            ->assertJsonPath('user_id', (string) $user->id);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('existing-token', session()->token());
    }
}
