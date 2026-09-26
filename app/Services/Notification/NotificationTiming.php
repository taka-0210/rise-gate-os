<?php

namespace App\Services\Notification;

use App\Models\OrganizationNotificationPolicy;
use App\Models\OrganizationNotificationCalendarDate;
use App\Models\UserNotificationPreference;
use Carbon\CarbonImmutable;

class NotificationTiming
{
    public function eligibleAt(int $organizationId, string $timing = 'now', ?string $specifiedAt = null, ?int $userId = null): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        if ($timing === 'specified' && $specifiedAt) return CarbonImmutable::parse($specifiedAt, config('app.timezone'))->utc()->max($now);
        $policy = OrganizationNotificationPolicy::query()->where('organization_id', $organizationId)->first();
        if (! $policy?->is_confirmed) return $now;
        $local = $now->setTimezone($policy->timezone ?: 'Asia/Tokyo');
        $preference=$userId?UserNotificationPreference::query()->where('organization_id',$organizationId)->where('user_id',$userId)->first():null;
        if ($timing === 'next_window' || $this->inQuietHours($local, $policy->quiet_starts_at, $policy->quiet_ends_at)
            ||$this->inQuietHours($local,$preference?->quiet_starts_at,$preference?->quiet_ends_at)) {
            return $this->nextWindow($local, $policy, $preference)->utc();
        }
        return $now;
    }

    private function inQuietHours(CarbonImmutable $now, ?string $start, ?string $end): bool
    {
        if (! $start || ! $end) return false;
        $time = $now->format('H:i:s');
        return $start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
    }

    private function nextWindow(CarbonImmutable $from, OrganizationNotificationPolicy $policy, ?UserNotificationPreference $preference): CarbonImmutable
    {
        $windows = $policy->weekday_windows ?: [];
        for ($offset = 0; $offset <= 14; $offset++) {
            $day = $from->addDays($offset);
            $exception=OrganizationNotificationCalendarDate::query()->where('organization_id',$policy->organization_id)->whereDate('calendar_date',$day->toDateString())->value('kind');
            if($exception===OrganizationNotificationCalendarDate::HOLIDAY)continue;
            $window = $windows[(string) $day->isoWeekday()] ?? null;
            if($exception===OrganizationNotificationCalendarDate::WORKING_EXCEPTION&&!is_array($window))$window=['enabled'=>true,'start'=>'09:00','end'=>'18:00'];
            if (! is_array($window) || (empty($window['enabled'])&&$exception!==OrganizationNotificationCalendarDate::WORKING_EXCEPTION) || empty($window['start'])) continue;
            $candidate = $day->setTimeFromTimeString($window['start']);
            if($this->inQuietHours($candidate,$preference?->quiet_starts_at,$preference?->quiet_ends_at)&&$preference?->quiet_ends_at){
                $candidate=$day->setTimeFromTimeString($preference->quiet_ends_at);
            }
            $windowEnd=!empty($window['end'])?$day->setTimeFromTimeString($window['end']):$day->endOfDay();
            if ($candidate->greaterThan($from)&&$candidate->lessThan($windowEnd)) return $candidate;
        }
        return $from->addDay()->startOfDay();
    }
}
