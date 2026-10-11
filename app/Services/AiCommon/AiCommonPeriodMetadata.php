<?php

namespace App\Services\AiCommon;

final class AiCommonPeriodMetadata
{
    public static function supplement(array $official, array $current): ?array
    {
        $period = $official['period'] ?? [];
        if (isset($period['organization_fiscal_term_number'])) {
            return null;
        }
        $matches = [
            [$official['organization_public_id'] ?? null, $current['organization_public_id'] ?? null],
            [$period['public_id'] ?? null, $current['public_id'] ?? null],
            [$period['organization_name'] ?? null, $current['name'] ?? null],
            [$period['declared_name'] ?? null, $current['name'] ?? null],
            [$period['organization_starts_on'] ?? null, $current['starts_on'] ?? null],
            [$period['declared_starts_on'] ?? null, $current['starts_on'] ?? null],
            [$period['organization_ends_on'] ?? null, $current['ends_on'] ?? null],
            [$period['declared_ends_on'] ?? null, $current['ends_on'] ?? null],
        ];
        foreach ($matches as [$approved, $now]) {
            if (! is_string($approved) || $approved === '' || $approved !== $now) {
                return null;
            }
        }
        if (! is_int($current['fiscal_term_number'] ?? null) || $current['fiscal_term_number'] < 1
            || ! is_int($current['version'] ?? null) || $current['version'] < 1) {
            return null;
        }

        return [
            'fiscal_term_number' => $current['fiscal_term_number'],
            'period_version' => $current['version'],
            'period_public_id' => $current['public_id'],
            'source' => 'organization_management_periods',
            'is_current_period_metadata' => true,
            'is_part_of_approved_snapshot' => false,
            'usage_note' => 'Supplemental current formal period metadata, not approved policy text or approval-time snapshot information. Do not cite it as approved policy content.',
        ];
    }
}
