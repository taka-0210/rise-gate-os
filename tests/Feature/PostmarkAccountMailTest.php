<?php

namespace Tests\Feature;

use App\Jobs\SendAccountActionMail;
use App\Mail\AccountActionMail;
use App\Services\AccountAudit;
use App\Services\AccountMailDelivery;
use App\Services\AccountMailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class PostmarkAccountMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_dedupes_same_identity_and_rejects_payload_reuse(): void
    {
        config(['account.mail.mailer' => 'array']);
        Mail::fake();
        $service = app(AccountMailDelivery::class);
        $this->assertTrue($service->send('event:1', 'recipient@example.test', 'array', new AccountActionMail('subject', 'intro')));
        $this->assertFalse($service->send('event:1', 'recipient@example.test', 'array', new AccountActionMail('subject', 'intro')));
        Mail::assertSent(AccountActionMail::class, 1);
        $row = DB::table('account_mail_deliveries')->first();
        $this->assertSame('accepted', $row->status);
        $this->assertStringNotContainsString('recipient@example.test', json_encode($row));
        $this->expectExceptionMessage('ACCOUNT_MAIL_DEDUPE_PAYLOAD_MISMATCH');
        $service->send('event:1', 'recipient@example.test', 'array', new AccountActionMail('changed', 'intro'));
    }

    public function test_ambiguous_send_is_not_retried_and_provider_exception_is_sanitized(): void
    {
        config(['account.mail.mailer' => 'array']);
        Mail::shouldReceive('mailer')->andReturn(new class
        {
            public int $calls = 0;

            public function render($view, $data): string
            {
                return 'fixture';
            }

            public function to($recipient): self
            {
                return $this;
            }

            public function send($mail): void
            {
                throw new \RuntimeException('private-token-body');
            }
        });
        for ($i = 0; $i < 2; $i++) {
            try {
                app(AccountMailDelivery::class)->send('unknown:1', 'recipient@example.test', 'array', new AccountActionMail('subject', 'intro'));
                $this->fail('Must stop.');
            } catch (\RuntimeException $e) {
                $this->assertSame('ACCOUNT_MAIL_DELIVERY_UNKNOWN', $e->getMessage());
                $this->assertNull($e->getPrevious());
            }
        }
        $this->assertDatabaseHas('account_mail_deliveries', ['status' => 'delivery_unknown', 'attempts' => 1]);
    }

    public function test_postmark_api_transport_uses_server_token_stream_and_returns_provider_id_without_network(): void
    {
        $client = new MockHttpClient(function ($method, $url, $options) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.postmarkapp.com/email', $url);
            $payload = json_decode($options['body'], true);
            $this->assertSame('account-lifecycle', $payload['MessageStream']);
            $this->assertStringContainsString('X-Postmark-Server-Token: fixture-token', implode('\n', $options['headers']));

            return new MockResponse(json_encode(['MessageID' => 'provider-fixture-id', 'ErrorCode' => 0]));
        });
        $transport = new PostmarkApiTransport('fixture-token', $client);
        $transport->setMessageStream('account-lifecycle');
        $sent = $transport->send((new Email)->from('from@example.test')->to('to@example.test')->subject('test')->text('test'));
        $this->assertSame('provider-fixture-id', $sent->getMessageId());
    }

    public function test_webhook_requires_https_basic_auth_and_actual_peer_allowlist(): void
    {
        $this->configureWebhook();
        $this->postJson('/api/webhooks/postmark/account-mail', [])->assertForbidden();
        $this->withServerVariables(['HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('https://localhost/api/webhooks/postmark/account-mail', [])->assertUnauthorized();
        $this->withServerVariables(['HTTPS' => 'on', 'REMOTE_ADDR' => '192.0.2.1', 'PHP_AUTH_USER' => 'fixture', 'PHP_AUTH_PW' => 'fixture-password'])
            ->withHeader('X-Forwarded-For', '127.0.0.1')->postJson('https://localhost/api/webhooks/postmark/account-mail', [])->assertForbidden();
    }

    public function test_webhook_dedupes_events_preserves_complaint_against_late_delivery_and_uses_jst(): void
    {
        $this->configureWebhook();
        DB::table('account_mail_deliveries')->insert([
            'public_id' => (string) Str::uuid(), 'dedupe_key' => str_repeat('a', 64),
            'payload_hash' => str_repeat('b', 64), 'recipient_hash' => str_repeat('c', 64),
            'provider' => 'postmark', 'provider_message_id' => 'fixture-id', 'status' => 'accepted',
        ]);
        $this->withServerVariables(['HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1', 'PHP_AUTH_USER' => 'fixture', 'PHP_AUTH_PW' => 'fixture-password']);
        $event = ['RecordType' => 'SpamComplaint', 'ServerID' => 23, 'MessageID' => 'fixture-id', 'MessageStream' => 'account-lifecycle', 'ID' => 123, 'BouncedAt' => '2026-10-07T01:00:00Z'];
        $this->postJson('https://localhost/api/webhooks/postmark/account-mail', $event)->assertOk();
        $this->postJson('https://localhost/api/webhooks/postmark/account-mail', $event)->assertOk();
        $this->assertDatabaseCount('account_mail_provider_events', 1);
        $this->assertDatabaseHas('account_mail_deliveries', ['status' => 'complained', 'event_at' => '2026-10-07 10:00:00']);
        $this->postJson('https://localhost/api/webhooks/postmark/account-mail', ['RecordType' => 'Delivery', 'ServerID' => 23, 'MessageID' => 'fixture-id', 'MessageStream' => 'account-lifecycle', 'DeliveredAt' => '2026-10-07T00:59:00Z'])->assertOk();
        $this->assertDatabaseHas('account_mail_deliveries', ['status' => 'complained']);
        $event['MessageStream'] = 'wrong';
        $this->postJson('https://localhost/api/webhooks/postmark/account-mail', $event)->assertUnprocessable();
    }

    private function configureWebhook(): void
    {
        config(['account_delivery.webhook' => ['enabled' => true, 'user' => 'fixture', 'password' => 'fixture-password', 'allowed_cidrs' => ['127.0.0.1/32'], 'stream' => 'account-lifecycle', 'server_id' => 23]]);
    }

    public function test_old_queued_job_identity_and_same_event_dispatch_do_not_double_send(): void
    {
        config(['account.mail.mailer' => 'array']);
        Mail::fake();
        $first = new SendAccountActionMail(null, 'recipient@example.test', 'event', 'subject', 'intro', 'https://localhost/fixture', 'open', 'array');
        $older = clone $first;
        (new \ReflectionProperty($older, 'deliveryIdentity'))->setValue($older, '');
        $first->handle(app(AccountAudit::class));
        $older->handle(app(AccountAudit::class));
        Mail::assertSent(AccountActionMail::class, 1);
        $this->assertDatabaseCount('account_mail_deliveries', 1);
    }

    public function test_postmark_configuration_fails_closed_without_token_or_stream(): void
    {
        config(['account.mail.mailer' => 'postmark', 'services.postmark.key' => '',
            'mail.mailers.postmark.message_stream_id' => 'account-lifecycle']);
        try {
            app(AccountMailDispatcher::class)->assertConfigured();
            $this->fail('Missing token must stop.');
        } catch (\RuntimeException $e) {
            $this->assertSame('ACCOUNT_MAIL_POSTMARK_CONFIGURATION_INCOMPLETE', $e->getMessage());
        }
        config(['services.postmark.key' => 'private-fixture-token', 'mail.mailers.postmark.message_stream_id' => '']);
        $this->expectExceptionMessage('ACCOUNT_MAIL_POSTMARK_CONFIGURATION_INCOMPLETE');
        app(AccountMailDispatcher::class)->assertConfigured();
    }

    public function test_status_monitor_reports_only_counts_and_queue_exhaustion_preserves_unknown(): void
    {
        config(['account.mail.mailer' => 'array']);
        Mail::fake();
        $service = app(AccountMailDelivery::class);
        $service->send('monitor:1', 'private@example.test', 'array', new AccountActionMail('private-subject', 'private-body'));
        DB::table('account_mail_deliveries')->update(['status' => 'delivery_unknown']);
        $service->markExhausted('monitor:1');
        $this->assertDatabaseHas('account_mail_deliveries', ['status' => 'delivery_unknown']);
        $this->artisan('account-mail:status')->expectsOutput('delivery_unknown_count=1')->expectsOutput('ACCOUNT_MAIL_STATUS=PASS')->assertSuccessful();
    }

    public function test_explicit_429_rejection_can_retry_but_permanent_401_cannot_resend(): void
    {
        config(['account.mail.mailer' => 'postmark', 'mail.default' => 'postmark',
            'services.postmark.key' => 'fixture-token', 'mail.mailers.postmark.message_stream_id' => 'account-lifecycle',
            'mail.from.address' => 'sender@example.test', 'mail.from.name' => 'Company OS']);
        $responses = [
            new MockResponse('{"ErrorCode":429,"Message":"rate limited"}', ['http_code' => 429]),
            new MockResponse('{"MessageID":"retry-provider-id","ErrorCode":0}'),
        ];
        Mail::mailer('postmark')->setSymfonyTransport((new PostmarkApiTransport('fixture-token', new MockHttpClient($responses)))->setMessageStream('account-lifecycle'));
        try {
            app(AccountMailDelivery::class)->send('retry:1', 'recipient@example.test', 'postmark', new AccountActionMail('subject', 'intro'));
            $this->fail('429 must stop this attempt.');
        } catch (\RuntimeException $e) {
            $this->assertSame('ACCOUNT_MAIL_QUEUED', $e->getMessage());
        }
        $this->assertTrue(app(AccountMailDelivery::class)->send('retry:1', 'recipient@example.test', 'postmark', new AccountActionMail('subject', 'intro')));
        $this->assertDatabaseHas('account_mail_deliveries', ['status' => 'accepted', 'attempts' => 2, 'provider_message_id' => 'retry-provider-id']);

        Mail::mailer('postmark')->setSymfonyTransport(new PostmarkApiTransport('fixture-token', new MockHttpClient(new MockResponse('{"ErrorCode":10,"Message":"unauthorized"}', ['http_code' => 401]))));
        for ($i = 0; $i < 2; $i++) {
            try {
                app(AccountMailDelivery::class)->send('permanent:1', 'recipient@example.test', 'postmark', new AccountActionMail('subject', 'intro'));
                $this->fail('Permanent rejection must stop.');
            } catch (\RuntimeException $e) {
                $this->assertContains($e->getMessage(), ['ACCOUNT_MAIL_FAILED', 'ACCOUNT_MAIL_DELIVERY_UNKNOWN']);
            }
        }
        $this->assertDatabaseHas('account_mail_deliveries', ['status' => 'failed', 'attempts' => 1]);
    }

    public function test_webhook_reconciles_unknown_send_via_metadata_and_rejects_wrong_server(): void
    {
        $this->configureWebhook();
        $publicId = (string) Str::uuid();
        DB::table('account_mail_deliveries')->insert([
            'public_id' => $publicId, 'dedupe_key' => str_repeat('d', 64),
            'payload_hash' => str_repeat('e', 64), 'recipient_hash' => str_repeat('f', 64),
            'provider' => 'postmark', 'status' => 'delivery_unknown',
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'PHP_AUTH_USER' => 'fixture', 'PHP_AUTH_PW' => 'fixture-password']);
        $event = ['RecordType' => 'Delivery', 'ServerID' => 99, 'MessageID' => 'reconciled-id',
            'MessageStream' => 'account-lifecycle', 'Metadata' => ['delivery_id' => $publicId], 'DeliveredAt' => '2026-10-07T01:00:00Z'];
        $this->postJson('https://localhost/api/webhooks/postmark/account-mail', $event)->assertUnprocessable();
        $event['ServerID'] = 23;
        $this->postJson('https://localhost/api/webhooks/postmark/account-mail', $event)->assertOk();
        $this->assertDatabaseHas('account_mail_deliveries', ['status' => 'delivered', 'provider_message_id' => 'reconciled-id']);
        $this->assertDatabaseCount('account_mail_provider_events', 1);
    }
}
