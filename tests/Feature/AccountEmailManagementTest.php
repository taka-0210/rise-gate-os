<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Models\AccountEmailRequest;
use App\Models\AiAccessKey;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AccountEmailManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('account.mail.max_per_minute', 100);
        config()->set('account.mail.max_per_hour', 100);
        RateLimiter::clear('unused');
        Mail::fake();
    }

    public function test_current_email_verification_is_hashed_signed_scoped_and_single_use(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'verify@example.com']);
        $other = User::factory()->unverified()->create();
        $url = $this->requestCurrentVerification($user);
        $record = AccountEmailRequest::query()->where('user_id', $user->id)->firstOrFail();
        $token = $this->queryValue($url, 'token');

        $this->assertNotSame($token, $record->token_hash);
        $this->assertSame(hash('sha256', $token), $record->token_hash);

        $this->actingAs($other)->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);

        $this->actingAs($user)->get($url)->assertRedirect(route('account.profile'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(AccountEmailRequest::STATUS_CONSUMED, $record->fresh()->status);
        $verifiedAt = $user->fresh()->email_verified_at;

        $this->actingAs($user)->get($url)->assertSessionHasErrors('email');
        $this->assertTrue($verifiedAt->equalTo($user->fresh()->email_verified_at));
    }

    public function test_tampered_expired_and_replaced_verification_links_do_not_verify(): void
    {
        $user = User::factory()->unverified()->create();
        $firstUrl = $this->requestCurrentVerification($user);

        $tampered = preg_replace('/token=[^&]+/', 'token=tampered', $firstUrl);
        $this->actingAs($user)->get($tampered)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);

        $secondUrl = $this->requestCurrentVerification($user);
        $this->actingAs($user)->get($firstUrl)->assertSessionHasErrors('email');
        $this->assertNull($user->fresh()->email_verified_at);

        AccountEmailRequest::query()->where('status', 'pending')->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($user)->get($secondUrl)->assertSessionHasErrors('email');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_change_requires_current_password_and_keeps_old_email_until_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'password' => 'password-test']);

        $this->actingAs($user)->post(route('account.email.change'), [
            'email' => 'new@example.com',
            'current_password' => 'wrong',
        ])->assertSessionHasErrors('current_password');
        $this->assertDatabaseMissing('account_email_requests', ['pending_email' => 'new@example.com']);

        $this->actingAs($user)->post(route('account.email.change'), [
            'email' => 'NEW@example.com',
            'current_password' => 'password-test',
        ])->assertRedirect();

        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->assertDatabaseHas('account_email_requests', [
            'user_id' => $user->id,
            'pending_email' => 'new@example.com',
            'status' => 'pending',
        ]);
        $this->post(route('login'), ['email' => 'old@example.com', 'password' => 'password-test']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_email_confirmation_atomically_changes_shared_identity_preserves_relations_and_reauthenticates(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'password' => 'password-test']);
        $organization = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'name' => 'Main', 'slug' => 'main']);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $key = AiAccessKey::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'Scope 2 invariant',
            'token_hash' => hash('sha256', 'scope-two-key'),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ],
            'created_by' => $user->id,
        ]);
        $url = $this->requestEmailChange($user, 'new@example.com');

        $this->actingAs($user)->get($url)->assertRedirect(route('login'));

        $user->refresh();
        $this->assertGuest();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(2, $user->credential_generation);
        $this->assertDatabaseHas('organization_users', ['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('ai_access_keys', ['id' => $key->id, 'user_id' => $user->id, 'revoked_at' => null]);
        $this->assertDatabaseHas('account_security_events', ['user_id' => $user->id, 'event' => 'account.email_changed']);
        Mail::assertSent(AccountActionMail::class, 2);

        $this->post(route('login'), ['email' => 'old@example.com', 'password' => 'password-test'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => 'new@example.com', 'password' => 'password-test']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_email_change_rejects_duplicate_latest_cancelled_and_old_generation_requests(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'password' => 'password-test']);
        User::factory()->create(['email' => 'TAKEN@example.com']);

        $this->actingAs($user)->post(route('account.email.change'), [
            'email' => 'taken@example.com',
            'current_password' => 'password-test',
        ])->assertSessionHasErrors('email');

        $firstUrl = $this->requestEmailChange($user, 'first@example.com');
        $secondUrl = $this->requestEmailChange($user, 'second@example.com');
        $this->actingAs($user)->get($firstUrl)->assertSessionHasErrors('email');
        $this->assertSame('old@example.com', $user->fresh()->email);

        $this->actingAs($user)->delete(route('account.email.change.cancel'))->assertRedirect();
        $this->actingAs($user)->get($secondUrl)->assertSessionHasErrors('email');
        $this->assertSame('old@example.com', $user->fresh()->email);

        $thirdUrl = $this->requestEmailChange($user, 'third@example.com');
        $user->increment('credential_generation');
        $this->actingAs($user->fresh())->get($thirdUrl)->assertSessionHasErrors('email');
        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    public function test_unauthenticated_email_link_does_not_store_intended_secret_and_requires_reopening(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->requestCurrentVerification($user);
        $this->post(route('logout'))->assertRedirect(route('welcome'));

        $this->get($url)->assertRedirect(route('login'));
        $this->assertNull(session('url.intended'));
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_explicit_email_change_resend_invalidates_the_old_link_and_the_new_link_can_complete(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'password' => 'password-test']);
        $firstUrl = $this->requestEmailChange($user, 'new@example.com');
        $before = Mail::sent(AccountActionMail::class)->count();

        $this->actingAs($user)->post(route('account.email.change.resend'))->assertRedirect();
        $secondUrl = $this->latestActionUrl($before);

        $this->actingAs($user)->get($firstUrl)->assertSessionHasErrors('email');
        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->actingAs($user)->get($secondUrl)->assertRedirect(route('login'));
        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_two_users_cannot_confirm_the_same_normalized_candidate_email(): void
    {
        $first = User::factory()->create(['email' => 'first@example.com', 'password' => 'password-test']);
        $second = User::factory()->create(['email' => 'second@example.com', 'password' => 'password-test']);
        $firstUrl = $this->requestEmailChange($first, 'shared@example.com');
        $secondUrl = $this->requestEmailChange($second, 'SHARED@example.com');

        $this->actingAs($first)->get($firstUrl)->assertRedirect(route('login'));
        $this->actingAs($second)->get($secondUrl)->assertSessionHasErrors('email');

        $this->assertSame('shared@example.com', $first->fresh()->email);
        $this->assertSame('second@example.com', $second->fresh()->email);
        $this->assertSame(1, User::query()->whereRaw('LOWER(email) = ?', ['shared@example.com'])->count());
    }

    private function requestCurrentVerification(User $user): string
    {
        $before = Mail::sent(AccountActionMail::class)->count();
        $this->actingAs($user)->post(route('account.email.verify'))->assertRedirect();

        return $this->latestActionUrl($before);
    }

    private function requestEmailChange(User $user, string $candidate): string
    {
        $before = Mail::sent(AccountActionMail::class)->count();
        $this->actingAs($user)->post(route('account.email.change'), [
            'email' => $candidate,
            'current_password' => 'password-test',
        ])->assertRedirect();

        return $this->latestActionUrl($before);
    }

    private function latestActionUrl(int $before): string
    {
        $sent = Mail::sent(AccountActionMail::class);
        $this->assertGreaterThan($before, $sent->count());
        $url = $sent->last()->actionUrl;
        $this->assertIsString($url);

        return $url;
    }

    private function queryValue(string $url, string $key): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

        return (string) ($query[$key] ?? '');
    }
}
