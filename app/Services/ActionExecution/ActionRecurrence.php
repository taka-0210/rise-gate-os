<?php

namespace App\Services\ActionExecution;

use App\Models\ActionScheduleRevision;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ActionRecurrence
{
    /** @return array<int, array{date: CarbonImmutable, start_utc: CarbonImmutable, end_utc: CarbonImmutable}> */
    public function occurrences(ActionScheduleRevision $revision, CarbonImmutable $from, CarbonImmutable $through, int $limit = 500): array
    {
        $timezone = $revision->timezone ?: config('app.timezone');
        $cursor = CarbonImmutable::parse($revision->starts_on, $timezone)->startOfDay();
        $from = $from->setTimezone($timezone)->startOfDay()->max($cursor);
        $through = $through->setTimezone($timezone)->startOfDay();
        if ($revision->ends_on) $through = $through->min(CarbonImmutable::parse($revision->ends_on, $timezone));
        $results = [];
        $guard = 0;
        while ($cursor->lte($through) && count($results) < $limit && $guard++ < 4000) {
            if ($cursor->gte($from) && $this->matches($revision, $cursor)) {
                $results[] = $this->window($revision, $cursor);
                if ($revision->count_limit && count($results) >= $revision->count_limit) break;
            }
            $cursor = $cursor->addDay();
        }
        return $results;
    }

    private function matches(ActionScheduleRevision $revision, CarbonImmutable $date): bool
    {
        $start = CarbonImmutable::parse($revision->starts_on, $revision->timezone)->startOfDay();
        $interval = max(1, (int) $revision->interval);
        return match ($revision->frequency) {
            'daily' => $start->diffInDays($date) % $interval === 0,
            'weekday' => $date->isWeekday(),
            'weekly' => $start->diffInWeeks($date->startOfWeek()) % $interval === 0
                && in_array($date->dayOfWeekIso, $revision->weekdays ?: [$start->dayOfWeekIso], true),
            'monthly' => $start->diffInMonths($date->startOfMonth()) % $interval === 0
                && ($revision->month_end ? $date->isLastOfMonth() : $date->day === (int) $revision->month_day),
            'custom' => $this->customMatches($revision, $start, $date, $interval),
            default => throw ValidationException::withMessages(['frequency' => 'Unsupported recurrence frequency.']),
        };
    }

    private function customMatches(ActionScheduleRevision $revision, CarbonImmutable $start, CarbonImmutable $date, int $interval): bool
    {
        if ($revision->weekdays) {
            return $start->diffInWeeks($date->startOfWeek()) % $interval === 0
                && in_array($date->dayOfWeekIso, $revision->weekdays, true);
        }
        if ($revision->month_day || $revision->month_end) {
            return $start->diffInMonths($date->startOfMonth()) % $interval === 0
                && ($revision->month_end ? $date->isLastOfMonth() : $date->day === (int) $revision->month_day);
        }
        return $start->diffInDays($date) % $interval === 0;
    }

    private function window(ActionScheduleRevision $revision, CarbonImmutable $date): array
    {
        $start = match ($revision->execution_rule) {
            'by_date' => $revision->frequency === 'monthly' ? $date->startOfMonth() : ($revision->frequency === 'weekly' ? $date->startOfWeek() : $date),
            'within_window' => $date->subDays((int) $revision->window_days_before),
            default => $date,
        };
        return ['date' => $date, 'start_utc' => $start->startOfDay()->utc(), 'end_utc' => $date->addDay()->startOfDay()->utc()];
    }
}
