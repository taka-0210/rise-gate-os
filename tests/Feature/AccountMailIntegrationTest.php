<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountMailIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_test_inbox_delivers_a_real_rendered_link_that_can_be_consumed(): void
    {
        config()->set('account.mail.mailer', 'array');
        config()->set('queue.default', 'sync');
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $transport->flush();
        $user = User::factory()->unverified()->create(['email' => 'inbox@example.com']);

        $this->actingAs($user)->post(route('account.email.verify'))->assertRedirect();

        $this->assertCount(1, $transport->messages());
        $message = $transport->messages()->last()->getOriginalMessage();
        $html = $message->getHtmlBody();
        preg_match('/href="([^"]+)"/', $html, $matches);
        $url = html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/account/email/confirm/', $url);
        $this->actingAs($user)->get($url)->assertRedirect(route('account.profile'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_unsafe_log_mailer_fails_safely_without_applying_email_change_and_can_retry(): void
    {
        config()->set('account.mail.max_per_minute', 100);
        config()->set('account.mail.max_per_hour', 100);
        $user = User::factory()->create(['email' => 'old@example.com', 'password' => 'password-test']);
        config()->set('account.mail.mailer', 'log');

        $this->actingAs($user)->post(route('account.email.change'), [
            'email' => 'new@example.com',
            'current_password' => 'password-test',
        ])->assertSessionHasErrors('email');

        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->assertDatabaseMissing('account_email_requests', ['pending_email' => 'new@example.com']);

        config()->set('account.mail.mailer', 'array');
        Mail::fake();
        $this->actingAs($user)->post(route('account.email.change'), [
            'email' => 'new@example.com',
            'current_password' => 'password-test',
        ])->assertRedirect();
        $this->assertDatabaseHas('account_email_requests', ['pending_email' => 'new@example.com', 'status' => 'pending']);
        Mail::assertSent(AccountActionMail::class, 1);
    }

    public function test_mail_and_token_entry_points_are_rate_limited_and_recover_after_decay(): void
    {
        Mail::fake();
        config()->set('account.mail.max_per_minute', 1);
        config()->set('account.mail.max_per_hour', 5);
        $user = User::factory()->create(['email' => 'limited@example.com']);

        $this->post(route('password.email'), ['email' => $user->email])->assertRedirect();
        $this->post(route('password.email'), ['email' => strtoupper($user->email)])->assertStatus(429);
        $this->travel(61)->seconds();
        $this->post(route('password.email'), ['email' => $user->email])->assertRedirect();

        config()->set('account.token.max_attempts_per_minute', 2);
        $payload = [
            'token' => 'invalid',
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ];
        $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
        $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
        $this->post(route('password.update'), $payload)->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_mail_hourly_limit_is_enforced_independently_from_minute_limit(): void
    {
        Mail::fake();
        config()->set('account.mail.max_per_minute', 100);
        config()->set('account.mail.max_per_hour', 2);

        $this->post(route('password.email'), ['email' => 'hourly@example.com'])->assertRedirect();
        $this->post(route('password.email'), ['email' => 'HOURLY@example.com'])->assertRedirect();
        $this->post(route('password.email'), ['email' => 'hourly@example.com'])->assertStatus(429);
    }
}
