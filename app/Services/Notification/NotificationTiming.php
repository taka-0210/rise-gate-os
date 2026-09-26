<?php

namespace App\Services\Notification;

use App\Models\OrganizationNotificationCalendarDate;
use App\Models\OrganizationNotificationPolicy;
use App\Models\UserNotificationPreference;
use Carbon\CarbonImmutable;

class NotificationTiming
{
    public function eligibleAt(
        int $organizationId,
        string $timing = 'now',
        ?string $specifiedAt = null,
        ?int $userId = null,
    ): ?CarbonImmutable {
        $now = CarbonImmutable::now('UTC');
        $earliest = $timing === 'specified' && $specifiedAt
            ? CarbonImmutable::parse($specifiedAt, config('app.timezone'))->utc()->max($now)
            : $now;

        return $this->nextEligibleAt($organizationId, $earliest, $userId);
    }

    public function nextEligibleAt(int $organizationId, CarbonImmutable $earliestUtc, ?int $userId = null): ?CarbonImmutable
    {
        $policy = OrganizationNotificationPolicy::query()
            ->where('organization_id', $organizationId)
            ->first();

        if (! $policy?->is_confirmed) {
            return null;
        }

        $timezone = $policy->timezone ?: 'Asia/Tokyo';
        $earliest = $earliestUtc->setTimezone($timezone);
        $preference = $userId
            ? UserNotificationPreference::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->first()
            : null;
        $windows = $policy->weekday_windows ?: [];

        for ($offset = 0; $offset <= 14; $offset++) {
            $day = $earliest->startOfDay()->addDays($offset);
            $exception = OrganizationNotificationCalendarDate::query()
                ->where('organization_id', $organizationId)
                ->whereDate('calendar_date', $day->toDateString())
                ->value('kind');

            if ($exception === OrganizationNotificationCalendarDate::HOLIDAY) {
                continue;
            }

            $window = $windows[(string) $day->isoWeekday()] ?? null;
            if ($exception === OrganizationNotificationCalendarDate::WORKING_EXCEPTION && ! is_array($window)) {
                $window = ['enabled' => true, 'start' => '09:00', 'end' => '18:00'];
            }
            if (! is_array($window)
                || (empty($window['enabled']) && $exception !== OrganizationNotificationCalendarDate::WORKING_EXCEPTION)
                || empty($window['start'])
                || empty($window['end'])) {
                continue;
            }

            $start = $day->setTimeFromTimeString($window['start']);
            $end = $day->setTimeFromTimeString($window['end']);
            if ($end->lessThanOrEqualTo($start)) {
                continue;
            }

            $candidate = $offset === 0 && $earliest->greaterThan($start) ? $earliest : $start;
            while ($candidate->lessThan($end)) {
                if (! $this->inQuietHours($candidate, $policy->quiet_starts_at, $policy->quiet_ends_at)
                    && ! $this->inQuietHours($candidate, $preference?->quiet_starts_at, $preference?->quiet_ends_at)) {
                    return $candidate->utc();
                }
                $candidate = $candidate->addMinute()->startOfMinute();
            }
        }

        return null;
    }

    public function isAllowedNow(int $organizationId, ?int $userId = null): bool
    {
        $now = CarbonImmutable::now('UTC');
        $next = $this->nextEligibleAt($organizationId, $now, $userId);

        return $next !== null && $next->equalTo($now);
    }

    private function inQuietHours(CarbonImmutable $at, ?string $start, ?string $end): bool
    {
        if (! $start || ! $end) {
            return false;
        }

        $time = $at->format('H:i:s');

        return $start < $end
            ? $time >= $start && $time < $end
            : $time >= $start || $time < $end;
    }
}
