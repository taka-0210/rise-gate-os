<?php

namespace App\Services;

use App\Models\AccountEmailRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountEmailService
{
    public function __construct(
        private readonly AccountMailDispatcher $mail,
        private readonly AccountCredentialService $credentials,
        private readonly AccountAudit $audit,
    ) {}

    public function requestCurrentVerification(User $user): AccountEmailRequest
    {
        return $this->createRequest($user, AccountEmailRequest::PURPOSE_VERIFY_CURRENT);
    }

    public function requestEmailChange(User $user, string $candidate): AccountEmailRequest
    {
        $candidate = Str::lower(trim($candidate));
        if (Str::lower($user->email) === $candidate || $this->emailExists($candidate, $user->id)) {
            throw ValidationException::withMessages([
                'email' => 'このメールアドレスは使用できません。別のメールアドレスを入力してください。',
            ]);
        }

        return $this->createRequest($user, AccountEmailRequest::PURPOSE_CHANGE_EMAIL, $candidate);
    }

    public function resendEmailChange(User $user): AccountEmailRequest
    {
        $latest = AccountEmailRequest::query()
            ->where('user_id', $user->id)
            ->where('purpose', AccountEmailRequest::PURPOSE_CHANGE_EMAIL)
            ->where('status', AccountEmailRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        if (! $latest || ! $latest->pending_email) {
            throw ValidationException::withMessages(['email' => '再送できる変更要求がありません。']);
        }

        return $this->requestEmailChange($user, $latest->pending_email);
    }

    public function cancelEmailChange(User $user): void
    {
        AccountEmailRequest::query()
            ->where('user_id', $user->id)
            ->where('purpose', AccountEmailRequest::PURPOSE_CHANGE_EMAIL)
            ->where('status', AccountEmailRequest::STATUS_PENDING)
            ->update([
                'status' => AccountEmailRequest::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

        $this->audit->record('account.email_change.cancelled', 'success', $user, $user);
    }

    /** @return 'verified'|'changed'|'invalid' */
    public function confirm(User $actor, string $publicId, string $token): string
    {
        $oldEmail = null;
        try {
            $result = DB::transaction(function () use ($actor, $publicId, $token, &$oldEmail): string {
                $request = AccountEmailRequest::query()
                    ->where('public_id', $publicId)
                    ->lockForUpdate()
                    ->first();

                if (! $request || $request->user_id !== $actor->id) {
                    abort(403);
                }

                $user = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $latestExists = AccountEmailRequest::query()
                    ->where('user_id', $user->id)
                    ->where('purpose', $request->purpose)
                    ->where('id', '>', $request->id)
                    ->exists();

                $valid = $request->status === AccountEmailRequest::STATUS_PENDING
                    && ! $request->expires_at->isPast()
                    && ! $latestExists
                    && hash_equals($request->token_hash, hash('sha256', $token))
                    && (int) $request->credential_generation === (int) $user->credential_generation
                    && hash_equals(Str::lower($request->current_email), Str::lower($user->email))
                    && $user->is_active;

                if (! $valid) {
                    return 'invalid';
                }

                if ($request->purpose === AccountEmailRequest::PURPOSE_VERIFY_CURRENT) {
                    $user->email_verified_at = now();
                    $user->save();
                    $request->update(['status' => AccountEmailRequest::STATUS_CONSUMED, 'consumed_at' => now()]);
                    $this->audit->record('account.email_verified', 'success', $user, $user);

                    return 'verified';
                }

                $candidate = (string) $request->pending_email;
                if ($candidate === '' || $this->emailExists($candidate, $user->id)) {
                    return 'invalid';
                }

                $oldEmail = $user->email;
                $user->email = $candidate;
                $user->email_verified_at = now();
                $user->save();
                $request->update(['status' => AccountEmailRequest::STATUS_CONSUMED, 'consumed_at' => now()]);
                $this->credentials->rotate(
                    $user,
                    'account.email_changed',
                    $user,
                    [$oldEmail],
                    [$request->id],
                );

                return 'changed';
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $this->audit->record('account.email_changed', 'conflict', $actor, $actor);
            $result = 'invalid';
        }

        if ($result === 'changed' && $oldEmail !== null) {
            try {
                $this->mail->sendEmailChangedNotice($actor->fresh(), $oldEmail);
            } catch (\Throwable) {
                $this->audit->record('account.email_changed.notice', 'enqueue_failed', $actor->fresh(), $actor->fresh());
            }
        }

        return $result;
    }

    private function createRequest(User $user, string $purpose, ?string $pendingEmail = null): AccountEmailRequest
    {
        $this->mail->assertConfigured();
        $token = Str::random(64);

        $request = DB::transaction(function () use ($user, $purpose, $pendingEmail, $token): AccountEmailRequest {
            AccountEmailRequest::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->where('status', AccountEmailRequest::STATUS_PENDING)
                ->update([
                    'status' => AccountEmailRequest::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);

            return AccountEmailRequest::query()->create([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'purpose' => $purpose,
                'current_email' => $user->email,
                'pending_email' => $pendingEmail,
                'token_hash' => hash('sha256', $token),
                'credential_generation' => $user->credential_generation,
                'expires_at' => now()->addMinutes(config('account.token.email_expire_minutes')),
            ]);
        });

        try {
            $this->mail->sendEmailRequest($user, $request, $token);
            $this->audit->record('account.email_request.created', 'success', $user, $user, ['purpose' => $purpose]);
        } catch (\Throwable $exception) {
            $this->audit->record('account.email_request.created', 'enqueue_failed', $user, $user, ['purpose' => $purpose]);
            throw $exception;
        }

        return $request;
    }

    private function emailExists(string $email, int $exceptUserId): bool
    {
        return User::query()
            ->whereKeyNot($exceptUserId)
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->exists();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;

        return in_array($state, ['23000', '23505'], true)
            || str_contains(Str::lower($exception->getMessage()), 'unique constraint');
    }
}
