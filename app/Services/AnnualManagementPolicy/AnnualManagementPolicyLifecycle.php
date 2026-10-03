<?php

namespace App\Services\AnnualManagementPolicy;

use App\Models\OrganizationManagementPeriod;
use Carbon\CarbonImmutable;

class AnnualManagementPolicyLifecycle
{
    public const TIMEZONE = 'Asia/Tokyo';

    public function evaluate(
        OrganizationManagementPeriod $period,
        bool $approved,
        CarbonImmutable|string|null $evaluatedOn = null,
    ): array {
        $day = $evaluatedOn instanceof CarbonImmutable
            ? $evaluatedOn->setTimezone(self::TIMEZONE)->startOfDay()
            : CarbonImmutable::parse($evaluatedOn ?: 'now', self::TIMEZONE)->startOfDay();
        $start = CarbonImmutable::parse($period->starts_on->toDateString(), self::TIMEZONE)->startOfDay();
        $end = CarbonImmutable::parse($period->ends_on->toDateString(), self::TIMEZONE)->startOfDay();

        $effective = $day->lt($start)
            ? 'upcoming'
            : ($day->gt($end) ? 'ended' : 'effective');

        return [
            'approval_status' => $approved ? 'approved' : 'draft',
            'effective_status' => $effective,
            'evaluated_on' => $day->toDateString(),
            'timezone' => self::TIMEZONE,
        ];
    }

    public function label(array $lifecycle): string
    {
        if (($lifecycle['approval_status'] ?? null) !== 'approved') {
            return '作成中 / 未承認';
        }

        return match ($lifecycle['effective_status'] ?? null) {
            'upcoming' => '承認済み / 開始前',
            'effective' => '承認済み / 現在有効',
            'ended' => '承認済み / 終了',
            default => '承認済み',
        };
    }
}
