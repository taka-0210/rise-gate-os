<?php

namespace App\Jobs;

use App\Mail\AccountActionMail;
use App\Models\User;
use App\Services\AccountAudit;
use App\Services\AccountMailDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendAccountActionMail implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    private string $deliveryIdentity = '';

    private string $sourceGeneration = '';

    public function __construct(
        private readonly ?int $userId,
        private readonly string $recipient,
        private readonly string $event,
        private readonly string $subject,
        private readonly string $intro,
        private readonly ?string $actionUrl = null,
        private readonly ?string $actionLabel = null,
        private readonly ?string $mailer = null,
        string $sourceGeneration = '',
    ) {
        $this->sourceGeneration = $sourceGeneration;
        $this->deliveryIdentity = hash_hmac('sha256', serialize([
            $userId, $recipient, $event, $subject, $intro, $actionUrl, $actionUrl === null ? $this->sourceGeneration : '',
        ]), (string) config('app.key'));
    }

    public function handle(AccountAudit $audit): void
    {
        // Older encrypted queued jobs do not contain the newly added field.
        if ($this->deliveryIdentity === '') {
            $this->deliveryIdentity = hash_hmac('sha256', serialize([
                $this->userId, $this->recipient, $this->event, $this->subject, $this->intro, $this->actionUrl, $this->actionUrl === null ? $this->sourceGeneration : '',
            ]), (string) config('app.key'));
        }
        $sent = app(AccountMailDelivery::class)->send(
            'account:'.$this->deliveryIdentity,
            $this->recipient,
            $this->mailer ?? (string) config('account.mail.mailer'),
            new AccountActionMail(
                $this->subject,
                $this->intro,
                $this->actionUrl,
                $this->actionLabel,
            ));

        if (! $sent) {
            return;
        }

        $user = $this->userId ? User::query()->find($this->userId) : null;
        $audit->record($this->event, 'sent', $user, $user);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->deliveryIdentity !== '') {
            app(AccountMailDelivery::class)->markExhausted('account:'.$this->deliveryIdentity);
        }
        $user = $this->userId ? User::query()->find($this->userId) : null;
        app(AccountAudit::class)->record($this->event, 'failed', $user, $user);
    }
}
