<?php

namespace App\Jobs;

use App\Mail\AccountActionMail;
use App\Models\User;
use App\Services\AccountAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAccountActionMail implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly ?int $userId,
        private readonly string $recipient,
        private readonly string $event,
        private readonly string $subject,
        private readonly string $intro,
        private readonly ?string $actionUrl = null,
        private readonly ?string $actionLabel = null,
        private readonly ?string $mailer = null,
    ) {}

    public function handle(AccountAudit $audit): void
    {
        Mail::mailer($this->mailer)
            ->to($this->recipient)
            ->send(new AccountActionMail(
                $this->subject,
                $this->intro,
                $this->actionUrl,
                $this->actionLabel,
            ));

        $user = $this->userId ? User::query()->find($this->userId) : null;
        $audit->record($this->event, 'sent', $user, $user);
    }

    public function failed(?Throwable $exception): void
    {
        $user = $this->userId ? User::query()->find($this->userId) : null;
        app(AccountAudit::class)->record($this->event, 'failed', $user, $user);
    }
}
