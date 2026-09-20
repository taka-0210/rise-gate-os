<?php

namespace App\Jobs;

use App\Mail\AccountActionMail;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Services\Organization\OrganizationInvitationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOrganizationInvitationMail implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $invitationId,
        private readonly int $generation,
        private readonly string $token,
        private readonly string $mailer,
    ) {}

    public function handle(OrganizationInvitationMailer $mailer): void
    {
        $invitation = OrganizationInvitation::query()->with(['organization', 'sponsor'])->find($this->invitationId);
        if (! $this->isCurrentAndAuthorized($invitation)) {
            return;
        }

        Mail::mailer($this->mailer)->to($invitation->normalized_email)->send(new AccountActionMail(
            'Company OSへの招待',
            $invitation->organization->name.' のCompany OSへ招待されました。心当たりがある場合のみ期限内に手続きを進めてください。',
            $mailer->trustedUrl($invitation, $this->token),
            '招待を確認する',
        ));

        OrganizationInvitation::query()
            ->whereKey($invitation->id)
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->where('token_generation', $this->generation)
            ->update([
                'delivery_status' => OrganizationInvitation::DELIVERY_SENT,
                'delivered_at' => now(),
                'delivery_failed_at' => null,
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        OrganizationInvitation::query()
            ->whereKey($this->invitationId)
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->where('token_generation', $this->generation)
            ->update([
                'delivery_status' => OrganizationInvitation::DELIVERY_FAILED,
                'delivery_failed_at' => now(),
            ]);
    }

    private function isCurrentAndAuthorized(?OrganizationInvitation $invitation): bool
    {
        if (! $invitation || ! $invitation->isPending() || $invitation->isExpired()
            || $invitation->token_generation !== $this->generation
            || ! hash_equals($invitation->token_hash, hash('sha256', $this->token))
            || ! $invitation->sponsor?->is_active) {
            return false;
        }

        $membership = OrganizationUser::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('user_id', $invitation->sponsor_user_id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->first();
        if (! $membership) {
            return false;
        }

        return $invitation->intended_organization_role === OrganizationUser::ORGANIZATION_ROLE_MEMBER
            ? in_array($membership->organization_role, [OrganizationUser::ORGANIZATION_ROLE_OWNER, OrganizationUser::ORGANIZATION_ROLE_ADMIN], true)
            : $membership->organization_role === OrganizationUser::ORGANIZATION_ROLE_OWNER;
    }
}
