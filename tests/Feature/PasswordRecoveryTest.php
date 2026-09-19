<?php

namespace Tests\Feature;

use App\Jobs\SendAccountActionMail;
use App\Mail\AccountActionMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
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

    public function test_forgot_response_does_not_enumerate_missing_or_inactive_accounts(): void
    {
        $active = User::factory()->create(['email' => 'active@example.com']);
        User::factory()->create(['email' => 'inactive@example.com', 'is_active' => false]);

        $activeResponse = $this->post(route('password.email'), ['email' => $active->email]);
        $message = session('status');
        $missingResponse = $this->post(route('password.email'), ['email' => 'missing@example.com']);
        $inactiveResponse = $this->post(route('password.email'), ['email' => 'inactive@example.com']);

        $activeResponse->assertRedirect();
        $missingResponse->assertRedirect();
        $inactiveResponse->assertRedirect();
        $this->assertSame($message, session('status'));
        Mail::assertSent(AccountActionMail::class, 1);
        $this->assertDatabaseCount('users', 2);
        $this->assertFalse(User::where('email', 'inactive@example.com')->firstOrFail()->is_active);
    }

    public function test_reset_token_is_hashed_versioned_single_use_and_completes_login_recovery(): void
    {
        $verifiedAt = now()->subDay()->startOfSecond();
        $user = User::factory()->create([
            'email' => 'recover@example.com',
            'password' => 'old-password',
            'email_verified_at' => $verifiedAt,
        ]);
        $url = $this->requestResetUrl($user->email);
        [$token, $email] = $this->resetParts($url);
        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        $this->assertNotNull($row);
        $this->assertNotSame($token, $row->token);
        $this->assertTrue(Hash::check($token, $row->token));
        $this->assertSame(1, (int) $row->credential_generation);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
        $this->assertSame(2, $user->credential_generation);
        $this->assertTrue($verifiedAt->equalTo($user->email_verified_at));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $email,
            'password' => 'third-password',
            'password_confirmation' => 'third-password',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));

        $this->post(route('login'), ['email' => $user->email, 'password' => 'new-password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_expired_tampered_wrong_user_and_old_generation_reset_tokens_are_rejected(): void
    {
        $user = User::factory()->create(['email' => 'one@example.com', 'password' => 'old-password']);
        $other = User::factory()->create(['email' => 'two@example.com', 'password' => 'other-password']);
        $url = $this->requestResetUrl($user->email);
        [$token] = $this->resetParts($url);

        $this->post(route('password.update'), [
            'token' => $token.'tampered',
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('email');
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $other->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('email');

        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('email');

        DB::table('password_reset_tokens')->where('email', $user->email)->update([
            'created_at' => now(),
            'credential_generation' => 0,
        ]);
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
        $this->assertTrue(Hash::check('other-password', $other->fresh()->password));
    }

    public function test_inactive_account_is_not_reactivated_by_reset(): void
    {
        $user = User::factory()->create(['email' => 'inactive@example.com', 'is_active' => true]);
        $url = $this->requestResetUrl($user->email);
        [$token] = $this->resetParts($url);
        $user->update(['is_active' => false]);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('email');

        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_encrypted_queue_payload_does_not_store_recipient_token_or_url_in_plaintext(): void
    {
        config()->set('queue.default', 'database');
        $user = User::factory()->create(['email' => 'secret@example.com']);
        $token = 'plain-reset-token-must-not-appear';
        $url = 'https://company-os.jp/reset-password/'.$token;

        SendAccountActionMail::dispatch(
            $user->id,
            $user->email,
            'account.test.mail',
            'Subject',
            'Intro',
            $url,
            'Action',
            'array',
        );

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertNotSame('', $payload);
        $this->assertStringNotContainsString($token, $payload);
        $this->assertStringNotContainsString($user->email, $payload);
        $this->assertStringNotContainsString($url, $payload);
    }

    private function requestResetUrl(string $email): string
    {
        $url = null;
        $this->post(route('password.email'), ['email' => $email])->assertRedirect();
        Mail::assertSent(AccountActionMail::class, function (AccountActionMail $mail) use (&$url): bool {
            if (! str_contains($mail->mailSubject, 'パスワード')) {
                return false;
            }
            $url = $mail->actionUrl;

            return true;
        });
        $this->assertIsString($url);

        return $url;
    }

    /** @return array{string, string} */
    private function resetParts(string $url): array
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);

        return [rawurldecode(basename($parts['path'])), (string) ($query['email'] ?? '')];
    }
}
