<?php

namespace App\Services\Notification;

use App\Models\CompanyNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationVisibility
{
    public function __construct(
        private readonly NotificationAuthorization $authorization,
        private readonly NotificationTiming $timing,
    ) {}

    public function isVisible(CompanyNotification $notification): bool
    {
        $now = CarbonImmutable::now('UTC');

        return $notification->cancelled_at_utc === null
            && $notification->content_visible_at_utc->lessThanOrEqualTo($now)
            && $notification->eligible_at_utc->lessThanOrEqualTo($now)
            && $this->timing->isAllowedNow($notification->organization_id, $notification->recipient_user_id)
            && $this->authorization->allowed($notification->loadMissing('recipient'));
    }

    public function paginate(int $organizationId, int $userId, int $perPage = 30): LengthAwarePaginatorContract
    {
        $page = LengthAwarePaginator::resolveCurrentPage();
        $visible = CompanyNotification::query()
            ->where('organization_id', $organizationId)
            ->where('recipient_user_id', $userId)
            ->whereNull('cancelled_at_utc')
            ->where('content_visible_at_utc', '<=', now('UTC'))
            ->latest('id')
            ->get()
            ->filter(fn (CompanyNotification $notification) => $this->isVisible($notification))
            ->values();

        return new LengthAwarePaginator(
            $visible->forPage($page, $perPage)->values(),
            $visible->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()],
        );
    }

    public function unreadCount(int $organizationId, int $userId): int
    {
        return CompanyNotification::query()
            ->where('organization_id', $organizationId)
            ->where('recipient_user_id', $userId)
            ->whereNull('read_at_utc')
            ->whereNull('cancelled_at_utc')
            ->where('content_visible_at_utc', '<=', now('UTC'))
            ->get()
            ->filter(fn (CompanyNotification $notification) => $this->isVisible($notification))
            ->count();
    }
}
