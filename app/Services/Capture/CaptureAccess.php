<?php

namespace App\Services\Capture;

use App\Models\Capture;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;

class CaptureAccess
{
    public function canParticipate(User $user, Organization $org): bool
    {
        if (! $user->is_active) {
            return false;
        }if (! OrganizationUser::query()->where('organization_id', $org->id)->where('user_id', $user->id)->where('membership_status', OrganizationUser::STATUS_ACTIVE)->exists()) {
            return false;
        }$e = $user->productAccountEligibility;

        return $e && ($e->mode !== ProductAccountEligibility::MODE_SINGLE || $e->product_organization_id === $org->id);
    }

    public function canRead(User $user, Capture $capture): bool
    {
        if (! in_array($user->id, [$capture->creator_user_id, $capture->recipient_user_id], true) || ! $this->canParticipate($user, $capture->organization)) {
            return false;
        }$m = OrganizationUser::query()->where('organization_id', $capture->organization_id)->where('user_id', $user->id)->where('membership_status', OrganizationUser::STATUS_ACTIVE)->first();
        $epoch = $user->id === $capture->creator_user_id ? $capture->creator_access_epoch : $capture->recipient_access_epoch;
        $generation = $user->id === $capture->creator_user_id ? $capture->creator_credential_generation : $capture->recipient_credential_generation;

        return $m && (int) $m->access_epoch === (int) $epoch && (int) $user->credential_generation === (int) $generation;
    }
}
