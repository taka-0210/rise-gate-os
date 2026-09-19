<?php

namespace Tests\Feature;

use App\Models\AccountEmailRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('unused');
    }

    public function test_guest_cannot_open_account_and_orgless_user_can(): void
    {
        $this->get(route('account.profile'))->assertRedirect(route('login'));

        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get(route('account.profile'))
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee($user->email)
            ->assertSee('未確認');
        $this->actingAs($user)->get(route('development.setup'))->assertOk();
    }

    public function test_profile_only_updates_current_users_name_and_escapes_output(): void
    {
        $user = User::factory()->create(['email' => 'one@example.com', 'is_active' => true]);
        $other = User::factory()->create(['name' => 'Other']);

        $this->actingAs($user)->patch(route('account.profile.update'), [
            'name' => '<script>alert(1)</script>',
            'email' => 'attacker@example.com',
            'is_active' => false,
            'email_verified_at' => null,
            'user_id' => $other->id,
            'role' => 'owner',
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('<script>alert(1)</script>', $user->name);
        $this->assertSame('one@example.com', $user->email);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('Other', $other->fresh()->name);
        $this->actingAs($user)->get(route('account.profile'))
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_password_change_requires_current_password_and_invalidates_only_subject_sessions_and_requests(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $other = User::factory()->create();
        $oldRememberToken = $user->remember_token;
        DB::table('sessions')->insert([
            ['id' => 'subject-one', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'subject-two', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'other-one', 'user_id' => $other->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('secret'),
            'credential_generation' => 1,
            'created_at' => now(),
        ]);
        AccountEmailRequest::query()->create([
            'public_id' => '01K00000000000000000000000',
            'user_id' => $user->id,
            'purpose' => AccountEmailRequest::PURPOSE_CHANGE_EMAIL,
            'current_email' => $user->email,
            'pending_email' => 'pending@example.com',
            'token_hash' => hash('sha256', 'token'),
            'credential_generation' => 1,
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertGuest();
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertSame(2, $user->credential_generation);
        $this->assertNotSame($oldRememberToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other-one', 'user_id' => $other->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('account_email_requests', ['user_id' => $user->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('account_security_events', ['user_id' => $user->id, 'event' => 'account.password_changed', 'outcome' => 'success']);
    }

    public function test_stale_credential_generation_is_rejected_on_next_request(): void
    {
        $user = User::factory()->create(['credential_generation' => 2]);

        $this->actingAs($user)
            ->withSession(['credential_generation' => 1])
            ->get(route('account.profile'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_legacy_session_without_generation_is_rejected_after_credentials_rotate(): void
    {
        $user = User::factory()->create(['credential_generation' => 2]);

        $this->actingAs($user)->get(route('account.profile'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_general_and_system_admin_login_are_rate_limited_after_failed_threshold(): void
    {
        config()->set('account.login.max_attempts_per_minute', 2);
        config()->set('account.login.max_attempts_per_ip_per_minute', 20);
        $admin = User::factory()->create(['email' => 'admin@example.com', 'is_system_admin' => true]);

        foreach (range(1, 2) as $_) {
            $this->post(route('login'), ['email' => 'missing@example.com', 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }
        $this->post(route('login'), ['email' => 'MISSING@example.com', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        RateLimiter::clear('account-login:identity:'.hash('sha256', 'missing@example.com|127.0.0.1'));
        foreach (range(1, 2) as $_) {
            $this->post(route('system-admin.login.store'), ['email' => $admin->email, 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }
        $this->post(route('system-admin.login.store'), ['email' => strtoupper($admin->email), 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_login_logout_and_bootstrap_entry_behavior_are_preserved(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('初期セットアップ');
        $user = User::factory()->create(['email' => 'login@example.com', 'password' => 'password-test']);
        $this->get(route('login'))->assertOk()->assertDontSee('初期セットアップ')->assertSee('Passwordを忘れた場合');

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password-test'])
            ->assertSessionHas('credential_generation', 1);
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect(route('welcome'));
        $this->assertGuest();
        $this->get(route('register'))->assertNotFound();
    }

    public function test_profile_identity_is_shared_without_changing_multiple_organization_memberships(): void
    {
        $user = User::factory()->create(['name' => 'Before']);
        $first = Organization::query()->create(['name' => 'First', 'slug' => 'first']);
        $second = Organization::query()->create(['name' => 'Second', 'slug' => 'second']);
        $first->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $second->users()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);

        $this->actingAs($user)->patch(route('account.profile.update'), ['name' => 'After'])->assertRedirect();

        $this->assertSame('After', $user->fresh()->name);
        $this->assertDatabaseHas('organization_users', ['organization_id' => $first->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('organization_users', ['organization_id' => $second->id, 'user_id' => $user->id, 'role' => 'member']);
    }

    public function test_login_has_an_ip_wide_limit_across_different_normalized_emails_and_recovers(): void
    {
        config()->set('account.login.max_attempts_per_minute', 100);
        config()->set('account.login.max_attempts_per_ip_per_minute', 2);

        $this->post(route('login'), ['email' => 'first@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => 'second@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => 'third@example.com', 'password' => 'wrong'])->assertStatus(429);

        $this->travel(61)->seconds();
        $this->post(route('login'), ['email' => 'third@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
    }
}
