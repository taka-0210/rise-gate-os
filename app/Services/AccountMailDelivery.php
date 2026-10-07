<?php

namespace App\Services;

use App\Mail\AccountActionMail;
use App\Services\Mail\TransportFailureDisposition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Throwable;

/** Account-only ledger; provider-specific parsing lives in the webhook adapter. */
class AccountMailDelivery
{
    public function send(string $identity, string $recipient, string $mailer, AccountActionMail $mail): bool
    {
        $transport = (string) config("mail.mailers.{$mailer}.transport");
        app(AccountMailDispatcher::class)->assertConfigured();
        if ($mailer !== (string) config('account.mail.mailer')) {
            throw new RuntimeException('ACCOUNT_MAIL_BINDING_CHANGED');
        }
        $key = $this->digest($identity);
        $payload = $this->digest(serialize([$recipient, $mail->mailSubject, $mail->intro, $mail->actionUrl, $mail->actionLabel]));
        // Render/configure before claiming a send: these failures are safe to retry.
        try {
            $mail->render();
            $resolved = Mail::mailer($mailer);
        } catch (Throwable) {
            throw new RuntimeException('ACCOUNT_MAIL_PREPARATION_FAILED');
        }
        $id = DB::transaction(function () use ($key, $payload, $recipient, $transport): ?int {
            DB::table('account_mail_deliveries')->insertOrIgnore([
                'public_id' => (string) Str::uuid(), 'dedupe_key' => $key,
                'payload_hash' => $payload, 'recipient_hash' => $this->digest(strtolower(trim($recipient))),
                'provider' => $transport, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('account_mail_deliveries')->where('dedupe_key', $key)->lockForUpdate()->first();
            if (! hash_equals($row->payload_hash, $payload)) {
                throw new RuntimeException('ACCOUNT_MAIL_DEDUPE_PAYLOAD_MISMATCH');
            }
            if ($row->status === 'accepted' || in_array($row->status, ['delivered', 'bounced', 'complained'], true)) {
                return null;
            }
            if ($row->status !== 'queued') {
                throw new RuntimeException('ACCOUNT_MAIL_DELIVERY_UNKNOWN');
            }
            if ($row->attempts >= 3) {
                throw new RuntimeException('ACCOUNT_MAIL_RETRY_EXHAUSTED');
            }
            DB::table('account_mail_deliveries')->where('id', $row->id)->update([
                'status' => 'sending', 'attempts' => $row->attempts + 1, 'updated_at' => now(),
            ]);

            return $row->id;
        });
        if ($id === null) {
            return false;
        }
        $publicId = DB::table('account_mail_deliveries')->where('id', $id)->value('public_id');
        $mail->withSymfonyMessage(function ($message) use ($publicId): void {
            $message->getHeaders()->add(new MetadataHeader('delivery_id', $publicId));
        });
        try {
            $sent = $resolved->to($recipient)->send($mail);
        } catch (Throwable $exception) {
            $status = app(TransportFailureDisposition::class)->classify($transport, $exception);
            DB::table('account_mail_deliveries')->where('id', $id)->where('status', 'sending')->update([
                'status' => $status, 'updated_at' => now(),
            ]);
            // Never retain provider exception text (may include credential or body).
            throw new RuntimeException('ACCOUNT_MAIL_'.strtoupper($status));
        }
        // A fake mailer returns null in tests; a live transport must return acceptance.
        if ($sent === null && ! app()->runningUnitTests()) {
            DB::table('account_mail_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'delivery_unknown', 'updated_at' => now()]);
            throw new RuntimeException('ACCOUNT_MAIL_DELIVERY_UNKNOWN');
        }
        DB::table('account_mail_deliveries')->where('id', $id)->where('status', 'sending')->update([
            'status' => 'accepted', 'provider_message_id' => $sent?->getMessageId(),
            'accepted_at' => now(), 'updated_at' => now(),
        ]);

        return true;
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', 'account-mail:v1:'.$value, (string) config('app.key'));
    }

    public function markExhausted(string $identity): void
    {
        DB::table('account_mail_deliveries')->where('dedupe_key', $this->digest($identity))
            ->where('status', 'queued')->update(['status' => 'failed', 'updated_at' => now()]);
        DB::table('account_mail_deliveries')->where('dedupe_key', $this->digest($identity))
            ->where('status', 'sending')->update(['status' => 'delivery_unknown', 'updated_at' => now()]);
    }
}
