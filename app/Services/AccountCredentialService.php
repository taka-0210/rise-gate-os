<?php

namespace App\Services;

use App\Models\AccountEmailRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountCredentialService
{
    public function __construct(private readonly AccountAudit $audit) {}

    public function rotate(
        User $user,
        string $event,
        ?User $actor = null,
        array $previousEmails = [],
        array $exceptEmailRequestIds = [],
    ): void {
        $user->credential_generation = ((int) $user->credential_generation) + 1;
        $user->setRememberToken(Str::random(60));
        $user->save();

        $emails = array_values(array_unique(array_filter([...$previousEmails, $user->email])));
        DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();

        $pending = AccountEmailRequest::query()
            ->where('user_id', $user->id)
            ->where('status', AccountEmailRequest::STATUS_PENDING);
        if ($exceptEmailRequestIds !== []) {
            $pending->whereNotIn('id', $exceptEmailRequestIds);
        }
        $pending->update([
            'status' => AccountEmailRequest::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

        $this->audit->record($event, 'success', $user, $actor ?? $user);
    }
}
