<?php

namespace Tests\Feature;

use App\Services\AiCommon\AiCommonPeriodMetadata;
use Tests\TestCase;

class CoPeriodMetadataTest extends TestCase
{
    private function data(): array
    {
        return [
            ['organization_public_id' => 'org', 'period' => [
                'public_id' => 'period', 'organization_name' => '2026', 'declared_name' => '2026',
                'organization_starts_on' => '2026-12-01', 'declared_starts_on' => '2026-12-01',
                'organization_ends_on' => '2027-11-30', 'declared_ends_on' => '2027-11-30',
            ]],
            ['organization_public_id' => 'org', 'public_id' => 'period', 'name' => '2026',
                'starts_on' => '2026-12-01', 'ends_on' => '2027-11-30', 'fiscal_term_number' => 23, 'version' => 4],
        ];
    }

    public function test_matching_metadata_is_separate_and_does_not_modify_snapshot(): void
    {
        [$official, $current] = $this->data();
        $before = $official;
        $result = AiCommonPeriodMetadata::supplement($official, $current);
        $this->assertSame(23, $result['fiscal_term_number']);
        $this->assertSame(4, $result['period_version']);
        $this->assertFalse($result['is_part_of_approved_snapshot']);
        $this->assertSame('organization_management_periods', $result['source']);
        $this->assertSame($before, $official);
    }

    public function test_mismatch_missing_and_invalid_metadata_are_omitted(): void
    {
        [$official, $current] = $this->data();
        foreach (['organization_public_id', 'public_id', 'name', 'starts_on', 'ends_on'] as $key) {
            $bad = $current;
            $bad[$key] = 'mismatch';
            $this->assertNull(AiCommonPeriodMetadata::supplement($official, $bad));
            unset($bad[$key]);
            $this->assertNull(AiCommonPeriodMetadata::supplement($official, $bad));
        }
        foreach ([null, 0, -1, '23'] as $term) {
            $this->assertNull(AiCommonPeriodMetadata::supplement($official, array_replace($current, ['fiscal_term_number' => $term])));
        }
        $official['period']['declared_starts_on'] = '2026-12-02';
        $this->assertNull(AiCommonPeriodMetadata::supplement($official, $current));
    }

    public function test_snapshot_term_is_never_overridden_by_current_period(): void
    {
        [$official, $current] = $this->data();
        $official['period']['organization_fiscal_term_number'] = 22;
        $this->assertNull(AiCommonPeriodMetadata::supplement($official, $current));
    }
}
