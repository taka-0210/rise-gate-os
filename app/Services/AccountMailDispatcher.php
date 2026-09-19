<?php

namespace App\Services;

use App\Jobs\SendAccountActionMail;
use App\Models\AccountEmailRequest;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class AccountMailDispatcher
{
    public function sendPasswordReset(User $user, string $token): void
    {
        $relative = route('password.reset', ['token' => $token], false)
            .'?email='.rawurlencode($user->email);

        $this->dispatch(
            $user,
            $user->email,
            'account.password_reset.mail',
            'Company OS パスワード再設定',
            'パスワード再設定の依頼を受け付けました。心当たりがある場合だけ、期限内に操作してください。',
            $this->trustedUrl($relative),
            'パスワードを再設定',
        );
    }

    public function sendEmailRequest(User $user, AccountEmailRequest $request, string $token): void
    {
        $relative = URL::temporarySignedRoute(
            'account.email.confirm',
            $request->expires_at,
            ['requestId' => $request->public_id, 'token' => $token],
            false,
        );
        $isChange = $request->purpose === AccountEmailRequest::PURPOSE_CHANGE_EMAIL;

        $this->dispatch(
            $user,
            $isChange ? (string) $request->pending_email : $user->email,
            $isChange ? 'account.email_change.mail' : 'account.email_verification.mail',
            $isChange ? 'Company OS メールアドレス変更確認' : 'Company OS メールアドレス確認',
            $isChange
                ? 'このメールアドレスへの変更依頼を受け付けました。心当たりがある場合だけ確認してください。'
                : '現在のメールアドレスを確認します。心当たりがある場合だけ確認してください。',
            $this->trustedUrl($relative),
            'メールアドレスを確認',
        );
    }

    public function sendEmailChangedNotice(User $user, string $oldEmail): void
    {
        $this->dispatch(
            $user,
            $oldEmail,
            'account.email_changed.notice',
            'Company OS メールアドレス変更のお知らせ',
            'Company OSのメールアドレスが変更されました。心当たりがない場合は管理者へ連絡してください。',
        );
    }

    public function assertConfigured(): void
    {
        $mailer = (string) config('account.mail.mailer');
        $transport = config("mail.mailers.{$mailer}.transport");

        if (
            $mailer === ''
            || $transport === null
            || $transport === 'log'
            || (app()->environment('production') && $transport === 'array')
        ) {
            throw new RuntimeException('Account mailer must use a non-log configured transport.');
        }
    }

    private function dispatch(
        User $user,
        string $recipient,
        string $event,
        string $subject,
        string $intro,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
    ): void {
        $this->assertConfigured();

        SendAccountActionMail::dispatch(
            $user->id,
            $recipient,
            $event,
            $subject,
            $intro,
            $actionUrl,
            $actionLabel,
            (string) config('account.mail.mailer'),
        );
    }

    private function trustedUrl(string $relative): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $parts = parse_url($base);
        if (! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('APP_URL must be a trusted absolute HTTP(S) URL.');
        }

        return $base.'/'.ltrim($relative, '/');
    }
}
